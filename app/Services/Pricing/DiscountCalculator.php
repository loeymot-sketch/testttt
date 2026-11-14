<?php

namespace App\Services\Pricing;

use App\Models\Coupon;
use App\Models\User;
use App\Services\CouponService;
use Smartisan\Settings\Facades\Settings;

final class DiscountCalculator
{
    // [LOCK_COUPON_SURFACE_BRANCHE_AU_PAIEMENT_2026-11-05] Branche et surface transmises : un code limité à « web » ou à une
    // branche était refusé au paiement (isUsableNow(null, null)) alors que « Appliquer » l'acceptait.
    public function couponDiscount(CouponService $couponService, int $couponId, float $subtotal, int $customerUserId, ?int $branchId = null, ?string $surface = null): float
    {
        if ($couponId <= 0) {
            return 0.0;
        }
        $coupon = $couponService->resolveCouponById($couponId, $subtotal, $customerUserId, $branchId, $surface);

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
    public function kioskLoyaltyRedemption(?Coupon $validatedCoupon, string $loyaltyCode, float $requestedDiscount, float $realSubtotal, User $lockedLoyaltyUser, bool $skipBalanceGate = false): array
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
        // [RED-1 2026-08-04 · LOCK_FRONTENDORDER_REDEEM_REORDER] La barrière de solde ne s'applique
        // qu'au DÉBIT FRAIS. Le RATTACHEMENT d'un pré-rachat déjà débité doit obtenir la valeur de
        // remise SANS re-tester le solde (déjà à 0 après « j'utilise tous mes points ») → skipBalanceGate.
        // [DÉCISION PROPRIÉTAIRE 2026-11-06 · relecture adverse P2-2] Le devis contrôle les points DÉPENSABLES, comme la
        // création : sinon la borne annonçait une remise que la commande sautait (409 « le total a changé »).
        if (! $skipBalanceGate && \App\Services\LoyaltyService::pointsDepensables((int) $lockedLoyaltyUser->id, (int) $lockedLoyaltyUser->loyalty_points) < $pointsRequired) {
            return ['discount' => 0.0, 'points' => 0];
        }

        return ['discount' => $maxDiscount, 'points' => $pointsRequired];
    }
}
