<?php

namespace Tests\Feature\Abuse;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\LoyaltyTransaction;
use App\Models\OrderStatusTransition;
use App\Models\User;
use App\Services\FrontendOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VECTOR frontend_order_double_refund — abuse harness.
 * [abuse-heal 2026-06-18 engines]
 *
 * NOTE on :memory: limitation. PHPUnit runs SQLite :memory: which IGNORES
 * `FOR UPDATE`, so a TRUE two-process cancel race can only be exercised
 * against MySQL :8766. Here we simulate the LOST race sequentially: request B
 * captures a STALE pre-cancel copy of the order, request A cancels (refund +
 * one transition row), then request B replays its cancel on the stale copy.
 *
 * Finding (5-engine hard discovery, adversarially confirmed): unlike
 * OrderService::changeStatus (which re-fetches the order under
 * lockForUpdate inside a DB::transaction and re-validates the transition
 * against the LOCKED status — ~lines 2153-2245), the FrontendOrder twin read
 * $oldStatus + called cashBack()/refundPoints() + save() + recordTransition()
 * WITHOUT a lock or transaction. Two concurrent CANCELs therefore both passed
 * the stale in-memory status check → double loyalty refund (the credit at
 * LoyaltyService:84 runs before the UNIQUE-guarded ledger insert) AND two
 * order_status_transitions rows (recordTransition has no idempotency guard).
 *
 * The fix mirrors OrderService::changeStatus: wrap the cancel mutation in a
 * DB::transaction, re-fetch the FrontendOrder under lockForUpdate, and
 * early-return if the locked status is already the target — so request B is a
 * no-op. This test asserts the refund + transition happen EXACTLY ONCE and
 * that the lock guard is present in source.
 */
class FrontendOrderChangeStatusDoubleRefundAbuseTest extends TestCase
{
    use RefreshDatabase;

    private function cancelRequest(): OrderStatusRequest
    {
        // The service reads only ->status and ->input('reason'); validation runs
        // at the controller boundary, not inside changeStatus.
        $request = OrderStatusRequest::create('/x', 'POST', [
            'status' => OrderStatus::CANCELED,
            'reason' => 'CUSTOMER_REQUEST',
        ]);
        $request->setContainer(app())->setRedirector(app('redirect'));

        return $request;
    }

    public function test_double_cancel_refunds_points_and_records_transition_exactly_once(): void
    {
        // Branch to satisfy the orders.branch_id FK.
        $branch = Branch::factory()->create();

        // Owner with a redeemed-points history so refundPoints has work to do.
        $owner = User::factory()->create([
            'loyalty_code'   => 'LOY-DBL-1',
            'loyalty_points' => 0,
            'status'         => \App\Enums\Status::ACTIVE,
        ]);

        $order = FrontendOrder::create([
            'user_id'               => $owner->id,
            'branch_id'             => $branch->id,
            'order_serial_no'       => 'FO-DBL-1',
            'order_type'            => OrderType::KIOSK,
            'status'                => OrderStatus::PENDING, // below kiosk cancel threshold (PREPARING)
            'payment_status'        => 5,
            'subtotal'              => 20,
            'total'                 => 20,
            'discount'              => 0,
            'delivery_charge'       => 0,
            'order_datetime'        => now(),
            'loyalty_customer_code' => 'LOY-DBL-1',
        ]);

        // 50 points were redeemed on this order → refundPoints should credit 50 back, once.
        LoyaltyTransaction::create([
            'user_id'       => $owner->id,
            'loyalty_code'  => 'LOY-DBL-1',
            'order_id'      => $order->id,
            'type'          => 'redeem',
            'points'        => -50,
            'balance_after' => 0,
            'source_surface' => 'kiosk',
            'description'   => 'redeem on order',
        ]);

        $this->actingAs($owner);
        $service = app(FrontendOrderService::class);

        // Request B captures a STALE pre-cancel snapshot of the order.
        $staleCopyForRequestB = FrontendOrder::findOrFail($order->id);
        $this->assertSame(OrderStatus::PENDING, (int) $staleCopyForRequestB->status);

        // Request A cancels first.
        $service->changeStatus(FrontendOrder::findOrFail($order->id), $this->cancelRequest());

        // Request B replays its cancel on the stale (still-PENDING in memory) copy.
        // With the lock+re-fetch fix this is a no-op (locked status is CANCELED).
        $service->changeStatus($staleCopyForRequestB, $this->cancelRequest());

        // Order ends CANCELED.
        $this->assertSame(OrderStatus::CANCELED, (int) FrontendOrder::findOrFail($order->id)->status);

        // EXACTLY ONE transition row (PENDING -> CANCELED). The pre-fix twin wrote two.
        $transitionRows = OrderStatusTransition::where('order_id', $order->id)
            ->where('order_type', FrontendOrder::class)
            ->where('to_status', OrderStatus::CANCELED)
            ->count();
        $this->assertSame(1, $transitionRows, 'double-cancel must record the transition exactly once');

        // EXACTLY ONE refund credit (type manual_add) and balance credited once (50, not 100).
        $refundRows = LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', 'manual_add')
            ->count();
        $this->assertSame(1, $refundRows, 'double-cancel must refund loyalty points exactly once');
        $this->assertSame(50, (int) $owner->fresh()->loyalty_points, 'points credited once, not doubled');
    }

    /**
     * GUARD (source sentinel) — FrontendOrderService::changeStatus must re-fetch
     * under lockForUpdate inside a DB::transaction so a concurrent cancel cannot
     * double-refund. Locks the fix against regression.
     */
    public function test_change_status_uses_lock_for_update_in_source(): void
    {
        $src = file_get_contents(app_path('Services/FrontendOrderService.php'));

        $idx = strpos($src, 'public function changeStatus(');
        $this->assertNotFalse($idx, 'changeStatus method not found in FrontendOrderService');

        // Inspect the changeStatus method body (bounded window).
        $window = substr($src, $idx, 3500);
        $this->assertStringContainsString(
            'lockForUpdate',
            $window,
            'FrontendOrderService::changeStatus must re-fetch the order under lockForUpdate (double-refund race guard)'
        );
        $this->assertStringContainsString(
            'DB::transaction',
            $window,
            'FrontendOrderService::changeStatus must wrap the cancel mutation in DB::transaction'
        );
    }
}
