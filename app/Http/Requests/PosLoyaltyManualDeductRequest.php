<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * RETIRER MANUELLEMENT DES POINTS — correction d'un sur-crédit, geste inverse.
 *
 * [2026-08-14 · propriétaire] a repéré un sur-crédit (bug de barème corrigé le jour même,
 * facteur 10) et a demandé un moyen de baisser un solde SANS annuler l'écriture déjà posée.
 *
 * `permission:pos`, comme `credit-manual` : le geste inverse mérite la même porte, pas une plus
 * stricte — un caissier qui peut créditer peut aussi corriger.
 *
 * Sentinelle : tests/Feature/Pos/PosLoyaltyManualDeductTest.php
 */
class PosLoyaltyManualDeductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos') ?? false;
    }

    public function rules(): array
    {
        return [
            'loyalty_code' => ['required', 'string', 'min:4', 'max:32'],
            'points'       => ['required', 'integer', 'min:1', 'max:100000'],
            'reason'       => ['nullable', 'string', 'max:255'],
            'order_id'     => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'loyalty_code.required' => 'Identifiez le client avant de retirer des points.',
            'points.required'       => 'Indiquez le nombre de points à retirer.',
            'points.min'            => 'Le nombre de points doit être positif.',
        ];
    }
}
