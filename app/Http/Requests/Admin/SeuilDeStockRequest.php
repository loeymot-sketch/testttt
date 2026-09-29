<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * [ONB-08 2026-08-28] Le seuil d'alerte d'une ligne de stock.
 *
 * `null` est volontairement accepte : il signifie « je ne surveille pas ce
 * produit ». Un seuil qu'on ne peut plus retirer ne serait pas un reglage.
 *
 * Le plafond a 100 000 n'est pas cosmetique : la colonne est un entier signe, et
 * un seuil absurde rendrait toute la carte « en alerte » en permanence, ce qui
 * revient exactement au meme que pas d'alerte du tout.
 */
class SeuilDeStockRequest extends FormRequest
{
    public function authorize(): bool
    {
        $utilisateur = $this->user();

        if ($utilisateur === null) {
            return false;
        }

        return $utilisateur->can('items_create') || $utilisateur->can('items_edit');
    }

    public function rules(): array
    {
        return [
            'threshold_low' => ['present', 'nullable', 'integer', 'min:0', 'max:100000'],
        ];
    }

    public function messages(): array
    {
        return [
            'threshold_low.present' => "Indiquez un seuil, ou laissez vide pour ne pas surveiller ce produit.",
            'threshold_low.integer' => 'Le seuil doit etre un nombre entier de pieces.',
            'threshold_low.min' => 'Un seuil ne peut pas etre negatif.',
            'threshold_low.max' => "Ce seuil est trop haut : tout serait en alerte en permanence, ce qui revient a n'avoir aucune alerte.",
        ];
    }
}
