<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [HEAL-A.2 / Z8 P0-2 — 2026-05-19]
 * Sentinel for idempotent semantics of LoyaltyService::refundPoints.
 *
 * Pre-heal: a second call against the same (user_id, order_id) double-credited
 * the user's loyalty_points balance (via DB::table('users')->increment) BEFORE
 * hitting the LoyaltyTransaction::create which then threw SQLSTATE 23000 on the
 * UNIQUE (user_id, order_id, type) index — surfacing as a generic 500 to all
 * four callers (OrderService:1753, OrderService:1856, FrontendOrderService:707,
 * Order/RefundWithCounterEntryService:241) and rolling back their outer
 * DB::transaction (status flip / cashBack / mirror).
 *
 * Owner Q1 decision (planner HEAL-PLAN-A §A.2 + owner 2026-05-19): NOOP
 * idempotent via early-detect, NOT 409 throw. Reason: all 4 callers run inside
 * outer DB::transaction; a 409 throw would cascade-rollback the parent flow.
 */
class LoyaltyRefundPointsIdempotentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function createOrderWithRedeemLedger(int $pointsRedeemed, int $startingBalance = 100): array
    {
        $branch = \Database\Factories\BranchFactory::new()->create();

        $customer = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
            'loyalty_code' => strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 15)),
            'loyalty_points' => $startingBalance,
        ]);

        $staff = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => \App\Enums\Status::ACTIVE,
        ]);
        $staff->assignRole('Admin');

        $order = Order::create([
            'user_id' => $staff->id,
            'branch_id' => $branch->id,
            'order_type' => 5,
            'order_serial_no' => 'IDEMP-'.uniqid(),
            'subtotal' => 20.00,
            'total' => 15.00,
            'discount' => 5.00,
            'delivery_charge' => 0,
            'status' => OrderStatus::PENDING,
            'payment_method' => 1,
            'payment_status' => 1,
            'source' => 1,
            'loyalty_customer_code' => $customer->loyalty_code,
        ]);

        LoyaltyTransaction::create([
            'user_id' => $customer->id,
            'loyalty_code' => $customer->loyalty_code,
            'order_id' => $order->id,
            'type' => 'redeem',
            'points' => -$pointsRedeemed,
            'balance_after' => $customer->loyalty_points,
            'source_surface' => 'pos',
            'description' => 'Test redeem',
        ]);

        return [$order, $customer, $staff];
    }

    /**
     * Core idempotency invariant. Double-call must produce exactly 1 ledger row
     * AND credit the customer's loyalty_points exactly once.
     *
     * Pre-heal failure mode:
     *   - first call: balance 100 → 150, ledger +1 row (OK)
     *   - second call: balance 150 → 200 (BUG — double credit), then
     *     LoyaltyTransaction::create throws QueryException 23000 → 500 + the
     *     caller's outer DB::transaction rolls back.
     *
     * Post-heal expected:
     *   - first call: balance 100 → 150, ledger +1 row.
     *   - second call: NOOP — balance stays 150, ledger stays at 1 row, no
     *     exception propagated.
     */
    public function test_double_refund_points_is_idempotent_noop(): void
    {
        [$order, $customer] = $this->createOrderWithRedeemLedger(50, 100);

        $service = new LoyaltyService;

        // First call — happy path.
        $service->refundPoints($order, 'pos');

        $customer->refresh();
        $this->assertSame(150, (int) $customer->loyalty_points, 'First call must credit +50');
        $this->assertSame(
            1,
            LoyaltyTransaction::where('order_id', $order->id)->where('type', 'manual_add')->count(),
            'First call must write exactly 1 manual_add ledger row'
        );

        // Second call — must be a silent NOOP. No exception, no double-credit,
        // no second ledger row.
        $service->refundPoints($order, 'pos');

        $customer->refresh();
        $this->assertSame(
            150,
            (int) $customer->loyalty_points,
            'Second call must NOT double-credit loyalty_points (idempotent NOOP)'
        );
        $this->assertSame(
            1,
            LoyaltyTransaction::where('order_id', $order->id)->where('type', 'manual_add')->count(),
            'Second call must NOT insert a duplicate manual_add row'
        );
    }

    /**
     * Regression: NOOP detection must scope by (user_id, order_id) — a different
     * order on the same customer (e.g. customer cancels two separate orders)
     * MUST still credit normally.
     */
    public function test_distinct_orders_still_refund_normally(): void
    {
        [$orderA, $customer] = $this->createOrderWithRedeemLedger(30, 200);
        // Same customer, second order — reuse the loyalty_code so both refunds
        // route to the same user but the (user_id, order_id) uniqueness is
        // exercised across distinct order_ids.
        $branch = $orderA->branch_id;
        $staff = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $branch,
            'status' => \App\Enums\Status::ACTIVE,
        ]);
        $orderB = Order::create([
            'user_id' => $staff->id,
            'branch_id' => $branch,
            'order_type' => 5,
            'order_serial_no' => 'IDEMP-B-'.uniqid(),
            'subtotal' => 10.00,
            'total' => 8.00,
            'discount' => 2.00,
            'delivery_charge' => 0,
            'status' => OrderStatus::PENDING,
            'payment_method' => 1,
            'payment_status' => 1,
            'source' => 1,
            'loyalty_customer_code' => $customer->loyalty_code,
        ]);
        LoyaltyTransaction::create([
            'user_id' => $customer->id,
            'loyalty_code' => $customer->loyalty_code,
            'order_id' => $orderB->id,
            'type' => 'redeem',
            'points' => -20,
            'balance_after' => $customer->loyalty_points,
            'source_surface' => 'pos',
            'description' => 'Test redeem B',
        ]);

        $service = new LoyaltyService;
        $service->refundPoints($orderA, 'pos');
        $service->refundPoints($orderB, 'pos');

        $customer->refresh();
        // Started at 200, +30 (A) +20 (B) = 250.
        $this->assertSame(250, (int) $customer->loyalty_points);
        $this->assertSame(2, LoyaltyTransaction::where('user_id', $customer->id)
            ->where('type', 'manual_add')->count());
    }

    /**
     * Regression: the existing happy-path (single-call) behavior must be intact.
     * This mirrors OrderCancellationLoyaltyTest::test_cancel_order_refunds_loyalty_points
     * to prove the heal did not alter the first-call semantics.
     */
    public function test_single_refund_still_credits_and_writes_ledger(): void
    {
        [$order, $customer] = $this->createOrderWithRedeemLedger(75, 100);

        $service = new LoyaltyService;
        $service->refundPoints($order, 'pos');

        $customer->refresh();
        $this->assertSame(175, (int) $customer->loyalty_points);
        $refund = LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', 'manual_add')->first();
        $this->assertNotNull($refund);
        $this->assertSame(75, (int) $refund->points);
        $this->assertStringContainsString('Remboursement', (string) $refund->description);
    }
}
