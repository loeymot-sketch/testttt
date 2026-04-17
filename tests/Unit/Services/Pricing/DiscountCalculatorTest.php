<?php

namespace Tests\Unit\Services\Pricing;

use App\Models\Coupon;
use App\Services\CouponService;
use App\Services\Pricing\DiscountCalculator;
use PHPUnit\Framework\TestCase;

/**
 * Unit coverage for PricingService::DiscountCalculator.
 *
 * Pure-PHP paths only (couponDiscount / manualDiscount). kioskLoyaltyRedemption
 * is covered indirectly by FrontendOrderServiceKioskLoyaltyTest since it pulls
 * from Spatie Settings which requires the Laravel container.
 *
 * @see app/Services/Pricing/DiscountCalculator.php
 * @see docs/PRICING_SSOT.md
 */
class DiscountCalculatorTest extends TestCase
{
    public function test_manual_discount_zero_when_requested_negative_or_zero(): void
    {
        $calc = new DiscountCalculator;
        $this->assertSame(0.0, $calc->manualDiscount(0.0, 100.0));
        $this->assertSame(0.0, $calc->manualDiscount(-5.0, 100.0));
    }

    public function test_manual_discount_returns_requested_when_below_subtotal(): void
    {
        $calc = new DiscountCalculator;
        $this->assertSame(10.0, $calc->manualDiscount(10.0, 100.0));
        $this->assertSame(99.99, $calc->manualDiscount(99.99, 100.0));
    }

    public function test_manual_discount_equal_to_subtotal_is_allowed(): void
    {
        $calc = new DiscountCalculator;
        $this->assertSame(50.0, $calc->manualDiscount(50.0, 50.0));
    }

    public function test_manual_discount_rejected_when_greater_than_subtotal(): void
    {
        // Contract: cashier cannot discount more than the cart subtotal (would yield
        // a negative total). Calculator returns 0 rather than capping so the caller
        // can detect the violation explicitly.
        $calc = new DiscountCalculator;
        $this->assertSame(0.0, $calc->manualDiscount(200.0, 100.0));
    }

    public function test_coupon_discount_zero_when_id_non_positive(): void
    {
        $calc = new DiscountCalculator;
        $couponSvc = $this->createMock(CouponService::class);
        $couponSvc->expects($this->never())->method('resolveCouponById');
        $couponSvc->expects($this->never())->method('calculateDiscountAmount');

        $this->assertSame(0.0, $calc->couponDiscount($couponSvc, 0, 100.0, 42));
        $this->assertSame(0.0, $calc->couponDiscount($couponSvc, -1, 100.0, 42));
    }

    public function test_coupon_discount_delegates_to_service_and_casts_to_float(): void
    {
        $calc = new DiscountCalculator;
        $coupon = $this->createMock(Coupon::class);

        $couponSvc = $this->createMock(CouponService::class);
        $couponSvc->expects($this->once())
            ->method('resolveCouponById')
            ->with(7, 42.50, 99)
            ->willReturn($coupon);
        $couponSvc->expects($this->once())
            ->method('calculateDiscountAmount')
            ->with($coupon, 42.50)
            ->willReturn(5.75);

        $this->assertSame(5.75, $calc->couponDiscount($couponSvc, 7, 42.50, 99));
    }

    public function test_coupon_discount_propagates_service_exception(): void
    {
        $calc = new DiscountCalculator;
        $couponSvc = $this->createMock(CouponService::class);
        $couponSvc->expects($this->once())
            ->method('resolveCouponById')
            ->willThrowException(new \Exception('Coupon expired'));

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Coupon expired');

        $calc->couponDiscount($couponSvc, 11, 30.0, 1);
    }
}
