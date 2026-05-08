<?php

namespace App\Services\Stock;

use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemBranchAvailability;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use Illuminate\Support\Collection;

final class ChoiceAvailabilityResolver
{
    /**
     * @param  Collection<int, Item>  $items
     * @return array<int, array{
     *   variations: array<int, array{is_available: bool, unavailable_reason: ?string}>,
     *   extras: array<int, array{is_available: bool, unavailable_reason: ?string}>,
     *   addons: array<int, array{is_available: bool, unavailable_reason: ?string}>
     * }>
     */
    public function snapshotForItems(Collection $items, int $branchId, ?string $surface = null): array
    {
        if ($branchId <= 0 || $items->isEmpty()) {
            return [];
        }

        $variationIds = $items->flatMap(fn (Item $item) => $item->variations ?? collect())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $extraIds = $items->flatMap(fn (Item $item) => $item->extras ?? collect())
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();
        $addonItems = $items->flatMap(fn (Item $item) => $item->addons ?? collect())
            ->map(fn ($addon) => $addon->addonItem)
            ->filter();
        $addonItemIds = $addonItems
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values();

        $stockLevels = $this->stockLevelsForBranch($branchId, $variationIds->all(), $extraIds->all(), $addonItemIds->all(), false);
        $branchAvailability = $addonItemIds->isEmpty()
            ? collect()
            : ItemBranchAvailability::query()
                ->where('branch_id', $branchId)
                ->whereIn('item_id', $addonItemIds->all())
                ->get()
                ->keyBy('item_id');

        return $items->mapWithKeys(function (Item $item) use ($branchAvailability, $stockLevels, $surface): array {
            $variations = collect($item->variations ?? [])
                ->mapWithKeys(fn ($variation): array => [
                    (int) $variation->id => $this->availabilityForVariation(
                        $variation,
                        $stockLevels->get($this->stockLevelKey(ItemVariation::class, (int) $variation->id))
                    ),
                ])
                ->all();

            $extras = collect($item->extras ?? [])
                ->mapWithKeys(fn ($extra): array => [
                    (int) $extra->id => $this->availabilityForExtra(
                        $extra,
                        $stockLevels->get($this->stockLevelKey(ItemExtra::class, (int) $extra->id))
                    ),
                ])
                ->all();

            $addons = collect($item->addons ?? [])
                ->mapWithKeys(function ($addon) use ($branchAvailability, $stockLevels, $surface): array {
                    $addonItem = $addon->addonItem;
                    return [
                        (int) $addon->id => $this->availabilityForAddonItem(
                            $addonItem,
                            $branchAvailability->get((int) $addon->addon_item_id),
                            $stockLevels->get($this->stockLevelKey(Item::class, (int) $addon->addon_item_id)),
                            $surface
                        ),
                    ];
                })
                ->all();

            return [
                (int) $item->id => [
                    'variations' => $variations,
                    'extras' => $extras,
                    'addons' => $addons,
                ],
            ];
        })->all();
    }

    /**
     * @return array{
     *   variations: array<int, array{is_available: bool, unavailable_reason: ?string}>,
     *   extras: array<int, array{is_available: bool, unavailable_reason: ?string}>,
     *   addons: array<int, array{is_available: bool, unavailable_reason: ?string}>
     * }
     */
    public function snapshotForItem(Item $item, int $branchId, ?string $surface = null): array
    {
        return $this->snapshotForItems(collect([$item]), $branchId, $surface)[(int) $item->id] ?? [
            'variations' => [],
            'extras' => [],
            'addons' => [],
        ];
    }

