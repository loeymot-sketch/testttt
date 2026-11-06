<?php

namespace App\Http\Resources;

use App\Libraries\AppLibrary;
use App\Models\Coupon;
use App\Services\CouponService;
use Illuminate\Http\Resources\Json\JsonResource;

class CouponCheckResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        $amount = $this->amount($request);

        return [
            'id'                => $this->id,
            'code'              => $this->code,
            'discount'          => $amount,
            "flat_discount"     => AppLibrary::flatAmountFormat($amount),
            "convert_discount"  => AppLibrary::convertAmountFormat($amount),
            "currency_discount" => AppLibrary::currencyAmountFormat($amount),
            // [TICKET PROMO 2026-11-03] Coupon « livraison offerte » : le site doit afficher
            // « Livraison offerte » (et non « −0,00 € ») et savoir jusqu'où elle s'applique.
            'free_delivery'        => (bool) ($this->free_delivery ?? false),
            'free_delivery_max_km' => ($this->free_delivery ?? false)
                ? (float) ($this->free_delivery_max_km ?? config('delivery.free_delivery_coupon_max_km', 7))
                : null,
            'minimum_order'        => (float) ($this->minimum_order ?? 0),
        ];
    }

    /**
     * Montant de la remise affiché au client.
     *
     * [FLYER PROMO 2026-08-07 — P0 mesuré sur la production réelle]
     * Cette méthode réimplémentait le calcul et DIVERGEAIT : elle plafonnait
     * par `maximum_discount` sans vérifier que ce plafond est renseigné, alors
     * que `maximum_discount = 0` signifie « pas de plafond » partout ailleurs
     * (`CouponService::calculateDiscountAmount` et `KioskPromoService` testent
     * tous deux `> 0`). Un coupon −10 % sur 25 € renvoyait donc 0,00 €.
     *
     * Conséquence : le client saisissait son code, lisait « −0,00 € », en
     * concluait que le code ne marchait pas et abandonnait — alors que la
     * COMMANDE aurait bien appliqué 2,50 €, son chemin passant par le service.
     * L'écran mentait sur ce que la caisse allait faire.
     *
     * Corrigé en SUPPRIMANT la duplication plutôt qu'en la réparant : la règle
     * n'a qu'un seul propriétaire, le service. Trois implémentations de la même
     * règle finissent toujours par diverger, et c'est celle que le client voit
     * qui avait divergé.
     */
    public function amount($request)
    {
        $subtotal = (float) $request->input('total', 0);

        // `$this->resource` est le modèle Coupon validé par le service.
        $coupon = $this->resource;
        if (! $coupon instanceof Coupon) {
            return 0.0;
        }

        return app(CouponService::class)->calculateDiscountAmount($coupon, $subtotal);
    }
}
