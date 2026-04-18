<?php

namespace App\Http\Requests\Kiosk;

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
    public function authorize(): bool
    {
        $user = $this->user();
        return $user && $user->tokenCan('kiosk:order');
    }

    public function rules(): array
    {
        $maxItems = (int) config('kiosk.max_item_qty', 20);

        return [
            'items'                               => ['required', 'array', 'min:1', 'max:100'],
            'items.*.item_id'                     => ['required', 'integer', 'min:1'],
            'items.*.quantity'                    => ['required', 'integer', 'min:1', "max:{$maxItems}"],
            'items.*.instruction'                 => ['nullable', 'string', 'max:255'],
            'items.*.item_variations'             => ['nullable', 'array', 'max:20'],
            'items.*.item_variations.*.id'        => ['required_with:items.*.item_variations', 'integer', 'min:1'],
            'items.*.item_extras'                 => ['nullable', 'array', 'max:30'],
            'items.*.item_extras.*.id'            => ['required_with:items.*.item_extras', 'integer', 'min:1'],

            'coupon_code'                         => ['nullable', 'string', 'min:3', 'max:64'],
            'kiosk_promo_code'                    => ['nullable', 'string', 'min:3', 'max:64'],
        ];
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
