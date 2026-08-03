<?php

namespace Tests\Feature\Coupon;

use App\Enums\DiscountType;
use App\Enums\Status;
use App\Models\Coupon;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [PROMO-DASH-2026-05-06] Couvre {@see Coupon::isUsableNow()} sur toutes les
 * dimensions de scoping ajoutées au cycle 6 :
 *  - days of week
 *  - hours window (incl. plages chevauchant minuit)
 *  - branch scope
 *  - surface scope
 *  - status INACTIVE
 *  - dates expirées / pas encore actives
 *  - max_uses_global atteint
 */
class CouponValidityTest extends TestCase
{
    use RefreshDatabase;

    private function makeCoupon(array $overrides = []): Coupon
    {
        return Coupon::create(array_merge([
            'name'             => 'Promo CY' . uniqid(),
            'description'      => 'Test',
            'code'             => 'CY' . strtoupper(uniqid()),
            'discount'         => 10,
            'discount_type'    => DiscountType::FIXED,
            'start_date'       => Carbon::now()->subDay(),
            'end_date'         => Carbon::now()->addMonth(),
            'minimum_order'    => 0,
            'maximum_discount' => 0,
            'limit_per_user'   => 0,
            'status'           => Status::ACTIVE,
            'usage_count'      => 0,
        ], $overrides));
    }

    public function test_coupon_active_with_no_scope_is_usable_now(): void
    {
        $coupon = $this->makeCoupon();
        $this->assertTrue($coupon->isUsableNow());
        $this->assertTrue($coupon->isUsableNow(branchId: 1, surface: 'pos'));
    }

    public function test_inactive_status_blocks_usage(): void
    {
        $coupon = $this->makeCoupon(['status' => Status::INACTIVE]);
        $this->assertFalse($coupon->isUsableNow());
    }

    public function test_expired_end_date_blocks_usage(): void
    {
        $coupon = $this->makeCoupon([
            'start_date' => Carbon::now()->subYear(),
            'end_date'   => Carbon::now()->subDay(),
        ]);
        $this->assertFalse($coupon->isUsableNow());
    }

    public function test_future_start_date_blocks_usage(): void
    {
        $coupon = $this->makeCoupon([
            'start_date' => Carbon::now()->addDay(),
            'end_date'   => Carbon::now()->addMonth(),
        ]);
        $this->assertFalse($coupon->isUsableNow());
    }

    public function test_day_of_week_scope_blocks_off_days(): void
    {
        // Couvre tous les jours sauf aujourd'hui -> doit échouer.
        $today = strtolower(substr(Carbon::now()->englishDayOfWeek, 0, 3));
        $allDays = ['mon', 'tue', 'wed', 'thu', 'fri', 'sat', 'sun'];
        $offDays = array_values(array_diff($allDays, [$today]));

        $coupon = $this->makeCoupon(['valid_days_of_week' => $offDays]);
        $this->assertFalse($coupon->isUsableNow());

        $coupon2 = $this->makeCoupon(['valid_days_of_week' => [$today]]);
        $this->assertTrue($coupon2->isUsableNow());
    }

    public function test_hours_window_blocks_off_hours(): void
    {
        $now = Carbon::now()->setTime(14, 30, 0);
        $coupon = $this->makeCoupon([
            'valid_hours_start' => '12:00:00',
            'valid_hours_end'   => '13:00:00',
        ]);
        $this->assertFalse($coupon->isUsableNow(now: $now));

        $coupon2 = $this->makeCoupon([
            'valid_hours_start' => '12:00:00',
            'valid_hours_end'   => '15:00:00',
        ]);
        $this->assertTrue($coupon2->isUsableNow(now: $now));
    }

    public function test_hours_window_overnight_supports_wrap(): void
    {
        // Plage 22:00 → 02:00 (wrap minuit)
        $coupon = $this->makeCoupon([
            'valid_hours_start' => '22:00:00',
            'valid_hours_end'   => '02:00:00',
        ]);
        $this->assertTrue($coupon->isUsableNow(now: Carbon::now()->setTime(23, 30, 0)));
        $this->assertTrue($coupon->isUsableNow(now: Carbon::now()->setTime(1, 0, 0)));
        $this->assertFalse($coupon->isUsableNow(now: Carbon::now()->setTime(15, 0, 0)));
    }

    public function test_branch_scope_blocks_other_branches(): void
    {
        $coupon = $this->makeCoupon(['branch_scope' => [1, 3]]);
        $this->assertTrue($coupon->isUsableNow(branchId: 1));
        $this->assertTrue($coupon->isUsableNow(branchId: 3));
        $this->assertFalse($coupon->isUsableNow(branchId: 2));
        $this->assertFalse($coupon->isUsableNow(branchId: null));
    }

    public function test_surface_scope_blocks_other_surfaces(): void
    {
        $coupon = $this->makeCoupon(['surfaces' => ['pos', 'kiosk']]);
        $this->assertTrue($coupon->isUsableNow(branchId: 1, surface: 'pos'));
        $this->assertTrue($coupon->isUsableNow(branchId: 1, surface: 'KIOSK'));
        $this->assertFalse($coupon->isUsableNow(branchId: 1, surface: 'web'));
        $this->assertFalse($coupon->isUsableNow(branchId: 1, surface: null));
    }

    public function test_max_uses_global_blocks_when_reached(): void
    {
        $coupon = $this->makeCoupon([
            'max_uses_global' => 100,
            'usage_count'     => 100,
        ]);
        $this->assertFalse($coupon->isUsableNow());

        $coupon2 = $this->makeCoupon([
            'max_uses_global' => 100,
            'usage_count'     => 99,
        ]);
        $this->assertTrue($coupon2->isUsableNow());
    }

    public function test_active_scope_filters_inactive_and_expired(): void
    {
        $active = $this->makeCoupon();
        $this->makeCoupon(['status' => Status::INACTIVE]);
        $this->makeCoupon([
            'start_date' => Carbon::now()->subYear(),
            'end_date'   => Carbon::now()->subDay(),
        ]);

        $list = Coupon::active()->get();
        $this->assertCount(1, $list);
        $this->assertSame($active->id, $list->first()->id);
    }
}
