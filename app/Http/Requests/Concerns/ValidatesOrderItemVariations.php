<?php

namespace App\Http\Requests\Concerns;

use App\Rules\MultiVariationConstraint;
use Illuminate\Validation\Validator;

trait ValidatesOrderItemVariations
{
    /**
     * Après les règles de base : valide item_variations pour chaque ligne,
     * avec chargement groupé (pas de N+1 sur item_variations / item_attributes).
     */
    protected function validateOrderItemVariationsAfter(Validator $validator): void
    {
        $raw = $this->input('items');
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $items = is_array($decoded) ? $decoded : null;
        } elseif (is_array($raw)) {
            $items = $raw;
        } else {
            return;
        }

        if (! is_array($items) || $items === []) {
            return;
        }

        MultiVariationConstraint::validateCollectionKeyedByItemIndex(
            $items,
            function (int $index, string $message) use ($validator): void {
                $validator->errors()->add("items.{$index}.item_variations", $message);
            }
        );
    }
}
