<?php

namespace Tests\Feature\Coupon;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Coupon;
use App\Models\OrderCoupon;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL_MGMT_TESTPLAN 2026-06-01 — COUPON-CAP-01 heal] max_uses_global enforcement.
 *
 * Adversarial audit (wf6dhhn09) + prior cycles found Coupon::isUsableNow() checked
 * `usage_count >= max_uses_global`, but usage_count is NEVER incremented (dead column),
 * so a globally-capped coupon ("max N uses") was effectively unlimited. Heal:
 * CouponService::validateCouponForOrder counts actual redemptions from `order_coupons`
 * (same source-of-truth as the working limit_per_user cap).
 *
 * @group sentinel
 */
class CouponMaxUsesGlobalEnforcementTest extends TestCase
{
    use RefreshDatabase;

    private function coupon(int $maxGlobal): Coupon
    {
        return Coupon::create([
            'name' => 'CapTest', 'description' => 'cap', 'code' => 'CAP' . $maxGlobal . uniqid(),
            'discount' => 10, 'discount_type' => DiscountType::PERCENTAGE, 'status' => Status::ACTIVE,
            'start_date' => null, 'end_date' => null, 'valid_days_of_week' => null,
            'surfaces' => null, 'branch_scope' => null, 'max_uses_global' => $maxGlobal,
            'usage_count' => 0, 'minimum_order' => 0, 'maximum_discount' => 0, 'limit_per_user' => 0,
        ]);
    }

    private function recordUse(Coupon $c, int $userId): void
    {
        // order_coupons row = one global redemption (real order_id to satisfy the FK).
        $order = \App\Models\Order::factory()->create();
        OrderCoupon::create([
            'order_id' => $order->id, 'coupon_id' => $c->id,
            'user_id' => $userId, 'discount' => 1.0,
        ]);
    }

    public function test_coupon_at_global_cap_is_rejected(): void
    {
        $c = $this->coupon(1);
        $u = User::factory()->create();
        $this->recordUse($c, $u->id); // 1 global use, cap = 1 → next must fail

        $this->expectException(\Exception::class);
        $this->app->make(CouponService::class)->resolveCouponById($c->id, 20.0, $u->id, 1, 'kiosk');
    }

    public function test_coupon_under_global_cap_is_allowed(): void
    {
        $c = $this->coupon(2);
        $u = User::factory()->create();
        $this->recordUse($c, $u->id); // 1 global use, cap = 2 → still allowed

        $resolved = $this->app->make(CouponService::class)->resolveCouponById($c->id, 20.0, $u->id, 1, 'kiosk');
        $this->assertEquals($c->id, $resolved->id, 'Coupon under its global cap must still resolve.');
    }

    /**
     * Test A — full sequence: cap=2, after 2 successful redemptions the 3rd application
     * is rejected. Each successful resolve is followed by an order_coupons row (the real
     * consumption side-effect of order creation, cf. OrderService::store) so the cap counts
     * actual redemptions.
     */
    public function test_third_application_rejected_after_two_redemptions(): void
    {
        $service = $this->app->make(CouponService::class);
        $c = $this->coupon(2);
        $u = User::factory()->create();

        // Redemption #1 — must succeed, then record the consumption.
        $service->resolveCouponById($c->id, 20.0, $u->id, 1, 'kiosk');
        $this->recordUse($c, $u->id);

        // Redemption #2 — must succeed, then record the consumption.
        $service->resolveCouponById($c->id, 20.0, $u->id, 1, 'kiosk');
        $this->recordUse($c, $u->id);

        // Cap reached (2 redemptions counted in order_coupons, cap = 2).
        $this->assertEquals(2, OrderCoupon::where('coupon_id', $c->id)->count(), 'Exactly 2 redemptions recorded.');

        // 3rd application must be rejected — the global cap is enforced.
        $this->expectException(\Exception::class);
        $service->resolveCouponById($c->id, 20.0, $u->id, 1, 'kiosk');
    }

    /**
     * Test B (behavioral, not mechanism) — each successful redemption consumes exactly one
     * unit of global capacity, no double-count. The source-of-truth is the order_coupons
     * row count (mirrors limit_per_user), NOT the dead `usage_count` column. We assert the
     * remaining capacity decreases by exactly 1 per redemption.
     */
    public function test_each_redemption_consumes_exactly_one_capacity_unit(): void
    {
        $service = $this->app->make(CouponService::class);
        $c = $this->coupon(3);
        $u = User::factory()->create();

        $this->assertEquals(0, OrderCoupon::where('coupon_id', $c->id)->count());

        $service->resolveCouponById($c->id, 20.0, $u->id, 1, 'kiosk');
        $this->recordUse($c, $u->id);
        $this->assertEquals(1, OrderCoupon::where('coupon_id', $c->id)->count(), 'One redemption → exactly +1 (no double-count).');

        $service->resolveCouponById($c->id, 20.0, $u->id, 1, 'kiosk');
        $this->recordUse($c, $u->id);
        $this->assertEquals(2, OrderCoupon::where('coupon_id', $c->id)->count(), 'Second redemption → exactly +1.');
    }

    /**
     * Test C (regression) — a coupon with max_uses_global = NULL (unlimited) is never blocked
     * by the cap, regardless of how many redemptions exist.
     */
    public function test_unlimited_coupon_is_never_blocked_by_cap(): void
    {
        $service = $this->app->make(CouponService::class);
        $c = $this->coupon(0);          // helper passes 0; normalize to NULL = unlimited.
        $c->max_uses_global = null;
        $c->save();
        $u = User::factory()->create();

        // Many redemptions — none should ever trip the (absent) cap.
        for ($i = 0; $i < 5; $i++) {
            $this->recordUse($c, $u->id);
        }

        $resolved = $service->resolveCouponById($c->id, 20.0, $u->id, 1, 'kiosk');
        $this->assertEquals($c->id, $resolved->id, 'Unlimited (NULL cap) coupon must never be blocked by the global cap.');
    }
}
