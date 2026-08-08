<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * [P0 SÉCURITÉ 2026-08-08] Vitrine PUBLIQUE des promotions — SANS le code.
 *
 * `GET /api/frontend/coupon` est joignable ANONYMEMENT : le groupe ne porte que
 * `installed` + `apiKey` + `localization`, et cette clé d'API n'est pas un secret (elle est
 * publiée dans le meta HTML du site, le middleware le documente lui-même). Or la réponse
 * utilisait {@see CouponResource}, qui expose `code`.
 *
 * Conséquence MESURÉE en production le 2026-08-08 : un appel anonyme renvoyait les codes
 * NOMINATIFS à usage unique des tickets promo, avec le prénom du client dans le nom
 * (« Flyer Camille »). N'importe qui pouvait donc récolter tous les codes en circulation et
 * brûler celui d'une cliente avant elle — `max_uses_global = 1`, premier arrivé premier servi.
 *
 * Un code promo n'a AUCUNE raison d'être listé publiquement : le client le reçoit par son
 * canal (ticket imprimé, courriel) et le SAISIT. Le site n'appelle d'ailleurs jamais cette
 * liste — seulement `POST /coupon/coupon-checking` pour vérifier un code tapé (vérifié :
 * `api.js` ne référence que `coupon-checking`).
 *
 * Cette ressource ne sert donc que la vitrine : de quoi annoncer une promotion, jamais de quoi
 * l'utiliser. {@see CouponResource} reste inchangée pour l'ADMIN, qui a légitimement besoin
 * des codes.
 */
class PublicCouponResource extends JsonResource
{
    public function toArray($request)
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'discount' => $this->discount,
            'discount_type' => $this->discount_type,
            'minimum_order' => $this->minimum_order,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            // Volontairement ABSENTS : `code` (c'est la fuite), `limit_per_user` et
            // `max_uses_global` (ils renseignent un attaquant sur ce qui vaut la peine d'être
            // brûlé en premier).
        ];
    }
}
