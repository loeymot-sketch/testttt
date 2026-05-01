<?php

namespace App\Services\Pricing;

use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use Illuminate\Support\Collection;

/**
 * [T07] Builds the immutable composition_snapshot persisted alongside each OrderItem.
 *
 * Used by OrderService and FrontendOrderService at order creation time.
 *
 * NF525 immutability contract: this snapshot must NEVER be re-written after the
 * initial insert. Reprint flows MUST read from this snapshot, never recompute.
 */
final class CompositionSnapshotBuilder
{
    public const SCHEMA_VERSION = 1;

    /**
     * @param  object  $item  Decoded payload line (stdClass) with item_variations / item_extras / item_addons.
     * @param  Collection  $dbVariations  Keyed by id (ItemVariation models).
     * @param  Collection  $dbExtras  Keyed by id (ItemExtra models).
     * @param  Collection|null  $dbAttributes  Keyed by id (ItemAttribute models). If null, will be loaded.
     * @param  Collection|null  $dbAddons  Keyed by id (ItemAddon models). If null, will be loaded.
     * @return array snapshot ready to be JSON-encoded for mass insert
     */
    public function build(
        object $item,
        Collection $dbVariations,
        Collection $dbExtras,
        ?Collection $dbAttributes = null,
        ?Collection $dbAddons = null
    ): array
    {
        $lines = [];
        $extras = [];
        $addons = [];

        if (isset($item->item_variations) && is_array($item->item_variations)) {
            $attrIds = [];
            foreach ($item->item_variations as $v) {
                $varId = $v->id ?? null;
                if (! $varId) {
                    continue;
                }
                $dbVar = $dbVariations[$varId] ?? null;
                if (! $dbVar) {
                    continue;
                }
                $attrIds[] = (int) $dbVar->item_attribute_id;
            }
            $attrIds = array_values(array_unique(array_filter($attrIds)));
            $attrs = $dbAttributes ?? ($attrIds !== []
                ? ItemAttribute::query()->whereIn('id', $attrIds)->get()->keyBy('id')
                : collect());

            foreach ($item->item_variations as $v) {
                $varId = $v->id ?? null;
                if (! $varId) {
                    continue;
                }
                $dbVar = $dbVariations[$varId] ?? null;
                if (! $dbVar) {
                    continue;
                }
                $qty = max(1, (int) ($v->quantity ?? 1));
                $unitPrice = (float) $dbVar->price;
                $attr = $attrs[(int) $dbVar->item_attribute_id] ?? null;
                $lines[] = [
                    'variation_id'   => (int) $dbVar->id,
                    'attribute_id'   => (int) $dbVar->item_attribute_id,
                    'attribute_name' => $attr?->name,
                    'variation_name' => (string) $dbVar->name,
                    'quantity'       => $qty,
                    'unit_price'     => round($unitPrice, 6),
                    'line_total'     => round($unitPrice * $qty, 6),
                ];
            }
        }

        if (isset($item->item_extras) && is_array($item->item_extras)) {
            foreach ($item->item_extras as $e) {
                $extId = $e->id ?? null;
                if (! $extId) {
                    continue;
                }
                $dbExt = $dbExtras[$extId] ?? null;
                if (! $dbExt) {
                    continue;
                }
                $qty = max(1, (int) ($e->quantity ?? 1));
                $unitPrice = (float) $dbExt->price;
                $extras[] = [
                    'extra_id'   => (int) $dbExt->id,
                    'extra_name' => (string) $dbExt->name,
                    'quantity'   => $qty,
                    'unit_price' => round($unitPrice, 6),
                    'line_total' => round($unitPrice * $qty, 6),
                ];
            }
        }

        if (isset($item->item_addons) && is_array($item->item_addons)) {
            $addonIds = [];
            foreach ($item->item_addons as $addon) {
                $addonId = $addon->id ?? null;
                if ($addonId) {
                    $addonIds[] = (int) $addonId;
                }
            }

            $resolvedAddons = $dbAddons ?? ($addonIds !== []
                ? ItemAddon::query()->with('addonItem')->whereIn('id', array_values(array_unique($addonIds)))->get()->keyBy('id')
                : collect());

            foreach ($item->item_addons as $addon) {
                $addonId = $addon->id ?? null;
                if (! $addonId) {
                    continue;
                }
                $dbAddon = $resolvedAddons[$addonId] ?? null;
                if (! $dbAddon) {
                    continue;
                }
                $qty = max(1, (int) ($addon->quantity ?? 1));
                $unitPrice = (float) ($dbAddon->addonItem?->price ?? 0);
                $addons[] = [
                    'addon_id'      => (int) $dbAddon->id,
                    'addon_item_id' => (int) $dbAddon->addon_item_id,
                    'addon_name'    => (string) ($dbAddon->addonItem?->name ?? ''),
                    'role'          => $dbAddon->role,
                    'quantity'      => $qty,
                    'unit_price'    => round($unitPrice, 6),
                    'line_total'    => round($unitPrice * $qty, 6),
                ];
            }
        }

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'captured_at'    => now()->toIso8601String(),
            'lines'          => $lines,
            'extras'         => $extras,
            'addons'         => $addons,
        ];
    }
}
