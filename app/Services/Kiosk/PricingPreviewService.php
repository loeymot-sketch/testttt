<?php

namespace App\Services\Kiosk;

use App\Models\Coupon;
use App\Models\KioskPromo;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;

/**
 * Kiosk Design V1 — Phase 1.5
 *
 * Recalcul SSOT d'un panier SANS persistance. Consommé par :
 *  - Le wizard kiosk (affichage temps-réel du total avant POST /order).
 *  - L'upsell UI (impact d'un ajout).
 *  - La promo UI (montrer le discount calculé côté serveur).
 *
 * Invariants :
 *  - Aucune écriture DB.
 *  - Aucun event broadcast.
 *  - `branch_id` imposé par l'appelant (controller → KioskMachine).
 *  - Les `id` payload sont des hints UX ; les prix sont RELUS en DB.
 *  - Garde cross-item active (une variation doit appartenir à son item).
 *
 * Priorité de discount :
 *  1. `kiosk_promo_code` si matché → applied, source = 'kiosk_promo'.
 *  2. `coupon_code` si matché      → applied, source = 'coupon'.
 *  3. Aucun                         → discount = 0, source = null.
 */
final class PricingPreviewService
{
    public function __construct(
        private readonly PricingService $pricing = new PricingService(),
        private readonly CouponService $couponService = new CouponService(),
    ) {
    }

    /**
     * @param  array<int, array<string, mixed>>  $items  ex. [['item_id'=>1,'quantity'=>1,'item_variations'=>[...],'item_extras'=>[...]]]
     *
     * @return array{
     *   lines: array<int, array<string, mixed>>,
     *   subtotal: float,
     *   tax: float,
     *   discount: float,
     *   discount_source: ?string,
     *   total: float,
     *   currency: string
     * }
     */
    public function preview(
        int $branchId,
        array $items,
        ?int $customerUserId,
        ?string $couponCode,
        ?string $kioskPromoCode
    ): array {
        // Convert plain arrays → stdClass to match what PricingService expects
        // (FrontendOrderService passes decoded JSON via safeJsonDecode).
        $requestItems = array_map(
            fn (array $line) => $this->toObject($line),
            array_values($items)
        );

        // -- Étape 1 : priorité kiosk_promo local (branch-scoped) -----------
        $kioskPromo = null;
        if ($kioskPromoCode !== null && $kioskPromoCode !== '') {
            // On calcule d'abord le sous-total "brut" pour connaître la base de
            // comparaison vs min_cart et appliquer un % si besoin.
            $draft = $this->pricing->calculateOrder(
                PricingRequest::forKiosk(
                    orderId: 0,
                    branchId: $branchId,
                    requestItems: $requestItems,
                    couponId: 0,
                    customerUserId: $customerUserId ?? 0,
                    deliveryCharge: 0.0,
                ),
                $this->couponService
            );
            $kioskPromo = KioskPromo::findValid(
                $branchId,
                $kioskPromoCode,
                (float) $draft->subtotal
            );
            if ($kioskPromo) {
                $kioskDiscount = $kioskPromo->computeDiscount((float) $draft->subtotal);
                return $this->envelope(
                    lines: $this->projectLines($draft->lines),
                    subtotal: (float) $draft->subtotal,
                    tax: (float) $draft->totalTax,
                    discount: $kioskDiscount,
                    discountSource: 'kiosk_promo',
                    // recompute total with discount applied (PricingService skipped it)
                    total: round(max(0.0, (float) $draft->subtotal + (float) $draft->totalTax - $kioskDiscount), 2),
                );
            }
        }

        // -- Étape 2 : fallback coupon global (DB table `coupons`) ----------
        $couponId = 0;
        if ($couponCode !== null && $couponCode !== '') {
            $couponId = (int) (Coupon::query()->where('code', $couponCode)->value('id') ?? 0);
        }

        $result = $this->pricing->calculateOrder(
            PricingRequest::forKiosk(
                orderId: 0,
                branchId: $branchId,
                requestItems: $requestItems,
                couponId: $couponId,
                customerUserId: $customerUserId ?? 0,
                deliveryCharge: 0.0,
            ),
            $this->couponService
        );

        return $this->envelope(
            lines: $this->projectLines($result->lines),
            subtotal: (float) $result->subtotal,
            tax: (float) $result->totalTax,
            discount: (float) $result->discount,
            discountSource: ($couponId > 0 && $result->discount > 0) ? 'coupon' : null,
            total: (float) $result->total,
        );
    }

    /**
     * @param  \App\Services\Pricing\PricingLineResult[]  $lines
     */
    private function projectLines(array $lines): array
    {
        return array_map(fn ($line) => [
            'item_id'          => $line->itemId,
            'quantity'         => $line->quantity,
            'unit_price'       => (float) $line->unitItemPrice,
            'variations_total' => (float) $line->variationTotal,
            'extras_total'     => (float) $line->extraTotal,
            'addons_total'     => (float) $line->addonTotal,
            'line_subtotal'    => (float) $line->lineSubtotalExTax,
            'tax'              => (float) $line->taxAmount,
            'line_total'       => round((float) $line->lineSubtotalExTax + (float) $line->taxAmount, 2),
        ], $lines);
    }

    private function toObject(array $line): object
    {
        $obj = (object) [
            'item_id'         => (int) ($line['item_id'] ?? 0),
            'quantity'        => max(1, (int) ($line['quantity'] ?? 1)),
            'instruction'     => $line['instruction'] ?? null,
            'item_variations' => array_map(
                fn ($v) => (object) [
                    'id' => (int) ($v['id'] ?? 0),
                    'quantity' => max(1, (int) ($v['quantity'] ?? 1)),
                ],
                (array) ($line['item_variations'] ?? [])
            ),
            'item_extras'     => array_map(
                fn ($e) => (object) [
                    'id' => (int) ($e['id'] ?? 0),
                    'quantity' => max(1, (int) ($e['quantity'] ?? 1)),
                ],
                (array) ($line['item_extras'] ?? [])
            ),
            'item_addons'     => array_map(
                fn ($a) => (object) [
                    'id' => (int) ($a['id'] ?? 0),
                    'quantity' => max(1, (int) ($a['quantity'] ?? 1)),
                ],
                (array) ($line['item_addons'] ?? [])
            ),
        ];
        return $obj;
    }

    private function envelope(
        array $lines,
        float $subtotal,
        float $tax,
        float $discount,
        ?string $discountSource,
        float $total
    ): array {
        return [
            'lines'           => $lines,
            'subtotal'        => round($subtotal, 2),
            'tax'             => round($tax, 2),
            'discount'        => round($discount, 2),
            'discount_source' => $discountSource,
            'total'           => round($total, 2),
            'currency'        => (string) config('app.currency_symbol', '€'),
        ];
    }
}
