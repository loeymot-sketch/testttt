<?php

namespace Tests\Feature\Jobs;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
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
 * Gap-Hunt Phase E — Sentinel for GAP-C1-002.
 *
 * Locks the extended payment_status filter on CleanupStalePendingKioskOrders:
 *   - whereIn('payment_status', [UNPAID, PENDING_COUNTER]).
 *
 * Previously the job only cleaned UNPAID kiosk orders → PENDING_COUNTER
 * zombies (kiosk cash abandoned mid-flow) polluted KDS forever + risk NF525
 * if encaissé à tort. This sentinel guards the 4 critical states of the
 * cleanup matrix:
 *
 *   - stale UNPAID kiosk         → cleaned (regression guard)
 *   - stale PENDING_COUNTER kiosk → cleaned (NEW behavior locked)
 *   - stale PAID kiosk           → NOT cleaned (TTL respected for paid)
 *   - young PENDING_COUNTER kiosk → NOT cleaned (TTL respected)
 *
 * If this test goes red, the cleanup job's behavior has drifted and
 * PENDING_COUNTER zombies will polluate KDS again. Do NOT relax without
 * NF525 owner gate.
 */
class CleanupStalePendingKioskOrdersExtendedSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_extended_cleanup_matrix_4_scenarios(): void
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

        $staleAt = now()->subMinutes(20);
        $freshAt = now()->subMinutes(5);

        // Scenario 1 — stale UNPAID kiosk: cleaned (regression guard)
        $staleUnpaid = $this->makeKioskOrder($branch->id, $customer->id, $staleAt, PaymentStatus::UNPAID);

        // Scenario 2 — stale PENDING_COUNTER kiosk: cleaned (NEW behavior)
        $stalePendingCounter = $this->makeKioskOrder($branch->id, $customer->id, $staleAt, PaymentStatus::PENDING_COUNTER);

        // Scenario 3 — stale PAID kiosk: NOT cleaned (paid order must never auto-reject)
        $stalePaid = $this->makeKioskOrder($branch->id, $customer->id, $staleAt, PaymentStatus::PAID);

        // Scenario 4 — young PENDING_COUNTER kiosk: NOT cleaned (TTL respected)
        $youngPendingCounter = $this->makeKioskOrder($branch->id, $customer->id, $freshAt, PaymentStatus::PENDING_COUNTER);

        (new CleanupStalePendingKioskOrders())->handle();

        // Scenario 1 + 2: both rejected
        $this->assertSame(
            OrderStatus::REJECTED,
            (int) $staleUnpaid->fresh()->status,
            'Stale UNPAID kiosk order must be auto-rejected (regression guard).'
        );
        $this->assertSame(
            OrderStatus::REJECTED,
            (int) $stalePendingCounter->fresh()->status,
            'Stale PENDING_COUNTER kiosk order must be auto-rejected (GAP-C1-002 NEW behavior).'
        );

        // Scenario 3: paid order untouched
        $this->assertSame(
            OrderStatus::PENDING,
            (int) $stalePaid->fresh()->status,
            'Stale PAID kiosk order must NEVER be auto-rejected (NF525 invariant).'
        );
        $this->assertSame(
            PaymentStatus::PAID,
            (int) $stalePaid->fresh()->payment_status,
            'Stale PAID kiosk order payment_status must remain PAID.'
        );

        // Scenario 4: young PENDING_COUNTER untouched (TTL respected)
        $this->assertSame(
            OrderStatus::PENDING,
            (int) $youngPendingCounter->fresh()->status,
            'Young PENDING_COUNTER kiosk order must NOT be cleaned (TTL respected).'
        );
        $this->assertSame(
            PaymentStatus::PENDING_COUNTER,
            (int) $youngPendingCounter->fresh()->payment_status,
            'Young PENDING_COUNTER kiosk order payment_status must remain PENDING_COUNTER.'
        );

        // Events dispatched exactly twice (once per rejected order).
        Event::assertDispatchedTimes(OrderStatusChanged::class, 2);
        Event::assertDispatchedTimes(OrderCanceled::class, 2);

        // Confirm the rejected order IDs match scenarios 1 + 2 (not 3 or 4).
        $rejectedIds = collect();
        Event::assertDispatched(OrderStatusChanged::class, function (OrderStatusChanged $event) use ($rejectedIds): bool {
            $rejectedIds->push((int) $event->order->id);
            return $event->newStatus === OrderStatus::REJECTED;
        });
        $this->assertEqualsCanonicalizing(
            [(int) $staleUnpaid->id, (int) $stalePendingCounter->id],
            $rejectedIds->all(),
            'Only the two stale UNPAID + PENDING_COUNTER orders must be in the OrderStatusChanged event stream.'
        );
    }

    private function makeKioskOrder(int $branchId, int $userId, Carbon $createdAt, int $paymentStatus): FrontendOrder
    {
        return FrontendOrder::withoutGlobalScopes()->create([
            'order_serial_no' => 'KIOSK-GAP-C1-002-' . fake()->unique()->numerify('######'),
            'user_id' => $userId,
            'branch_id' => $branchId,
            'subtotal' => 10,
            'discount' => 0,
            'delivery_charge' => 0,
            'total_tax' => 1,
            'total' => 11,
            'order_type' => OrderType::TAKEAWAY,
            'order_datetime' => $createdAt,
            'preparation_time' => 15,
            'is_advance_order' => 0,
            'payment_method' => 1,
            'payment_status' => $paymentStatus,
            'status' => OrderStatus::PENDING,
            'source' => 10,
            'source_surface' => 'kiosk',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);
    }
}
