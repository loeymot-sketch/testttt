<?php

namespace App\Services\Pricing;

use App\Models\Coupon;
use App\Models\User;
use App\Services\CouponService;
use Smartisan\Settings\Facades\Settings;

final class DiscountCalculator
{
    public function couponDiscount(CouponService $couponService, int $couponId, float $subtotal, int $customerUserId): float
    {
        if ($couponId <= 0) {
            return 0.0;
        }
        $coupon = $couponService->resolveCouponById($couponId, $subtotal, $customerUserId);

        return (float) $couponService->calculateDiscountAmount($coupon, $subtotal);
    }

    public function manualDiscount(float $requested, float $subtotal): float
    {
        if ($requested <= 0) {
            return 0.0;
        }

        return $requested <= $subtotal ? $requested : 0.0;
    }

    /**
     * Loyalty euro discount and points to deduct (no DB writes). Mirrors FrontendOrderService kiosk logic.
     *
     * @return  array{discount: float, points: int}
     */
    public function kioskLoyaltyRedemption(?Coupon $validatedCoupon, string $loyaltyCode, float $requestedDiscount, float $realSubtotal, User $lockedLoyaltyUser): array
    {
        if ($validatedCoupon instanceof Coupon && $loyaltyCode !== '' && $requestedDiscount > 0) {
            return ['discount' => 0.0, 'points' => 0];
        }
        if ($loyaltyCode === '' || $requestedDiscount <= 0) {
            return ['discount' => 0.0, 'points' => 0];
        }

        $rate = (int) Settings::group('loyalty_setup')->get('loyalty_points_for_1_euro_discount', 100);
        if ($rate <= 0) {
            $rate = 100;
        }
        $maxDiscount = min($requestedDiscount, $realSubtotal);
        // [LOY-SEM-02 heal 2026-06-01] Snap the redeemed discount to WHOLE points.
        // floor() so we never charge more points than the euro value granted, and
        // the granted discount is exactly the points' value (cent-clean). Mirrors
        // the POS "never fractional" guard (PosRedemptionService) — previously the
        // kiosk/web path used ceil() and returned the raw fractional $maxDiscount,
        // charging up to 1 extra point and persisting a sub-cent order.discount.
        $pointsRequired = (int) floor($maxDiscount * $rate);
        $maxDiscount = round($pointsRequired / $rate, 2);
        $minRedeemPoints = (int) Settings::group('loyalty_setup')->get('loyalty_min_redeem_points', 50);
        if ($minRedeemPoints < 0) {
            $minRedeemPoints = 0;
        }

        if ($pointsRequired <= 0 || $pointsRequired < $minRedeemPoints) {
            return ['discount' => 0.0, 'points' => 0];
        }
        if ($lockedLoyaltyUser->loyalty_points < $pointsRequired) {
            return ['discount' => 0.0, 'points' => 0];
        }

        return ['discount' => $maxDiscount, 'points' => $pointsRequired];
    }
}