    /**
     * @param  Collection<int, ItemVariation>  $variations
     * @param  Collection<int, ItemExtra>  $extras
     * @param  Collection<int, ItemAddon>  $addons
     */
    public function assertSelectionsOrderable(
        int $branchId,
        Collection $variations,
        Collection $extras,
        Collection $addons,
        string $surface,
        bool $useRowLock,
    ): void {
        if ($branchId <= 0) {
            return;
        }

        $variationLevels = $this->stockLevelsForBranch(
            $branchId,
            $variations->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            [],
            [],
            $useRowLock
        );
        foreach ($variations as $variation) {
            $state = $this->availabilityForVariation(
                $variation,
                $variationLevels->get($this->stockLevelKey(ItemVariation::class, (int) $variation->id))
            );
            if (! $state['is_available']) {
                throw new \InvalidArgumentException(
                    "Variation ID {$variation->id} indisponible pour cette branche ({$state['unavailable_reason']}).",
                    422
                );
            }
        }

        $extraLevels = $this->stockLevelsForBranch(
            $branchId,
            [],
            $extras->pluck('id')->map(fn ($id): int => (int) $id)->all(),
            [],
            $useRowLock
        );
        foreach ($extras as $extra) {
            $state = $this->availabilityForExtra(
                $extra,
                $extraLevels->get($this->stockLevelKey(ItemExtra::class, (int) $extra->id))
            );
            if (! $state['is_available']) {
                throw new \InvalidArgumentException(
                    "Supplément ID {$extra->id} indisponible pour cette branche ({$state['unavailable_reason']}).",
                    422
                );
            }
        }

        $addonItemIds = $addons->pluck('addon_item_id')
            ->map(fn ($id): int => (int) $id)
            ->filter()
            ->unique()
            ->values()
            ->all();
        $addonLevels = $this->stockLevelsForBranch($branchId, [], [], $addonItemIds, $useRowLock);
        $branchAvailability = empty($addonItemIds)
            ? collect()
            : $this->itemBranchAvailability($branchId, $addonItemIds, $useRowLock);

        foreach ($addons as $addon) {
            $state = $this->availabilityForAddonItem(
                $addon->addonItem,
                $branchAvailability->get((int) $addon->addon_item_id),
                $addonLevels->get($this->stockLevelKey(Item::class, (int) $addon->addon_item_id)),
                $surface
            );
            if (! $state['is_available']) {
                throw new \InvalidArgumentException(
                    "Addon ID {$addon->id} indisponible pour cette branche ({$state['unavailable_reason']}).",
                    422
                );
            }
        }
    }

    /**
     * @param  array<int, int>  $variationIds
     * @param  array<int, int>  $extraIds
     * @param  array<int, int>  $itemIds
     * @return Collection<string, StockLevel>
     */
    private function stockLevelsForBranch(
        int $branchId,
        array $variationIds,
        array $extraIds,
        array $itemIds,
        bool $useRowLock,
    ): Collection {
        $query = StockLevel::query()->where('branch_id', $branchId);

        $clauses = 0;
        $query->where(function ($scope) use ($variationIds, $extraIds, $itemIds, &$clauses): void {
            if ($variationIds !== []) {
                $clauses++;
                $scope->orWhere(function ($q) use ($variationIds): void {
                    $q->where('stockable_type', ItemVariation::class)
                        ->whereIn('stockable_id', $variationIds);
                });
            }

            if ($extraIds !== []) {
                $clauses++;
                $scope->orWhere(function ($q) use ($extraIds): void {
                    $q->where('stockable_type', ItemExtra::class)
                        ->whereIn('stockable_id', $extraIds);
                });
            }

            if ($itemIds !== []) {
                $clauses++;
                $scope->orWhere(function ($q) use ($itemIds): void {
                    $q->where('stockable_type', Item::class)
                        ->whereIn('stockable_id', $itemIds);
                });
            }
        });

        if ($clauses === 0) {
            return collect();
        }

        $levels = $useRowLock ? $query->lockForUpdate()->get() : $query->get();

        return $levels->keyBy(fn (StockLevel $level): string => $this->stockLevelKey($level->stockable_type, (int) $level->stockable_id));
    }

