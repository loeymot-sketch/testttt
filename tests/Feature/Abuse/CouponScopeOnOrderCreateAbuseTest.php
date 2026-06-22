<?php

namespace Tests\Feature\Abuse;

use App\Models\Coupon;
use App\Models\User;
use App\Services\CouponService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * VECTOR coupon_scope_on_order_create — abuse harness.
 * [abuse-heal 2026-06-18 engines]
 *
 * Finding (5-engine hard discovery, adversarially confirmed): a coupon scoped
 * to a single surface (surfaces=['kiosk']) — or to a branch (branch_scope=[N])
 * — was enforced on the /coupon-checking endpoint but NOT on the order-CREATE
 * paths, which called resolveCouponById() WITHOUT branchId + surface. Result:
 * a kiosk-only coupon could be redeemed at POS / web (revenue leak). The fix
 * threads branchId + the order's surface into every order-create call site;
 * the model's isUsableNow() then rejects the cross-surface / cross-branch use.
 *
 * These tests exercise the validation mechanism directly via the SAME service
 * method the order-create paths call (resolveCouponById), proving that when the
 * scope args ARE supplied the cross-surface/branch use is rejected, and the
 * matching-surface use is accepted (control). The companion
 * CouponResolveScopeSentinelTest guarantees the call sites actually pass them.
 */
class CouponScopeOnOrderCreateAbuseTest extends TestCase
{
    use RefreshDatabase;

    private function makeUser(): int
    {
        return (int) User::factory()->create()->id;
    }

    private function coupon(array $overrides = []): Coupon
    {
        return Coupon::factory()->create(array_merge([
            'discount_type'    => 1,
            'discount'         => 10,
            'minimum_order'    => 0,
            'maximum_discount' => 0,
            'status'           => \App\Enums\Status::ACTIVE,
            'start_date'       => now()->subDay(),
            'end_date'         => now()->addDay(),
        ], $overrides));
    }

    /**
     * ABUSE — a kiosk-only coupon is REJECTED when resolved for the POS surface.
     */
    public function test_kiosk_only_coupon_rejected_on_pos_surface(): void
    {
        $userId = $this->makeUser();
        $coupon = $this->coupon(['surfaces' => ['kiosk']]);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(422);
        app(CouponService::class)->resolveCouponById((int) $coupon->id, 100.0, $userId, null, 'pos');
    }

    /**
     * ABUSE — a kiosk-only coupon is REJECTED when resolved for the web surface.
     */
    public function test_kiosk_only_coupon_rejected_on_web_surface(): void
    {
        $userId = $this->makeUser();
        $coupon = $this->coupon(['surfaces' => ['kiosk']]);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(422);
        app(CouponService::class)->resolveCouponById((int) $coupon->id, 100.0, $userId, null, 'web');
    }

    /**
     * CONTROL — the same kiosk-only coupon is ACCEPTED on the kiosk surface.
     */
    public function test_kiosk_only_coupon_accepted_on_kiosk_surface(): void
    {
        $userId = $this->makeUser();
        $coupon = $this->coupon(['surfaces' => ['kiosk']]);

        $resolved = app(CouponService::class)
            ->resolveCouponById((int) $coupon->id, 100.0, $userId, null, 'kiosk');

        $this->assertSame((int) $coupon->id, (int) $resolved->id);
    }

    /**
     * ABUSE (branch, V2-prep) — a coupon scoped to branch 1 is REJECTED when
     * resolved for branch 2.
     */
    public function test_branch_scoped_coupon_rejected_on_other_branch(): void
    {
        $userId = $this->makeUser();
        $coupon = $this->coupon(['branch_scope' => [1]]);

        $this->expectException(\Exception::class);
        $this->expectExceptionCode(422);
        app(CouponService::class)->resolveCouponById((int) $coupon->id, 100.0, $userId, 2, 'pos');
    }

    /**
     * CONTROL — the branch-1 coupon is ACCEPTED on branch 1.
     */
    public function test_branch_scoped_coupon_accepted_on_same_branch(): void
    {
        $userId = $this->makeUser();
        $coupon = $this->coupon(['branch_scope' => [1]]);

        $resolved = app(CouponService::class)
            ->resolveCouponById((int) $coupon->id, 100.0, $userId, 1, 'pos');

        $this->assertSame((int) $coupon->id, (int) $resolved->id);
    }

    /**
     * CONTROL — an UNSCOPED coupon (no surfaces / no branch_scope) is accepted
     * everywhere (backward compatibility — must not over-reject legacy coupons).
     */
    public function test_unscoped_coupon_accepted_on_any_surface(): void
    {
        $userId = $this->makeUser();
        $coupon = $this->coupon();

        $resolved = app(CouponService::class)
            ->resolveCouponById((int) $coupon->id, 100.0, $userId, 2, 'pos');

        $this->assertSame((int) $coupon->id, (int) $resolved->id);
    }
}
