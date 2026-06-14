<?php

namespace Tests\Feature\Fiscal;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use App\Services\Fiscal\FiscalSequenceService;
use App\Services\FrontendOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Tests\TestCase;

/**
 * [iter14 SPECIALIST-3 / FISCAL-ORPHAN-RETRY]
 *
 * Locks the audit iter13 ORDER-PATH P1 fix: when fiscal_sequence_no
 * allocation fails inside finalizePaidKioskOrder, the order MUST be
 * flagged with `fiscal_alloc_error_at` (not silently rolled back) so
 * the retry cron can recover it.
 */
class FiscalAllocOrphanRetryTest extends TestCase
{
    use RefreshDatabase;

    /**
     * When alloc throws, the order keeps PAID + PENDING (status not
     * promoted), gets a `fiscal_alloc_error_at` flag, and the caller
     * receives `false` instead of an exception bubbling up.
     */
    public function test_finalize_flags_order_when_alloc_fails_and_returns_false(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);
        config(['fiscal.kiosk_auto_allocate_sequence' => true]);

        [$branch, $kioskOrder] = $this->seedKioskScenario('FAIL-001', 'kiosk-fail');

        $this->app->bind(FiscalSequenceService::class, function () {
            return new class extends FiscalSequenceService {
                public function next(int $branchId): int
                {
                    throw new RuntimeException('fiscal alloc forced failure');
                }
            };
        });

        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder($kioskOrder);

        $this->assertFalse($promoted, 'finalize must return false when alloc fails (no exception bubbles).');

