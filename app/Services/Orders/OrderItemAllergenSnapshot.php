<?php

namespace App\Services\Orders;

use App\Models\Item;
use App\Services\AllergenService;

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

        foreach ($itemsArray as $index => $row) {
            $itemsArray[$index]['allergens_snapshot'] = json_encode(
                self::resolveSnapshot($itemsById->get((int) ($row['item_id'] ?? 0))),
                JSON_UNESCAPED_UNICODE
            );
        }

        return $itemsArray;
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