    /**
     * @param  array<int, int>  $itemIds
     * @return Collection<int, ItemBranchAvailability>
     */
    private function itemBranchAvailability(int $branchId, array $itemIds, bool $useRowLock): Collection
    {
        $query = ItemBranchAvailability::query()
            ->where('branch_id', $branchId)
            ->whereIn('item_id', $itemIds);

        return ($useRowLock ? $query->lockForUpdate()->get() : $query->get())->keyBy('item_id');
    }

    /**
     * @return array{is_available: bool, unavailable_reason: ?string}
     */
    private function availabilityFromLevel(?StockLevel $level): array
    {
        if (! $level) {
            return ['is_available' => true, 'unavailable_reason' => null];
        }

        // [F-016a-BIS] Manual rupture (manager-set) takes priority over automatic
        // stock check. Reasoning: a manager flagging "seasonal" or "supplier_issue"
        // expresses an explicit business decision that must surface in the UI even
        // if stock_levels.on_hand happens to be > 0 (e.g. inventory drift or
        // pre-emptive flag before a delivery shortfall is recorded).
        $manualReason = $level->manual_unavailable_reason ?? null;
        if (is_string($manualReason) && $manualReason !== '') {
            return ['is_available' => false, 'unavailable_reason' => $manualReason];
        }

        return (int) $level->on_hand > 0
            ? ['is_available' => true, 'unavailable_reason' => null]
            : ['is_available' => false, 'unavailable_reason' => 'stock_rupture'];
    }

    /**
     * @return array{is_available: bool, unavailable_reason: ?string}
     */
    private function availabilityForExtra(ItemExtra $extra, ?StockLevel $level): array
    {
        $ingredientAvailable = $extra->getRawOriginal('is_available') ?? $extra->is_available;

        if ($ingredientAvailable !== null && ! (bool) $ingredientAvailable) {
            return ['is_available' => false, 'unavailable_reason' => 'ingredient_rupture'];
        }

        return $this->availabilityFromLevel($level);
    }

    /**
     * @return array{is_available: bool, unavailable_reason: ?string}
     */
    private function availabilityForVariation(ItemVariation $variation, ?StockLevel $level): array
    {
        $attribute = $variation->itemAttribute;

        if ($attribute !== null) {
            $ingredientAvailable = $attribute->getRawOriginal('is_available') ?? $attribute->is_available;

            if ($ingredientAvailable === false || $ingredientAvailable === 0 || $ingredientAvailable === '0') {
                return ['is_available' => false, 'unavailable_reason' => 'ingredient_rupture'];
            }
        }

        return $this->availabilityFromLevel($level);
    }

    /**
     * @return array{is_available: bool, unavailable_reason: ?string}
     */
    private function availabilityForAddonItem(
        ?Item $addonItem,
        ?ItemBranchAvailability $branchAvailability,
        ?StockLevel $level,
        ?string $surface,
    ): array {
        if (! $addonItem) {
            return ['is_available' => false, 'unavailable_reason' => 'addon_target_missing'];
        }

        if ((int) $addonItem->status !== \App\Enums\Status::ACTIVE) {
            return ['is_available' => false, 'unavailable_reason' => 'catalog_inactive'];
        }

        if ($addonItem->is_available !== null && ! (bool) $addonItem->is_available) {
            return ['is_available' => false, 'unavailable_reason' => 'catalog_unavailable'];
        }

        if ($surface !== null && ! $addonItem->isVisibleOn($surface)) {
            return ['is_available' => false, 'unavailable_reason' => 'surface_hidden'];
        }

        if ($branchAvailability && ! (bool) $branchAvailability->is_available) {
            return [
                'is_available' => false,
                'unavailable_reason' => $branchAvailability->unavailable_reason ?: 'branch_unavailable',
            ];
        }

        return $this->availabilityFromLevel($level);
    }

    private function stockLevelKey(string $type, int $id): string
    {
        return $type . ':' . $id;
    }
}
