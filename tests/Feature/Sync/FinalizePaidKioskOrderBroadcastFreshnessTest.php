<?php

namespace Tests\Feature\Sync;

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
use App\Models\User;
use App\Services\FrontendOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [Wave M / Heal Z2 P1 — broadcast freshness regression guard 2026-05-19]
 *
 * The Z2 P1 heal moves `OrderCreated::dispatch(...)` from OUTSIDE
 * `DB::transaction(...)` to INSIDE the closure so that the
 * {@see \App\Events\Concerns\DispatchableAfterCommit} trait engages.
 * For `finalizePaidKioskOrder`, the call site lives inside the closure
 * that locked + mutated `$locked` (lockForUpdate-loaded twin of the
 * caller's `$frontendOrder` parameter).
 *
 * Advisor pivot (2026-05-19): the dispatch MUST pass the freshly-mutated
 * `$locked` model, not the caller's stale `$frontendOrder`. Pre-Wave-M,
 * the helper-based dispatch fired AFTER `$frontendOrder->refresh()` had
 * re-read the row, so the broadcast captured `status=ACCEPT`. Moving the
 * dispatch inside the closure removes the refresh opportunity — passing
 * the stale parameter would cause KDS to receive a broadcast with
 * `status=PENDING` for a promotion event, which {@see \App\Listeners\PersistOrderCreatedToOutbox}
 * persists into the outbox payload at line 39 (`'status' => $order->status`).
 *
 * This test asserts the regression cannot recur:
 *   1. Seed a paid-pending kiosk order.
 *   2. Call `finalizePaidKioskOrder`.
 *   3. Listen to `OrderCreated`; assert the event's `$order->status`
 *      property is `ACCEPT` (not `PENDING`).
 */
class FinalizePaidKioskOrderBroadcastFreshnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_created_event_carries_promoted_status_not_stale_pending(): void
    {
        config(['fiscal.kiosk_auto_allocate_sequence' => true]);

        // Use real Event dispatcher (no fake) so we can register a
        // listener and inspect the broadcast payload state at dispatch
        // time. We swallow any side-effect listeners by binding the
        // outbox listener etc. — simpler: capture the FIRST OrderCreated
        // observed via a closure listener registered BEFORE the call.
        $capturedStatus = null;
        $capturedId = null;
        Event::listen(OrderCreated::class, function (OrderCreated $event) use (&$capturedStatus, &$capturedId) {
            $capturedStatus = (int) $event->order->status;
            $capturedId = (int) $event->order->id;
        });
        Event::fake([OrderStatusChanged::class]);

        [$branch, $kioskOrder] = $this->seedPaidKioskOrder();

        $promoted = app(FrontendOrderService::class)->finalizePaidKioskOrder($kioskOrder);

        $this->assertTrue($promoted, 'finalize must succeed for this seed scenario');

        $this->assertSame(
            $kioskOrder->id,
            $capturedId,
            'OrderCreated must fire for this order'
        );

        $this->assertSame(
            OrderStatus::ACCEPT,
            $capturedStatus,
            'OrderCreated event payload MUST carry the post-promotion status '
            . '(ACCEPT, 4). If this test reads PENDING (1), the dispatch inside '
            . 'finalizePaidKioskOrder is passing the caller\'s stale '
            . '$frontendOrder reference instead of the locked-and-mutated '
            . '$locked instance — see RED-Z2 advisor pivot 2026-05-19.'
        );
    }

    /**
     * Helper mirroring `FiscalAllocOrphanRetryTest::seedKioskScenario`.
     */
    private function seedPaidKioskOrder(): array
    {
        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::forceCreate([
            'user_id'    => $kioskUser->id,
            'branch_id'  => $branch->id,
            'machine_id' => 'kiosk-freshness-001',
            'username'   => 'kiosk-freshness',
            'password'   => bcrypt('secret'),
            'is_login'   => Ask::NO,
            'status'     => Status::ACTIVE,
        ]);

        $kioskOrder = FrontendOrder::forceCreate([
            'order_serial_no'  => 'FRESH-001',
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
            'queue_number'     => 'A1234',
        ]);

        return [$branch, $kioskOrder];
    }
}
