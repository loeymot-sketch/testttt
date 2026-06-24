<?php

namespace App\Rules;

use App\Enums\Status;
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

        // [HEAL 2026-06-24 / e2e sweep wh6f2bepp] Reject a REQUIRED attribute
        // (min_select>=1) that is wholly OMITTED from the payload. The
        // per-attribute min check below only inspects attributes PRESENT in the
        // payload, so an order missing a required modifier entirely (a tacos
        // with no meat, a sandwich with no bread, a bol with no sauce) was
        // silently accepted. All required attributes in the V1 menu are visible
        // on both pos & kiosk, so enforcing presence cannot reject a valid order
        // (the wizard always defaults a value for each required attribute).
        $requiredByItem = self::requiredAttributesByOrderedItem($orderItems);

        if ($allVarIds === [] && $requiredByItem === []) {
            return;
        }

        $variationModels = $allVarIds === []
            ? collect()
            : ItemVariation::query()->whereIn('id', $allVarIds)->get()->keyBy('id');
        $attrIds = $variationModels->pluck('item_attribute_id')->unique()->values()->all();
        $attributeModels = $attrIds === []
            ? collect()
            : ItemAttribute::query()->whereIn('id', $attrIds)->get()->keyBy('id');

        foreach ($orderItems as $index => $item) {
            if (! is_array($item)) {
                continue;
            }
            $vars = $item['item_variations'] ?? [];
            $vars = is_array($vars) ? $vars : [];

            if ($vars !== []) {
                self::validateVariationsPayloadAgainstMaps(
                    $vars,
                    $variationModels,
                    $attributeModels,
                    function (string $message) use ($failItem, $index): void {
                        $failItem((int) $index, $message);
                    }
                );
            }

            $itemId = (int) ($item['item_id'] ?? 0);
            $required = $requiredByItem[$itemId] ?? [];
            if ($required === []) {
                continue;
            }
            $presentAttrIds = self::presentAttributeIds($vars, $variationModels, $itemId);
            foreach ($required as $attrId => $req) {
                if (! in_array($attrId, $presentAttrIds, true)) {
                    $failItem((int) $index, __('validation.multi_variation.min', [
                        'attribute' => $req['name'],
                        'min' => $req['min'],
                        'actual' => 0,
                    ]));
                }
            }
        }
    }

    /**
     * [HEAL 2026-06-24] Map each ordered item_id to its REQUIRED attributes
     * (min_select>=1), derived from the item's ACTIVE variations. Lets the
     * caller detect a wholly-omitted required attribute, which the
     * present-attribute loop cannot see.
     *
     * @param  array<int, mixed>  $orderItems
     * @return array<int, array<int, array{name: string, min: int}>>  [item_id => [attribute_id => [name, min]]]
     */
    private static function requiredAttributesByOrderedItem(array $orderItems): array
    {
        $itemIds = [];
        foreach ($orderItems as $item) {
            if (is_array($item) && (int) ($item['item_id'] ?? 0) > 0) {
                $itemIds[] = (int) $item['item_id'];
            }
        }
        $itemIds = array_values(array_unique($itemIds));
        if ($itemIds === []) {
            return [];
        }

        $rows = ItemVariation::query()
            ->whereIn('item_id', $itemIds)
            ->where('status', Status::ACTIVE)
            ->get(['item_id', 'item_attribute_id']);
        if ($rows->isEmpty()) {
            return [];
        }

        $attrIds = $rows->pluck('item_attribute_id')->filter()->unique()->values()->all();
        $attrs = $attrIds === []
            ? collect()
            : ItemAttribute::query()->whereIn('id', $attrIds)->get()->keyBy('id');

        $out = [];
        foreach ($rows as $row) {
            $attrId = (int) $row->item_attribute_id;
            $attr = $attrs->get($attrId);
            if (! $attr instanceof ItemAttribute) {
                continue;
            }
            $min = (int) ($attr->min_select ?? 0);
            if ($min < 1) {
                continue;
            }
            $out[(int) $row->item_id][$attrId] = [
                'name' => (string) $attr->name,
                'min' => $min,
            ];
        }

        return $out;
    }

    /**
     * Attribute ids present in the payload AND owned by the item.
     *
     * @param  array<int, mixed>  $vars
     * @return list<int>
     */
    private static function presentAttributeIds(array $vars, Collection $variationModels, int $itemId): array
    {
        $ids = [];
        foreach ($vars as $v) {
            if (! is_array($v)) {
                continue;
            }
            $model = $variationModels->get((int) ($v['id'] ?? 0));
            if ($model instanceof ItemVariation && (int) $model->item_id === $itemId) {
                $ids[] = (int) $model->item_attribute_id;
            }
        }

        return array_values(array_unique($ids));
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
