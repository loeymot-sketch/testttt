<?php

namespace Tests\Feature\Loyalty;

use App\Models\LoyaltyTransaction;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [CLUSTER-7 / P3 2026-07-11]
 * Sentinel for LoyaltyService::reapOrphanRedemptions.
 *
 * Bug: the self-service pre-redeem endpoint (LoyaltyController::redeem) debits
 * loyalty points immediately and writes a PENDING ledger row (type='redeem',
 * order_id=NULL). An order backfills that row's order_id (10-min attach window,
 * FrontendOrderService). If NO order is ever placed (abandoned), the row stays
 * order_id=NULL forever and the order-keyed LoyaltyService::refundPoints can
 * never re-credit it → the customer's points are burned.
 *
 * The reaper re-credits any unconsumed pending redeem older than the configured
 * window, idempotently, without touching orders / fiscal state.
 */
class OrphanRedeemReaperTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
    }

    private function makeCustomer(int $balance): \App\Models\User
    {
        return \Database\Factories\UserFactory::new()->create([
            'branch_id' => \Database\Factories\BranchFactory::new()->create()->id,
            'status' => \App\Enums\Status::ACTIVE,
            'loyalty_code' => strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 15)),
            'loyalty_points' => $balance,
        ]);
    }

    private function makePendingRedeem(\App\Models\User $customer, int $points, int $ageMinutes): LoyaltyTransaction
    {
        // Reflects the state written by LoyaltyController::redeem after it has
        // already decremented the user balance: order_id NULL, negative points.
        $txn = LoyaltyTransaction::create([
            'user_id' => $customer->id,
            'loyalty_code' => $customer->loyalty_code,
            'order_id' => null,
            'type' => 'redeem',
            'points' => -$points,
            'balance_after' => (int) $customer->loyalty_points,
            'source_surface' => 'kiosk',
            'description' => 'Réduction fidélité appliquée',
        ]);
        // Backdate so the reaper's staleness window sees it.
        $txn->forceFill(['created_at' => now()->subMinutes($ageMinutes)])->save();

        return $txn;
    }

    /**
     * Core: an abandoned pending redeem older than the window is re-credited.
     */
    public function test_orphan_pending_redeem_is_recredited(): void
    {
        // Customer had 500, redeemed 100 → balance now 400, pending redeem orphaned.
        $customer = $this->makeCustomer(400);
        $orphan = $this->makePendingRedeem($customer, 100, 45);

        $reaped = (new LoyaltyService)->reapOrphanRedemptions(30);

        $this->assertSame(1, $reaped);
        $customer->refresh();
        $this->assertSame(500, (int) $customer->loyalty_points, 'Stranded points must be returned');

        $reversal = LoyaltyTransaction::where('user_id', $customer->id)
            ->where('type', 'manual_add')->first();
        $this->assertNotNull($reversal);
        $this->assertSame(100, (int) $reversal->points);
        $this->assertStringContainsString('[reap:'.$orphan->id.']', (string) $reversal->description);
    }

    /**
     * Idempotency: a second reaper run must NOT double-credit.
     */
    public function test_reaper_is_idempotent(): void
    {
        $customer = $this->makeCustomer(400);
        $this->makePendingRedeem($customer, 100, 45);

        $service = new LoyaltyService;
        $this->assertSame(1, $service->reapOrphanRedemptions(30));
        $this->assertSame(0, $service->reapOrphanRedemptions(30), 'Second run must be a NOOP');

        $customer->refresh();
        $this->assertSame(500, (int) $customer->loyalty_points, 'No double-credit');
        $this->assertSame(1, LoyaltyTransaction::where('user_id', $customer->id)
            ->where('type', 'manual_add')->count());
    }

    /**
     * A pending redeem still inside the attach window must be LEFT ALONE so it
     * can still be consumed by an incoming order (no premature re-credit).
     */
    public function test_recent_pending_redeem_is_not_reaped(): void
    {
        $customer = $this->makeCustomer(400);
        $this->makePendingRedeem($customer, 100, 5); // 5 min old, within window

        $this->assertSame(0, (new LoyaltyService)->reapOrphanRedemptions(30));
        $customer->refresh();
        $this->assertSame(400, (int) $customer->loyalty_points, 'Recent redeem must not be touched');
    }

    /**
     * A CONSUMED redeem (order_id backfilled) must never be re-credited — that
     * would refund a discount the customer actually used.
     */
    public function test_consumed_redeem_is_not_reaped(): void
    {
        $customer = $this->makeCustomer(400);
        $orphan = $this->makePendingRedeem($customer, 100, 45);
        // Simulate the order attach that FrontendOrderService performs.
        $orphan->forceFill(['order_id' => 999])->save();

        $this->assertSame(0, (new LoyaltyService)->reapOrphanRedemptions(30));
        $customer->refresh();
        $this->assertSame(400, (int) $customer->loyalty_points, 'Consumed redeem must not be refunded');
        $this->assertSame(0, LoyaltyTransaction::where('type', 'manual_add')->count());
    }
}
