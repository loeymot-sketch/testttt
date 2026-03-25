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

            // [P2-2] instruction longueur max 500 caractères
            if (isset($item['instruction']) && is_string($item['instruction']) && strlen($item['instruction']) > 500) {
                $this->message = "L'instruction de l'article à l'index {$index} dépasse 500 caractères.";
                return false;
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