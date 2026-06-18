<?php

namespace Tests\Feature\Abuse;

use App\Models\Coupon;
use App\Models\Order;
use App\Models\OrderCoupon;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VECTOR coupon_limit_race — abuse harness.
 * [abuse-heal 2026-06-18 engines]
 *
 * NOTE on :memory: limitation. PHPUnit runs SQLite :memory: which IGNORES
 * `FOR UPDATE` (the SQLite grammar does not even emit it — a `->toSql()` check
 * would be useless here). These tests therefore prove (a) the SERIALIZED
 * correctness of the per-user and global redemption caps, and (b) via a SOURCE
 * sentinel that both count queries are compiled WITH `lockForUpdate()` so that
 * under MySQL :8766 two concurrent redemptions of the same coupon serialize on
 * the order_coupons rows instead of both reading a stale count and both
 * passing. A TRUE lock-race can only be exercised against MySQL.
 *
 * Findings (5-engine hard discovery, adversarially confirmed race):
 *  - limit_per_user check (`orderedCouponCount`) read the count WITHOUT a lock
 *    → 2 concurrent requests both pass a limit_per_user=1 coupon.
 *  - max_uses_global check (`globalUsed`) had the same non-atomic semantics
 *    (the model comment near CouponService.php:452 acknowledges it).
 * The fix wraps both counts in `->lockForUpdate()` inside a DB::transaction.
 */
class CouponLimitRaceAbuseTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): int
    {
        return (int) User::factory()->create()->id;
    }

    private function activeCoupon(array $overrides = []): Coupon
    {
        return Coupon::factory()->create(array_merge([
            'discount_type'    => 1,        // percentage
            'discount'         => 10,
            'minimum_order'    => 0,
            'maximum_discount' => 0,
            'status'           => \App\Enums\Status::ACTIVE,
            'start_date'       => now()->subDay(),
            'end_date'         => now()->addDay(),
        ], $overrides));
    }

    private function redeem(Coupon $coupon, int $userId): void
    {
        // Real order row to satisfy the order_coupons.order_id FK.
        $order = Order::factory()->create(['user_id' => $userId]);
        OrderCoupon::query()->create([
            'order_id'  => $order->id,
            'coupon_id' => $coupon->id,
            'user_id'   => $userId,
            'discount'  => 1,
        ]);
    }

    /**
     * ABUSE 1 (serialized) — limit_per_user=1: after one redemption, the 2nd
     * resolve for the SAME user is rejected (422). A lost race would let both
     * through; the lock makes the read-modify atomic under MySQL.
     */
    public function test_per_user_limit_rejects_second_redemption(): void
    {
        $userId = $this->makeUser();
        $coupon = $this->activeCoupon(['limit_per_user' => 1]);

        // First redemption recorded.
        $this->redeem($coupon, $userId);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(422);
        app(CouponService::class)->resolveCouponById((int) $coupon->id, 100.0, $userId);
    }

    /**
     * CONTROL — a DIFFERENT user is NOT blocked by another user's redemption.
     */
    public function test_per_user_limit_is_scoped_to_user(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $coupon = $this->activeCoupon(['limit_per_user' => 1]);
        $this->redeem($coupon, $userA); // user A used it

        // user B has never used it → still valid.
        $resolved = app(CouponService::class)->resolveCouponById((int) $coupon->id, 100.0, $userB);
        $this->assertSame((int) $coupon->id, (int) $resolved->id);
    }

    /**
     * ABUSE 2 (serialized) — max_uses_global=1: after one global redemption
     * (by ANY user) the next resolve is rejected regardless of user.
     */
    public function test_global_cap_rejects_after_reached(): void
    {
        $userA = $this->makeUser();
        $userB = $this->makeUser();
        $coupon = $this->activeCoupon(['max_uses_global' => 1]);
        $this->redeem($coupon, $userA); // global usage now 1 of 1

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(422);
        // different user, but global cap is hit
        app(CouponService::class)->resolveCouponById((int) $coupon->id, 100.0, $userB);
    }

    /**
     * GUARD (source sentinel) — both cap checks in validateCouponForOrder must
     * carry `lockForUpdate()` so a future edit can't silently drop the lock and
     * reintroduce the race. We assert the source of CouponService contains the
     * locked OrderCoupon count for BOTH the per-user and global checks. (This is
     * the only reliable lock proof under SQLite, which strips FOR UPDATE.)
     */
    public function test_coupon_service_cap_checks_are_locked_in_source(): void
    {
        $src = file_get_contents(app_path('Services/CouponService.php'));

        // per-user check: OrderCoupon::where([... 'coupon_id' => $coupon->id ...])->lockForUpdate()->count()
        $perUserIdx = strpos($src, "'coupon_id' => \$coupon->id,");
        $this->assertNotFalse($perUserIdx, 'per-user OrderCoupon count marker not found');
        $perUserWindow = substr($src, $perUserIdx, 200);
        $this->assertStringContainsString(
            'lockForUpdate',
            $perUserWindow,
            'per-user limit_per_user count must use lockForUpdate (concurrency race guard)'
        );

        // global check: OrderCoupon::where('coupon_id', $coupon->id)->lockForUpdate()->count()
        $globalIdx = strpos($src, "OrderCoupon::where('coupon_id', \$coupon->id)");
        $this->assertNotFalse($globalIdx, 'global OrderCoupon count marker not found');
        $globalWindow = substr($src, $globalIdx, 200);
        $this->assertStringContainsString(
            'lockForUpdate',
            $globalWindow,
            'max_uses_global count must use lockForUpdate (concurrency race guard)'
        );
    }
}
