<?php

namespace App\Http\Requests\Kiosk;

use App\Http\Requests\Concerns\ValidatesOrderItemVariations;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Kiosk Design V1 — Phase 1.5
 *
 * Validation payload `POST /api/frontend/pricing/preview`.
 *
 * Invariants :
 *  - Aucun prix/total accepté du client. Seul `items` (id + quantity +
 *    variations.id + extras.id + instruction) est autorisé.
 *  - `branch_id` JAMAIS dans le payload : résolu côté controller via
 *    `KioskMachine::where(user_id=Auth::id())`.
 */
class PricingPreviewRequest extends FormRequest
{
    use ValidatesOrderItemVariations;

    public function authorize(): bool
    {
        $user = $this->user();
        return $user && $user->tokenCan('kiosk:order');
    }

    public function rules(): array
    {
        $maxItems = (int) config('kiosk.max_item_qty', 20);

        return [
            // [rush-100 WA-R1-05/06 heal 2026-05-13] Relax `items` required→nullable.
            // Kiosk composer-step entry (Bol Curry, Petite Frites first paint) fires
            // a pricing/preview before the user has selected anything; the previous
            // `required + min:1` rule rejected this with a 422 → "Tarif rafraîchi
            // localement" yellow toast + NF525 pricing SSOT bypass risk. Service
            // returns 0-total cleanly for empty items. Backend remains gap-free
            // (no negative impact) and adds an explicit early-exit for empty input.
            'items'                               => ['nullable', 'array', 'max:100'],
            'items.*.item_id'                     => ['required', 'integer', 'min:1'],
            'items.*.quantity'                    => ['required', 'integer', 'min:1', "max:{$maxItems}"],
            'items.*.instruction'                 => ['nullable', 'string', 'max:255'],
            'items.*.item_variations'             => ['nullable', 'array', 'max:20'],
            'items.*.item_variations.*.id'        => ['required_with:items.*.item_variations', 'integer', 'min:1'],
            'items.*.item_variations.*.quantity'    => ['sometimes', 'nullable', 'integer', 'min:1'],
            'items.*.item_extras'                 => ['nullable', 'array', 'max:30'],
            'items.*.item_extras.*.id'            => ['required_with:items.*.item_extras', 'integer', 'min:1'],
            'items.*.item_extras.*.quantity'      => ['sometimes', 'nullable', 'integer', 'min:1'],
            'items.*.item_addons'                 => ['nullable', 'array', 'max:20'],
            'items.*.item_addons.*.id'            => ['required_with:items.*.item_addons', 'integer', 'min:1'],
            'items.*.item_addons.*.quantity'      => ['sometimes', 'nullable', 'integer', 'min:1'],
            // [test-e2e/borne E-001 fix 2026-05-10] Addon role hint —
            // 'drink', 'menu_full', 'menu_frites', 'menu_boisson' (kiosk),
            // 'menu_component', 'side', 'dessert', 'upsell' (legacy).
            // Backend uses the `menu_*` prefixes to apply the kiosk menu
            // ratio (see PricingService::menuRoleAdjustedAddonPrice).
            'items.*.item_addons.*.role'          => ['sometimes', 'nullable', 'string', 'max:32'],

            'coupon_code'                         => ['nullable', 'string', 'min:3', 'max:64'],
            'kiosk_promo_code'                    => ['nullable', 'string', 'min:3', 'max:64'],
        ];
    }

    public function withValidator($validator): void
    {
        $validator->after(function (Validator $validator): void {
            $this->validateOrderItemVariationsAfter($validator);
        });
    }

    /**
     * Refuse TOUTE clé non-listée ci-dessus (def. whitelist strict).
     * Protège contre les tentatives d'injection `branch_id`, `price`, `total`…
     */
    public function validated($key = null, $default = null): array
    {
        $data = parent::validated($key, $default);

        // Extra-paranoid: strip unexpected keys even s'ils passent rules()
        // (Laravel les supprime déjà, mais on garde-fou).
        $allowed = ['items', 'coupon_code', 'kiosk_promo_code'];
        return array_intersect_key($data, array_flip($allowed));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'status'  => false,
            'message' => 'Payload invalide.',
            'errors'  => $validator->errors(),
        ], 422));
    }
}
