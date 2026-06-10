<?php

namespace App\Services\Kiosk;

use App\Models\Coupon;
use App\Models\KioskPromo;
use Carbon\Carbon;

/**
 * Kiosk Design V1 — Phase 1.6
 *
 * Validation de codes promo côté kiosk. Priorité :
 *  1. `kiosk_promos` scoped par branche (table dédiée, Phase 1.1).
 *  2. Fallback `coupons` globaux (table existante, inchangée).
 *
 * Aucune persistance : c'est une validation de lecture qui renvoie le
 * montant de discount à appliquer. Le discount n'est JAMAIS définitif avant
 * `POST /order`, qui ré-applique le calcul côté FrontendOrderService (SSOT).
 */
final class KioskPromoService
{
    public const SOURCE_KIOSK_PROMO = 'kiosk_promo';
    public const SOURCE_COUPON      = 'coupon';

    /**
     * @return array{
     *   valid: bool,
     *   code: ?string,
     *   source: ?string,
     *   type: ?string,
     *   value: ?float,
     *   discount_amount: float,
     *   message: ?string
     * }
     */
    public function validate(int $branchId, string $code, float $cartTotal, ?Carbon $at = null): array
    {
        $at ??= now();
        $code = trim($code);

        // [BORNE-PROMO-01 / LOT D] Dormancy gate — see config/kiosk.php
        // `promos_redeemable`. While the order path cannot bill the discount
        // (OrderQuoteService:416 copies kiosk_promo_code as non-financial
        // metadata only), validating a code here re-creates the phantom
        // −5,00 € display the customer never receives.
        if (! config('kiosk.promos_redeemable')) {
            return $this->fail('Les codes promo ne sont pas disponibles pour le moment.');
        }

        if ($code === '') {
            return $this->fail('Code vide.');
        }

        // -- 1. Kiosk promo branch-scoped ------------------------------------
        $kioskPromo = KioskPromo::findValid($branchId, $code, $cartTotal, $at);
        if ($kioskPromo) {
            return [
                'valid'           => true,
                'code'            => $kioskPromo->code,
                'source'          => self::SOURCE_KIOSK_PROMO,
                'type'            => $kioskPromo->type,
                'value'           => (float) $kioskPromo->value,
                'discount_amount' => $kioskPromo->computeDiscount($cartTotal),
                'message'         => null,
            ];
        }

        // -- 2. Fallback coupon global --------------------------------------
        /** @var ?Coupon $coupon */
        $coupon = Coupon::query()->where('code', $code)->first();
        if (!$coupon) {
            return $this->fail('Code invalide ou expiré.');
        }

        if ($coupon->start_date && Carbon::parse($coupon->start_date)->gt($at)) {
            return $this->fail('Code non encore actif.');
        }
        if ($coupon->end_date && Carbon::parse($coupon->end_date)->lt($at)) {
            return $this->fail('Code expiré.');
        }
        if ((float) ($coupon->minimum_order ?? 0) > $cartTotal) {
            return $this->fail('Montant minimum non atteint.');
        }

        $discount = $this->computeCouponDiscount($coupon, $cartTotal);

        return [
            'valid'           => true,
            'code'            => $coupon->code,
            'source'          => self::SOURCE_COUPON,
            'type'            => $coupon->discount_type == 1 ? 'percent' : 'amount',
            'value'           => (float) $coupon->discount,
            'discount_amount' => $discount,
            'message'         => null,
        ];
    }

    /**
     * Calcule le discount d'un coupon global (type 1 = percent, 2 = amount
     * par convention projet), plafonné par `maximum_discount` si défini.
     */
    private function computeCouponDiscount(Coupon $coupon, float $cartTotal): float
    {
        if ($cartTotal <= 0) return 0.0;

        $value = (float) ($coupon->discount ?? 0);
        $discount = ((int) ($coupon->discount_type ?? 0) == 1)
            ? $cartTotal * ($value / 100.0)
            : $value;

        $maxCap = (float) ($coupon->maximum_discount ?? 0);
        if ($maxCap > 0) {
            $discount = min($discount, $maxCap);
        }

        return round(min(max(0.0, $discount), $cartTotal), 2);
    }

    private function fail(string $message): array
    {
        return [
            'valid'           => false,
            'code'            => null,
            'source'          => null,
            'type'            => null,
            'value'           => null,
            'discount_amount' => 0.0,
            'message'         => $message,
        ];
    }
}
