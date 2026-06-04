<?php

namespace Tests\Feature\Jobs;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCanceled;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Jobs\CleanupStalePendingKioskOrders;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [TRAP-2 2026-06-04] Real abandoned-kiosk cleanup.
 *
 * The deep-review trap: a walk-away kiosk Plan-B cash order AUTO-ACCEPTS to
 * status=ACCEPT + payment_status=PENDING_COUNTER + pos_payment_method=
 * COUNTER_DEFERRED (FrontendOrderService:208,266-267,590-593). The previous
 * cleanup gate filtered status=PENDING ONLY → it matched ZERO real kiosk
 * orders and was DEAD CODE.
 *
 * This test uses the REAL fixture shape (status=ACCEPT) — NOT the artificial
 * status=PENDING that the older sentinel locked — and proves:
 *   1. an OLD uncollected ACCEPT/PENDING_COUNTER kiosk order IS cleaned
 *      (ACCEPT→CANCELED, a legal non-fiscal transition);
 *   2. its COUNTER_DEFERRED marker is broken so a late collect is refused
 *      (NF525-safe: a canceled row can never be fiscalized + paid);
 *   3. a COLLECTED + fiscalized kiosk order is NOT touched (NF525 invariant —
 *      a row with a fiscal_sequence_no is never mutated).
 */
class CleanupAbandonedKioskCounterOrderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Deterministic TTL for the fixtures below.
        config(['kiosk.stale_collect_ttl_minutes' => 180]);
    }

    public function test_old_abandoned_kiosk_counter_order_is_canceled_marker_broken(): void
    {
        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
            OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        // REAL walk-away kiosk cash order: auto-accepted, never collected, 4 h old.
        $abandoned = $this->makeAbandonedKioskCounterOrder(
            $branch->id,
            $customer->id,
            now()->subMinutes(240)
        );

        (new CleanupStalePendingKioskOrders())->handle();

        $fresh = $abandoned->fresh();

        $this->assertSame(
            OrderStatus::CANCELED,
            (int) $fresh->status,
            'Abandoned ACCEPT/PENDING_COUNTER kiosk order must be auto-canceled (ACCEPT→CANCELED legal, KDS-clearing).'
        );

        // Counter-deferred marker broken → a late collect can never fiscalize it.
        $this->assertNull(
            $fresh->pos_payment_method,
            'COUNTER_DEFERRED marker must be cleared so confirmCounterPayment refuses the canceled order (NF525-safe).'
        );

        // No fiscal sequence ever allocated → NF525 chain untouched.
        $this->assertNull(
            $fresh->fiscal_sequence_no,
            'Canceled abandoned order must carry NO fiscal sequence.'
        );

        Event::assertDispatchedTimes(OrderStatusChanged::class, 1);
        Event::assertDispatchedTimes(OrderCanceled::class, 1);
        Event::assertDispatched(OrderStatusChanged::class, function (OrderStatusChanged $event) use ($abandoned): bool {
            return (int) $event->order->id === (int) $abandoned->id
                && $event->oldStatus === OrderStatus::ACCEPT
                && $event->newStatus === OrderStatus::CANCELED;
        });
    }

    public function test_collected_fiscalized_kiosk_order_is_never_touched(): void
    {
        Event::fake([
            SendOrderMail::class,
            SendOrderSms::class,
            SendOrderPush::class,
            OrderStatusChanged::class,
            OrderCanceled::class,
        ]);

        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);

        // Same age, but already COLLECTED at the counter: payment_status=PAID and
        // a fiscal_sequence_no allocated (PaymentService seal). Must be immune.
        $collected = $this->makeAbandonedKioskCounterOrder(
            $branch->id,
            $customer->id,
            now()->subMinutes(240)
        );
        FrontendOrder::withoutGlobalScopes()
            ->whereKey($collected->id)
            ->update([
                'payment_status'     => PaymentStatus::PAID,
                'fiscal_sequence_no' => 1,
                'pos_payment_method' => PosPaymentMethod::CASH,
            ]);

        (new CleanupStalePendingKioskOrders())->handle();

        $fresh = $collected->fresh();

        $this->assertSame(
            OrderStatus::ACCEPT,
            (int) $fresh->status,
            'A collected (PAID) order must NEVER be auto-canceled.'
        );
        $this->assertSame(
            PaymentStatus::PAID,
            (int) $fresh->payment_status,
            'Collected order payment_status must remain PAID.'
        );
        $this->assertSame(
            1,
            (int) $fresh->fiscal_sequence_no,
            'Fiscalized order fiscal_sequence_no must be untouched (NF525 chain).'
        );

        Event::assertNotDispatched(OrderStatusChanged::class);
        Event::assertNotDispatched(OrderCanceled::class);
    }

    public function test_cancel_transition_is_legal_without_frozen_state_machine_edit(): void
    {
        // Guards that the chosen terminal action uses only LEGAL transitions —
        // ACCEPT→REJECTED is illegal, ACCEPT→CANCELED is legal.
        $this->assertFalse(
            OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::REJECTED),
            'ACCEPT→REJECTED must be illegal (so the job must NOT reject auto-accepted kiosk orders).'
        );
        $this->assertTrue(
            OrderStateMachine::allows(OrderStatus::ACCEPT, OrderStatus::CANCELED),
            'ACCEPT→CANCELED must be legal (the terminal action the job uses).'
        );
    }

    private function makeAbandonedKioskCounterOrder(int $branchId, int $userId, Carbon $createdAt): FrontendOrder
    {
        return FrontendOrder::withoutGlobalScopes()->create([
            'order_serial_no'    => 'KIOSK-TRAP2-' . fake()->unique()->numerify('######'),
            'user_id'            => $userId,
            'branch_id'          => $branchId,
            'subtotal'           => 10,
            'discount'           => 0,
            'delivery_charge'    => 0,
            'total_tax'          => 1,
            'total'              => 11,
            'order_type'         => OrderType::KIOSK,
            'order_datetime'     => $createdAt,
            'preparation_time'   => 15,
            'is_advance_order'   => 0,
            // Real Plan-B cash markers set by FrontendOrderService at creation.
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            // Auto-accepted (the trap): real abandoned orders sit at ACCEPT.
            'status'             => OrderStatus::ACCEPT,
            'source'             => 10,
            'source_surface'     => 'kiosk',
            'fiscal_sequence_no' => null,
            'created_at'         => $createdAt,
            'updated_at'         => $createdAt,
        ]);
    }
}
