<?php

namespace App\Rules;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\ItemWizardProfile;
use App\Models\Scopes\WizardProfileBranchScope;
use App\Support\Menu\IncludedMeatCount;
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
    public static function validateCollectionKeyedByItemIndex(array $orderItems, Closure $failItem, ?int $branchId = null): void
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
        // silently accepted. The active published composer profile is the
        // visibility contract when it exists; otherwise preserve the legacy
        // V1 required-attribute behavior.
        $requiredByItem = self::requiredAttributesByOrderedItem($orderItems, $branchId);
        // [VG-2026-10-21 R2-005] Viandes INCLUSES imposées par la règle du propriétaire (Méga/Terminator 2, Tacos M/L/XL 1/2/3).
        $includedMeatByItem = self::includedMeatByOrderedItem($orderItems);

        if ($allVarIds === [] && $requiredByItem === [] && $includedMeatByItem === []) {
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

            // [VG-2026-10-21 R2-005] Une composition doit porter EXACTEMENT les viandes incluses de l'article : la 2e viande
            // d'un Méga n'est pas facultative (décision du propriétaire), et une viande en trop passe par l'extra payant.
            if (isset($includedMeatByItem[$itemId])) {
                ['name' => $itemName, 'count' => $expected] = $includedMeatByItem[$itemId];
                $selected = self::selectedMeatCount($vars, $variationModels, $attributeModels, $itemId);
                if ($selected !== $expected) {
                    $failItem((int) $index, sprintf(
                        '« %s » comprend %d %s : choisis-en exactement %d (reçu %d). Une viande en plus se commande en supplément.',
                        $itemName,
                        $expected,
                        $expected > 1 ? 'viandes' : 'viande',
                        $expected,
                        $selected,
                    ));
                }
            }

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
    private static function requiredAttributesByOrderedItem(array $orderItems, ?int $branchId = null): array
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

        // A published composer profile is the product's active contract. An
        // inactive legacy step (for example “pain”) must not be resurrected
        // by the generic presence validator: the kiosk cannot select it, yet
        // the old rule rejected the quote at checkout. PricingService already
        // enforces the profile's active step min/max rules.
        // Only the nullable wizard-profile tenant filter must be lifted here:
        // the query re-applies the permitted global-or-requested-branch policy
        // below. Do not use `withoutGlobalScopes()` as it would silently widen
        // any future scope added to this model.
        $profiles = ItemWizardProfile::withoutGlobalScope(WizardProfileBranchScope::class)
            ->with(['steps' => fn ($query) => $query->where('is_active', true)->orderBy('position')])
            ->whereIn('item_id', $itemIds)
            ->where('is_published', true)
            ->where(function ($query) use ($branchId): void {
                $query->whereNull('branch_id_scope');
                if ($branchId !== null && $branchId > 0) {
                    $query->orWhere('branch_id_scope', $branchId);
                }
            })
            ->get()
            ->groupBy('item_id')
            ->map(function (Collection $candidates) use ($branchId): ItemWizardProfile {
                return $candidates->sortByDesc(function (ItemWizardProfile $profile) use ($branchId): int {
                    return $branchId !== null && (int) $profile->branch_id_scope === $branchId ? 2 : 1;
                })->first();
            });

        $out = [];
        foreach ($profiles as $itemId => $profile) {
            foreach ($profile->steps as $step) {
                if ($step->source_type !== 'item_attribute' || (int) $step->min_select < 1) {
                    continue;
                }
                $attributeId = (int) ($step->source_item_attribute_id ?: $step->source_ref);
                if ($attributeId < 1) {
                    continue;
                }
                $out[(int) $itemId][$attributeId] = [
                    'name' => (string) $step->label,
                    'min' => (int) $step->min_select,
                ];
            }
        }

        $legacyItemIds = array_values(array_diff($itemIds, $profiles->keys()->map(fn ($id) => (int) $id)->all()));
        if ($legacyItemIds === []) {
            return $out;
        }

        $rows = ItemVariation::query()
            ->whereIn('item_id', $legacyItemIds)
            ->where('status', Status::ACTIVE)
            ->get(['item_id', 'item_attribute_id']);
        if ($rows->isEmpty()) {
            return [];
        }

        $attrIds = $rows->pluck('item_attribute_id')->filter()->unique()->values()->all();
        $attrs = $attrIds === []
            ? collect()
            : ItemAttribute::query()->whereIn('id', $attrIds)->get()->keyBy('id');

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
     * [VG-2026-10-21 R2-005] Pour chaque article commandé PORTANT une règle du propriétaire (config/menu_meat_rules.php),
     * le nombre de viandes incluses à choisir — même calcul que la borne et le site (`viande_count`,
     * IncludedMeatCount::resolve sur les attributs « Viande N » portant une variation active). Un article sans règle
     * (bols, burgers, galettes) n'est pas concerné : son emplacement unique est déjà tenu par min_select.
     *
     * @param  array<int, mixed>  $orderItems
     * @return array<int, array{name: string, count: int}>  [item_id => [name, count]]
     */
    private static function includedMeatByOrderedItem(array $orderItems): array
    {
        $itemIds = [];
        foreach ($orderItems as $item) {
            if (is_array($item) && (int) ($item['item_id'] ?? 0) > 0) {
                $itemIds[(int) $item['item_id']] = true;
            }
        }
        if ($itemIds === []) {
            return [];
        }

        $items = Item::query()->with('category:id,name')->whereIn('id', array_keys($itemIds))->get(['id', 'name', 'item_category_id']);
        $ruled = $items->filter(fn (Item $item): bool => IncludedMeatCount::rule((string) $item->name, $item->category?->name) !== null);
        if ($ruled->isEmpty()) {
            return [];
        }

        $attributeNamesByItem = ItemVariation::query()
            ->join('item_attributes', 'item_attributes.id', '=', 'item_variations.item_attribute_id')
            ->whereIn('item_variations.item_id', $ruled->pluck('id')->all())
            ->where('item_variations.status', Status::ACTIVE)
            ->get(['item_variations.item_id', 'item_attributes.name as attribute_name'])
            ->groupBy('item_id');

        $out = [];
        foreach ($ruled as $item) {
            $count = IncludedMeatCount::resolve(
                (string) $item->name,
                $item->category?->name,
                collect($attributeNamesByItem->get($item->id, []))->pluck('attribute_name')
            );
            if ($count > 0) {
                $out[(int) $item->id] = ['name' => (string) $item->name, 'count' => $count];
            }
        }

        return $out;
    }

    /**
     * [VG-2026-10-21 R2-005] Viandes choisies sur une ligne : variations de l'article dont l'attribut est un emplacement
     * viande (« Viande N »). L'extra « Viande supplémentaire » (payant) n'est pas une viande incluse.
     *
     * @param  array<int, mixed>  $vars
     */
    private static function selectedMeatCount(array $vars, Collection $variationModels, Collection $attributeModels, int $itemId): int
    {
        $count = 0;
        foreach ($vars as $v) {
            if (! is_array($v)) {
                continue;
            }
            $model = $variationModels->get((int) ($v['id'] ?? 0));
            if (! $model instanceof ItemVariation || (int) $model->item_id !== $itemId) {
                continue;
            }
            $attribute = $attributeModels->get((int) $model->item_attribute_id);
            if ($attribute instanceof ItemAttribute && IncludedMeatCount::isMeatSlot((string) $attribute->name)) {
                $count += max(1, (int) ($v['quantity'] ?? 1));
            }
        }

        return $count;
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
