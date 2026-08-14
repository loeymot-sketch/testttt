<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * CRÉDITER MANUELLEMENT LE COMPTE D'UN CLIENT — un montant en euros, pas lié à une vente.
 *
 * ── LA PORTE ─────────────────────────────────────────────────────────────────────────────────
 * `permission:pos`, comme `attach-loyalty` : créditer n'est pas débiter. Un caissier autorisé à
 * encaisser peut compenser un client (geste commercial, points oubliés une commande précédente,
 * dédommagement) sans détenir le droit de puiser dans un solde.
 *
 * Sentinelle : tests/Feature/Pos/PosLoyaltyManualCreditTest.php
 */
class PosLoyaltyManualCreditRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()?->can('pos') ?? false;
    }

    public function rules(): array
    {
        return [
            'loyalty_code' => ['required', 'string', 'min:4', 'max:32'],
            // Plafonné à 200€ (defense) : un crédit manuel plus important est un geste de
            // responsable, pas un réflexe de comptoir — cette route reste ouverte à
            // `permission:pos` volontairement large, le plafond est le filet.
            'euros'        => ['required', 'numeric', 'min:0.01', 'max:200'],
            'reason'       => ['nullable', 'string', 'max:255'],
            // Facultatif — trace « pour quelle vente » sans jamais modifier cette commande.
            'order_id'     => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function messages(): array
    {
        return [
            'loyalty_code.required' => 'Identifiez le client avant de créditer des points.',
            'euros.required'        => 'Indiquez le montant à créditer.',
            'euros.min'             => 'Le montant doit être positif.',
        ];
    }
}