        $persisted = Order::withoutGlobalScopes()->findOrFail($kioskOrder->id);
        $this->assertSame(OrderStatus::PENDING, (int) $persisted->status, 'order must stay PENDING — KDS skips, retry cron picks up.');
        $this->assertSame(PaymentStatus::PAID, (int) $persisted->payment_status);
        $this->assertNull($persisted->fiscal_sequence_no, 'fiscal_sequence_no must remain NULL after failed alloc.');
        $this->assertNotNull($persisted->fiscal_alloc_error_at, 'flag must be set so retry cron picks the order up.');
    }

    /**
     * When the retry succeeds (e.g. cache backend recovered), the same
     * finalize path clears the error flag and promotes the order. This
     * is the cron's success path.
     */
    public function test_finalize_clears_error_flag_on_successful_retry(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);
        config(['fiscal.kiosk_auto_allocate_sequence' => true]);

        [$branch, $kioskOrder] = $this->seedKioskScenario('RETRY-001', 'kiosk-retry');

        // Pre-flag the order as if a previous alloc had failed.
        Order::withoutGlobalScopes()->where('id', $kioskOrder->id)->update([
            'fiscal_alloc_error_at' => now()->subMinute(),
        ]);

        // Real FiscalSequenceService now succeeds.
        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder($kioskOrder->fresh());

        $this->assertTrue($promoted);

        $persisted = Order::withoutGlobalScopes()->findOrFail($kioskOrder->id);
        // [Wave S-1 — 2026-05-20] Owner P-OWNER Wave S-1: kiosk paid TPE
        // auto-promotes to PREPARING after finalize. The retry path goes
        // through finalizePaidKioskOrder, so it inherits the new behaviour.
        $this->assertSame(OrderStatus::PREPARING, (int) $persisted->status);
        $this->assertNotNull($persisted->fiscal_sequence_no, 'retry must allocate fiscal_sequence_no.');
        $this->assertNull($persisted->fiscal_alloc_error_at, 'flag must be cleared on successful retry.');
    }

    /**
     * The retry command picks up flagged orders, invokes finalize, and
     * returns SUCCESS exit code.
     */
    public function test_retry_alloc_command_picks_up_flagged_orders_and_promotes(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);
        config(['fiscal.kiosk_auto_allocate_sequence' => true]);

        [, $kioskOrder] = $this->seedKioskScenario('CRON-001', 'kiosk-cron');

        // Simulate prior failed alloc.
        Order::withoutGlobalScopes()->where('id', $kioskOrder->id)->update([
            'fiscal_alloc_error_at' => now()->subMinutes(2),
        ]);

        $exitCode = $this->artisan('foodking:fiscal:retry-alloc')->run();

        $this->assertSame(0, $exitCode);

        $persisted = Order::withoutGlobalScopes()->findOrFail($kioskOrder->id);
        // [Wave S-1 — 2026-05-20] Owner P-OWNER Wave S-1: kiosk paid TPE
        // auto-promotes to PREPARING after finalize. The retry path goes
        // through finalizePaidKioskOrder, so it inherits the new behaviour.
        $this->assertSame(OrderStatus::PREPARING, (int) $persisted->status);
        $this->assertNotNull($persisted->fiscal_sequence_no);
        $this->assertNull($persisted->fiscal_alloc_error_at);
    }

    /**
     * P1a (code-review of G-DELIV-FISCAL) — the retry cron must SALVAGE a flagged
     * COD-delivery order, not just kiosk orders. finalizePaidKioskOrder early-returns
     * false for order_type=DELIVERY / payment_method=CASH_ON_DELIVERY, so without a
     * generic fallback the row loops forever PAID+seq=NULL, never entering the Z.
     */
    public function test_retry_alloc_command_salvages_flagged_cod_delivery_order(): void
    {
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id'             => $branch->id,
            'order_type'            => OrderType::DELIVERY,
            'payment_method'        => PaymentGateway::CASH_ON_DELIVERY,
            'status'                => OrderStatus::DELIVERED,
            'payment_status'        => PaymentStatus::PAID,
            'fiscal_sequence_no'    => null,
            'fiscal_alloc_error_at' => now()->subMinutes(2),
        ]);

        $exitCode = $this->artisan('foodking:fiscal:retry-alloc')->run();
        $this->assertSame(0, $exitCode);

        $persisted = Order::withoutGlobalScopes()->findOrFail($order->id);
        $this->assertNotNull(
            $persisted->fiscal_sequence_no,
            'A flagged COD delivery MUST be salvaged into the Z by the retry cron (NF525 exhaustivity).'
        );
        $this->assertNull($persisted->fiscal_alloc_error_at, 'flag cleared on successful salvage.');
        $this->assertSame(OrderStatus::DELIVERED, (int) $persisted->status, 'status unchanged — already delivered.');
    }

    /**
     * The retry command short-circuits when no flagged rows exist and
     * does not crash on an empty set.
     */
    public function test_retry_alloc_command_short_circuits_when_no_orphans(): void
    {
        $exitCode = $this->artisan('foodking:fiscal:retry-alloc')->run();
        $this->assertSame(0, $exitCode);
    }

    private function seedKioskScenario(string $serial, string $machine): array
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::forceCreate([
            'user_id'    => $kioskUser->id,
            'branch_id'  => $branch->id,
            'machine_id' => $machine . '-001',
            'username'   => $machine,
            'password'   => bcrypt('secret'),
            'is_login'   => Ask::NO,
            'status'     => Status::ACTIVE,
        ]);

        $kioskOrder = FrontendOrder::forceCreate([
            'order_serial_no'  => $serial,
            'user_id'          => $kioskUser->id,
            'branch_id'        => $branch->id,
            'status'           => OrderStatus::PENDING,
            'payment_status'   => PaymentStatus::PAID,
            'payment_method'   => PaymentGateway::CARD,
            'order_type'       => OrderType::KIOSK,
            'source'           => Source::APP,
            'source_surface'   => 'kiosk',
            'subtotal'         => 25.00,
            'total'            => 25.00,
            'total_tax'        => 0,
            'discount'         => 0,
            'delivery_charge'  => 0,
            'order_datetime'   => now(),
            'preparation_time' => 30,
            'queue_number'     => 'A0801',
        ]);

        return [$branch, $kioskOrder];
    }
}
