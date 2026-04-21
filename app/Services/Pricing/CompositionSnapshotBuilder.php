<?php

namespace App\Services\Pricing;

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
     * @param  object  $item  Decoded payload line (stdClass) with item_variations / item_extras.
     * @param  Collection  $dbVariations  Keyed by id (ItemVariation models).
     * @param  Collection  $dbExtras  Keyed by id (ItemExtra models).
     * @param  Collection|null  $dbAttributes  Keyed by id (ItemAttribute models). If null, will be loaded.
     * @return array snapshot ready to be JSON-encoded for mass insert
     */
    public function build(object $item, Collection $dbVariations, Collection $dbExtras, ?Collection $dbAttributes = null): array
    {
        $lines = [];
        $extras = [];

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

        return [
            'schema_version' => self::SCHEMA_VERSION,
            'captured_at'    => now()->toIso8601String(),
            'lines'          => $lines,
            'extras'         => $extras,
        ];
    }
}
