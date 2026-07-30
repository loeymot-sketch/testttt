<?php

namespace App\Rules;

use Illuminate\Contracts\Validation\Rule;

/**
 * PLAN_03 D-004 — Validation des items de commande
 * Rejeter les items sans item_id ou quantity valide
 */
class ValidJsonOrder implements Rule
{
    private string $message = '';

    /**
     * Create a new rule instance.
     *
     * @return void
     */
    public function __construct()
    {
    }

    /**
     * Determine if the validation rule passes.
     *
     * @param  string  $attribute
     * @param  mixed  $value
     * @return bool
     */
    public function passes($attribute, $value): bool
    {
        // [PLAN_03 D-004] Étape 1 : Vérifier que c'est un JSON valide
        if (!is_string($value)) {
            $this->message = 'Le champ ' . $attribute . ' doit être une chaîne JSON.';
            return false;
        }

        $decoded = json_decode($value, true);
        if (json_last_error() !== JSON_ERROR_NONE) {
            $this->message = 'Le champ ' . $attribute . ' contient du JSON invalide.';
            return false;
        }

        // [PLAN_03 D-004] Étape 2 : Vérifier que c'est un tableau non vide
        if (!is_array($decoded) || empty($decoded)) {
            $this->message = 'La commande doit contenir au moins un article.';
            return false;
        }

        // [Gap-Hunt 2026-05-25 A.2] DoS guard: cap order items at 50
        if (count($decoded) > 50) {
            $this->message = trans('validation.items_cap_exceeded');
            return false;
        }

        // [PLAN_03 D-004] Étape 3 : Vérifier chaque item
        foreach ($decoded as $index => $item) {
            // item_id obligatoire et numérique > 0
            if (!isset($item['item_id']) || !is_numeric($item['item_id']) || (int)$item['item_id'] <= 0) {
                $this->message = "L'article à l'index {$index} n'a pas d'item_id valide.";
                return false;
            }

            // quantity obligatoire, numérique, > 0
            if (!isset($item['quantity']) || !is_numeric($item['quantity']) || (int)$item['quantity'] <= 0) {
                $this->message = "L'article à l'index {$index} n'a pas de quantité valide.";
                return false;
            }

            // [ULTRA-AUDIT V2 2026-07-02 — P3 parité preview↔create] Le preview kiosk cape à
            // config('kiosk.max_item_qty')=20/ligne, mais la création (/order, /order/quote) n'avait
            // AUCUN plafond → un client bypassant le preview (device legacy/replay) créait des
            // quantités arbitraires (999999999). Plafond de sécurité GÉNÉREUX (999/ligne) : bloque
            // les valeurs absurdes/DoS/overflow SANS gêner une commande POS bulk réaliste (la règle
            // est partagée kiosk+POS ; on ne réplique donc pas le cap kiosk strict de 20 ici).
            if ((int) $item['quantity'] > 999) {
                $this->message = "La quantité de l'article à l'index {$index} dépasse le maximum autorisé (999).";
                return false;
            }

            // [P2-2] instruction longueur max 500 caractères
            if (isset($item['instruction']) && is_string($item['instruction']) && strlen($item['instruction']) > 500) {
                $this->message = "L'instruction de l'article à l'index {$index} dépasse 500 caractères.";
                return false;
            }

            // [SEC/NF525 HEAL 2026-07-30 · EXTRAS-QTY-CAP] Le cap 999 ci-dessus ne couvrait QUE
            // item.quantity : les sous-quantités des extras/variations/addons passaient BRUTES →
            // PricingService fait total += price × max(1,(int)qty) SANS plafond. Un token web pouvait
            // sceller un total absurde (ex. qty 9999999999999 sur un extra valide → ~5e12 €, ça rentre
            // dans decimal(19,6) sans overflow) → pollution NF525/Z si encaissé au comptoir. Même
            // plafond généreux (999/option) qu'au-dessus — aucun impact sur une commande légitime.
            foreach (['item_extras', 'item_variations', 'item_addons'] as $subKey) {
                if (isset($item[$subKey]) && is_array($item[$subKey])) {
                    foreach ($item[$subKey] as $sub) {
                        if (is_array($sub) && isset($sub['quantity']) && is_numeric($sub['quantity']) && (int) $sub['quantity'] > 999) {
                            $this->message = "Une quantité d'option de l'article à l'index {$index} dépasse le maximum autorisé (999).";
                            return false;
                        }
                    }
                }
            }
        }

        return true;
    }

    /**
     * Get the validation error message.
     *
     * @return string
     */
    public function message(): string
    {
        return $this->message ?: 'Le format de la commande est invalide.';
    }
}