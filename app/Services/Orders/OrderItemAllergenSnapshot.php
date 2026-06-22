<?php

namespace App\Services\Orders;

use App\Models\Item;
use App\Models\ItemExtra;
use App\Services\AllergenService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Stateless helper that hydrates the `allergens_snapshot` column on
 * `order_items` insert rows before they hit `OrderItem::insert(...)`.
 *
 * Mirrors (read-only) the logic already in use by
 * `FrontendOrderService::hydrateAllergenSnapshots` (Track A, P9.5) so POS
 * orders created via `OrderService::posOrderStore` also get an immutable
 * allergen snapshot persisted at order-time — NF525 requirement for
 * fiscal traceability (article sold = attributes sold at that instant).
 *
 * Introduced by POS-9.4.BL.1.
 */
final class OrderItemAllergenSnapshot
{
    /**
     * @param  array<int, array<string, mixed>>  $itemsArray
     * @return array<int, array<string, mixed>>
     */
    public static function hydrate(array $itemsArray): array
    {
        if ($itemsArray === []) {
            return $itemsArray;
        }

        $itemIds = collect($itemsArray)
            ->pluck('item_id')
            ->filter()
            ->unique()
            ->all();

        if ($itemIds === []) {
            return $itemsArray;
        }

        $itemsById = Item::query()
            ->select('id', 'allergen_flags')
            ->with(['allergens' => function ($query): void {
                $query->select('allergens.id', 'code')->orderBy('sort');
            }])
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        $extrasAllergensById = self::resolveExtraAllergens($itemsArray);

        foreach ($itemsArray as $index => $row) {
            $base = self::resolveSnapshot($itemsById->get((int) ($row['item_id'] ?? 0)));
            $extraCodes = self::extractExtraAllergens($row, $extrasAllergensById);

            $merged = collect($base)
                ->merge($extraCodes)
                ->filter(fn ($code): bool => is_string($code) && $code !== '')
                ->unique()
                ->values()
                ->all();

            $itemsArray[$index]['allergens_snapshot'] = json_encode(
                $merged,
                JSON_UNESCAPED_UNICODE
            );
        }

        return $itemsArray;
    }

    /**
     * Pre-fetches allergen codes for every extra ID referenced across the rows.
     * Returns a map [extra_id => [allergen_code, ...]].
     *
     * Safe-by-design : if the `item_extra_allergens` pivot table is missing
     * (legacy schema, fresh installs without the pivot), returns []. This
     * preserves backward compatibility with the existing immutability contract.
     *
     * @param  array<int, array<string, mixed>>  $itemsArray
     * @return array<int, array<int, string>>
     */
    private static function resolveExtraAllergens(array $itemsArray): array
    {
        if (! Schema::hasTable('item_extra_allergens')) {
            return [];
        }

        $extraIds = [];
        foreach ($itemsArray as $row) {
            foreach (self::normalizeExtras($row['item_extras'] ?? null) as $extraId) {
                $extraIds[$extraId] = true;
            }
        }
        if ($extraIds === []) {
            return [];
        }

        $rows = DB::table('item_extra_allergens AS pivot')
            ->join('allergens', 'allergens.id', '=', 'pivot.allergen_id')
            ->whereIn('pivot.item_extra_id', array_keys($extraIds))
            ->orderBy('allergens.sort')
            ->get(['pivot.item_extra_id', 'allergens.code']);

        $map = [];
        foreach ($rows as $r) {
            $eid = (int) $r->item_extra_id;
            $map[$eid] = $map[$eid] ?? [];
            if (is_string($r->code) && $r->code !== '') {
                $map[$eid][] = $r->code;
            }
        }

        return $map;
    }

    /**
     * @param  array<int, array<int, string>>  $extrasAllergensById
     * @return array<int, string>
     */
    private static function extractExtraAllergens(array $row, array $extrasAllergensById): array
    {
        $codes = [];
        foreach (self::normalizeExtras($row['item_extras'] ?? null) as $extraId) {
            foreach ($extrasAllergensById[$extraId] ?? [] as $code) {
                $codes[] = $code;
            }
        }

        return $codes;
    }

    /**
     * Accepts the legacy shapes carried by item_extras :
     *   - JSON string : '[{"id":5},{"id":7}]'
     *   - Array of id-bearing objects : [['id'=>5], ['id'=>7]]
     *   - Array of ints : [5, 7]
     *   - null / scalar / malformed → []
     *
     * @return array<int, int>
     */
    private static function normalizeExtras(mixed $raw): array
    {
        if ($raw === null || $raw === '') {
            return [];
        }
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            $raw = is_array($decoded) ? $decoded : [];
        }
        if (! is_array($raw)) {
            return [];
        }

        $ids = [];
        foreach ($raw as $entry) {
            if (is_int($entry) || (is_string($entry) && ctype_digit($entry))) {
                $id = (int) $entry;
            } elseif (is_array($entry)) {
                $id = (int) ($entry['id'] ?? $entry['extra_id'] ?? $entry['item_extra_id'] ?? 0);
            } elseif (is_object($entry)) {
                $id = (int) ($entry->id ?? $entry->extra_id ?? 0);
            } else {
                $id = 0;
            }
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    /**
     * @return array<int, string>
     */
    private static function resolveSnapshot(?Item $item): array
    {
        if (!$item) {
            return [];
        }

        $pivotCodes = $item->relationLoaded('allergens')
            ? $item->allergens->pluck('code')->filter()->values()->all()
            : $item->allergens()->orderBy('sort')->pluck('code')->filter()->values()->all();

        if ($pivotCodes !== []) {
            return $pivotCodes;
        }

        if (!method_exists(AllergenService::class, 'projectFlags')) {
            return collect($item->allergen_flags ?? [])
                ->filter(fn ($code): bool => is_string($code) && $code !== '')
                ->values()
                ->all();
        }

        return [];
    }
}
