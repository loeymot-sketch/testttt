<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RATTACHER UN CLIENT À UNE VENTE DE CAISSE.
 *
 * ── LA PORTE ─────────────────────────────────────────────────────────────────────────────────
 * `permission:pos`, et non `pos.redeem-loyalty` : faire CUMULER n'est pas DÉPENSER. Un caissier
 * autorisé à encaisser doit pouvoir créditer les points d'un client sans détenir le droit de
 * puiser dans son solde — ce sont deux gestes de nature différente, et le second engage l'argent
 * du client.
 *
 * Sentinelle : tests/Feature/Pos/PosLoyaltyAttachTest.php
 */
class PosLoyaltyAttachRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos') ?? false;
    }

    public function rules(): array
    {
        return [
            'loyalty_code' => ['required', 'string', 'min:4', 'max:32'],
        ];
    }

    public function messages(): array
    {
        return [
            'loyalty_code.required' => 'Identifiez le client avant de rattacher la commande.',
        ];
    }
}
