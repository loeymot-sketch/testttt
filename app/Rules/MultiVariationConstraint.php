<?php

namespace App\Rules;

use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use Closure;
use Illuminate\Support\Collection;

/**
 * V14 T06 — Valide min_select / max_select / allow_repeat pour un payload
 * `item_variations` au format SSOT [{id, quantity?}].
 *
 * Laravel 9 : pas d'interface ValidationRule (L10+). La validation est déclenchée
 * via {@see self::validateCollectionKeyedByItemIndex()} dans un hook `after` FormRequest.
 *
 * Pour plusieurs lignes de commande sans N+1, utiliser
 * {@see self::validateCollectionKeyedByItemIndex()} depuis un hook `after`.
 */
class MultiVariationConstraint
{
    /**
     * @param  array<int, mixed>  $orderItems  Liste d'items (chacun peut contenir item_variations)
     * @param  Closure(int $itemIndex, string $message): void  $failItem
     */
    public static function validateCollectionKeyedByItemIndex(array $orderItems, Closure $failItem): void
    {
        $allVarIds = [];
        foreach ($orderItems as $item) {
            if (! is_array($item)) {
                continue;
            }
            $vars = $item['item_variations'] ?? null;
            if (! is_array($vars) || $vars === []) {
                continue;
            }
            foreach ($vars as $v) {
                if (! is_array($v)) {
                    continue;
                }
                $id = (int) ($v['id'] ?? 0);
                if ($id > 0) {
                    $allVarIds[] = $id;
                }
            }
        }

        $allVarIds = array_values(array_unique($allVarIds));
        if ($allVarIds === []) {
            return;
        }

        $variationModels = ItemVariation::query()->whereIn('id', $allVarIds)->get()->keyBy('id');
        $attrIds = $variationModels->pluck('item_attribute_id')->unique()->values()->all();
        $attributeModels = $attrIds === []
            ? collect()
            : ItemAttribute::query()->whereIn('id', $attrIds)->get()->keyBy('id');

        foreach ($orderItems as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $vars = $item['item_variations'] ?? null;
            if (! is_array($vars) || $vars === []) {
                continue;
            }
            self::validateVariationsPayloadAgainstMaps(
                $vars,
                $variationModels,
                $attributeModels,
                function (string $message) use ($failItem, $index): void {
                    $failItem((int) $index, $message);
                }
            );
        }
    }

    /**
     * @param  Collection<int, ItemVariation>|array<int, ItemVariation>  $variations  keyed by variation id
     * @param  Collection<int, ItemAttribute>|array<int, ItemAttribute>  $attributes  keyed by attribute id
     * @param  Closure(string): void  $fail
     */
    private static function validateVariationsPayloadAgainstMaps(
        array $value,
        Collection|array $variations,
        Collection|array $attributes,
        Closure $fail
    ): void {
        $byAttr = [];
        foreach ($value as $v) {
            if (! is_array($v)) {
                continue;
            }
            $varId = (int) ($v['id'] ?? 0);
            $qty = max(1, (int) ($v['quantity'] ?? 1));
            $var = $variations instanceof Collection
                ? $variations->get($varId)
                : ($variations[$varId] ?? null);
            if (! $var instanceof ItemVariation) {
                continue;
            }
            $attrId = (int) $var->item_attribute_id;
            $byAttr[$attrId][$varId] = ($byAttr[$attrId][$varId] ?? 0) + $qty;
        }

        foreach ($byAttr as $attrId => $quantities) {
            $attr = $attributes instanceof Collection
                ? $attributes->get($attrId)
                : ($attributes[$attrId] ?? null);
            if (! $attr instanceof ItemAttribute) {
                continue;
            }
            $totalQty = array_sum($quantities);
            $min = (int) ($attr->min_select ?? 0);
            $max = (int) ($attr->max_select ?? 1);
            $allowRepeat = (bool) ($attr->allow_repeat ?? false);

            if ($min > 0 && $totalQty < $min) {
                $fail(__('validation.multi_variation.min', [
                    'attribute' => $attr->name,
                    'min' => $min,
                    'actual' => $totalQty,
                ]));
            }
            if ($max > 0 && $totalQty > $max) {
                $fail(__('validation.multi_variation.max', [
                    'attribute' => $attr->name,
                    'max' => $max,
                    'actual' => $totalQty,
                ]));
            }
            if (! $allowRepeat) {
                foreach ($quantities as $qty) {
                    if ($qty > 1) {
                        $fail(__('validation.multi_variation.no_repeat', [
                            'attribute' => $attr->name,
                        ]));
                    }
                }
            }
        }
    }
}
