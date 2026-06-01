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
}
