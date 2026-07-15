<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class StockRuptureDashboardController extends AdminController
{
    /**
     * Display-friendly labels for known ItemExtra group_label slugs. The list
     * is intentionally small + falls back to a Str::title humanisation so new
     * group labels render acceptably without a controller edit.
     */
    private const EXTRA_GROUP_LABELS = [
        'sauce_supp'      => 'Sauces supplémentaires',
        'frites_style'    => 'Frites - format',
        'gratine'         => 'Gratiné',
        'supplement_bol'  => 'Suppléments bol',
        'supplement'      => 'Suppléments',
        'topping'         => 'Toppings',
        'extra'           => 'Extras',
    ];

    /**
     * Fallback display label when an ItemExtra has no group_label set.
     */
    private const EXTRA_GROUP_OTHER = 'other';

    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:items_show'])->only('lastSummary', 'lowAlerts', 'catalogOverview');
        $this->middleware(['permission:items_create'])->only('run');
    }

    public function lastSummary(Request $request): JsonResponse
    {
        $branches = $this->scopedBranches($request);
        $branchIds = $branches->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $currentlyUnavailable = ItemBranchAvailability::query()
            ->with(['item:id,name', 'branch:id,name'])
            ->whereIn('branch_id', $branchIds)
            ->where('is_available', false)
            // [GOAL RUPTURE-CARNET 2026-07-15 / W6 heal P2] Toutes les ruptures
            // effectives, pas seulement 'stock_rupture' : les auto-86 quota
            // journalier écrivent 'out_of_stock' (AvailabilityService:141,403) et
            // le toggle manuel accepte une raison libre/nullable — le dashboard
            // les rendait invisibles alors que borne/caisse les masquaient.
            ->whereIn('unavailable_reason', ['stock_rupture', 'out_of_stock'])
            ->orderByDesc('unavailable_since')
            ->limit(100)
            ->get()
            ->map(fn (ItemBranchAvailability $row): array => [
                'branch_id' => (int) $row->branch_id,
                'branch_name' => (string) ($row->branch?->name ?? ('#' . $row->branch_id)),
                'item_id' => (int) $row->item_id,
                'item_name' => (string) ($row->item?->name ?? ('#' . $row->item_id)),
                'reason' => (string) $row->unavailable_reason,
                'flipped_at' => optional($row->unavailable_since)->toIso8601String(),
                'flipped_at_human' => optional($row->unavailable_since)->diffForHumans(),
            ])
            ->values();

        return response()->json([
            'cron_enabled' => (bool) config('catalog_v15.auto_86_preventive_cron.enabled', false),
            'branches' => $branches->map(fn (Branch $branch): array => [
                'branch_id' => (int) $branch->id,
                'branch_name' => (string) $branch->name,
                'summary' => Cache::get("stock_scan_rupture:last_summary:branch:{$branch->id}"),
                'fetched_at' => now()->toIso8601String(),
            ])->values(),
            'currently_unavailable' => $currentlyUnavailable,
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    public function lowAlerts(Request $request): JsonResponse
    {
        $branches = $this->scopedBranches($request);
        $branchNames = $branches->keyBy('id')->map(fn (Branch $branch): string => (string) $branch->name);

        $rows = StockLevel::query()
            ->whereIn('branch_id', $branches->pluck('id')->map(fn ($id): int => (int) $id)->all())
            ->whereNotNull('threshold_low')
            ->whereColumn('on_hand', '<=', 'threshold_low')
            ->orderBy('branch_id')
            ->orderBy('on_hand')
            ->limit(200)
            ->get();

        // [BUILD-4 stock-v1-0-2-ui N+1 heal] Previous impl called stockableLabel()
        // TWICE per row (lines 82+83) → 200 rows × 2 polymorphic finds = up to 1200
        // queries on a single endpoint hit. Replace with bucketed whereIn (one
        // query per stockable_type) and resolve labels from an in-memory map.
        $labelMap = $this->buildStockableLabelMap($rows);

        $alerts = $rows->map(fn (StockLevel $level): array => [
            'branch_id' => (int) $level->branch_id,
            'branch_name' => (string) ($branchNames->get((int) $level->branch_id) ?? ('#' . $level->branch_id)),
            'stockable_type' => (string) $level->stockable_type,
            'stockable_id' => (int) $level->stockable_id,
            'stockable_name' => $this->resolveLabel($labelMap, (string) $level->stockable_type, (int) $level->stockable_id),
            'label' => $this->resolveLabel($labelMap, (string) $level->stockable_type, (int) $level->stockable_id),
            'on_hand' => (int) $level->on_hand,
            'threshold_low' => (int) $level->threshold_low,
        ])->values();

        return response()->json([
            'alerts' => $alerts,
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * [BUILD-4 stock-v1-0-2-ui N+1 heal]
     * Bucket polymorphic stockable references by type, run ONE whereIn per type,
     * return a `[type => [id => name]]` map for O(1) label resolution.
     *
     * Before this heal: 200 rows × find() × 2 calls = ≤ 1200 queries.
     * After this heal:  ≤ 3 queries (item / item_variation / item_extra).
     *
     * @param \Illuminate\Support\Collection<int, StockLevel> $rows
     * @return array<string, array<int, string>>
     */
    private function buildStockableLabelMap($rows): array
    {
        $buckets = [
            'item' => [],
            'item_variation' => [],
            'item_extra' => [],
        ];

        foreach ($rows as $row) {
            $type = $this->normalizeStockableType((string) $row->stockable_type);
            if ($type === null) {
                continue;
            }
            $buckets[$type][(int) $row->stockable_id] = true;
        }

        $map = [
            'item' => [],
            'item_variation' => [],
            'item_extra' => [],
        ];

        if (! empty($buckets['item'])) {
            $map['item'] = Item::query()
                ->whereIn('id', array_keys($buckets['item']))
                ->pluck('name', 'id')
                ->map(fn ($name): string => (string) $name)
                ->all();
        }

        if (! empty($buckets['item_variation'])) {
            $map['item_variation'] = ItemVariation::query()
                ->whereIn('id', array_keys($buckets['item_variation']))
                ->pluck('name', 'id')
                ->map(fn ($name): string => (string) $name)
                ->all();
        }

        if (! empty($buckets['item_extra'])) {
            $map['item_extra'] = ItemExtra::query()
                ->whereIn('id', array_keys($buckets['item_extra']))
                ->pluck('name', 'id')
                ->map(fn ($name): string => (string) $name)
                ->all();
        }

        return $map;
    }

    private function normalizeStockableType(string $type): ?string
    {
        return match ($type) {
            Item::class, 'item' => 'item',
            ItemVariation::class, 'item_variation' => 'item_variation',
            ItemExtra::class, 'item_extra' => 'item_extra',
            default => null,
        };
    }

    /**
     * @param array<string, array<int, string>> $map
     */
    private function resolveLabel(array $map, string $type, int $id): string
    {
        $bucket = $this->normalizeStockableType($type);
        if ($bucket !== null && isset($map[$bucket][$id])) {
            return (string) $map[$bucket][$id];
        }

        return class_basename($type) . ' #' . $id;
    }

    public function run(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'branch_id' => ['nullable', 'integer', 'exists:branches,id'],
            'dry_run' => ['nullable', 'boolean'],
        ]);

        $branches = $this->scopedBranches($request);
        $branchId = isset($validated['branch_id'])
            ? (int) $validated['branch_id']
            : (int) ($branches->first()?->id ?? 0);

        abort_if($branchId <= 0, 422, 'No branch available for stock rupture scan.');
        $this->authorizeWritableBranchScope($request, $branchId);

        if (app()->environment('production')) {
            abort_unless($request->user()?->can('items_create'), 403);
        }

        $dryRun = (bool) ($validated['dry_run'] ?? false);
        $exitCode = Artisan::call('stock:scan-rupture', [
            '--branch' => $branchId,
            '--dry-run' => $dryRun,
        ]);

        return response()->json([
            'ok' => $exitCode === 0,
            'exit_code' => $exitCode,
            'branch_id' => $branchId,
            'summary' => Cache::get("stock_scan_rupture:last_summary:branch:{$branchId}"),
            'output' => trim(Artisan::output()),
        ], $exitCode === 0 ? 200 : 500);
    }

    /**
     * @return \Illuminate\Support\Collection<int, Branch>
     */
    private function scopedBranches(Request $request)
    {
        // [GOAL-PAGEBY-STOCK-2026-05-18 P0 SCAN-422 heal]
        // Branches use the canonical Status enum (ACTIVE=5). Hard-coding `status=1`
        // produced an empty scopedBranches → branchId=0 → 422 'No branch available'
        // from POST /api/admin/stock/scan-rupture/run with no UI feedback.
        // Mirror the bridge pattern from PersistCatalogChangedToOutbox L39.
        $query = Branch::query()
            ->whereNull('deleted_at')
            ->whereIn('status', [Status::ACTIVE, 1]);

        if ($request->filled('branch_id')) {
            $branchId = (int) $request->integer('branch_id');
            $this->authorizeBranchScope($request, $branchId);
            return $query->whereKey($branchId)->get();
        }

        $userBranchId = (int) ($request->user()?->branch_id ?? 0);
        if ($userBranchId > 0) {
            return $query->whereKey($userBranchId)->get();
        }

        return $query->get();
    }

    private function stockableLabel(string $type, int $id): string
    {
        $model = match ($type) {
            Item::class, 'item' => Item::query()->find($id),
            ItemVariation::class, 'item_variation' => ItemVariation::query()->find($id),
            ItemExtra::class, 'item_extra' => ItemExtra::query()->find($id),
            default => null,
        };

        return (string) ($model?->name ?? class_basename($type) . ' #' . $id);
    }

    /**
     * [Mission 1 — Stock-Rupture UI Simplification 2026-05-21]
     * Single read endpoint that powers the unified "Produits & Stock" admin
     * page. Returns the full active catalogue (items grouped by category,
     * extras deduped by name within their group_label, variations deduped by
     * name within their attribute) with a binary per-branch is_available flag
     * resolved against `item_branch_availability` (items) and `stock_levels`
     * (extras + variations — polymorphic, `manual_unavailable_reason`).
     *
     * Bulk-query discipline (mirrors {@see buildStockableLabelMap()}): 1 SELECT
     * per entity bucket + 1 SELECT per per-branch override table. Target ≤ 5
     * queries total, no N+1 even on full catalogues.
     */
    public function catalogOverview(Request $request): JsonResponse
    {
        $branches = $this->scopedBranches($request);
        $branchId = $request->filled('branch_id')
            ? (int) $request->integer('branch_id')
            : (int) ($branches->first()?->id ?? 0);

        if ($branchId <= 0) {
            // No branch resolvable (staff with no branch, or empty branch set).
            return response()->json([
                'branch_id'        => 0,
                'categories'       => [],
                'extra_groups'     => [],
                'variation_groups' => [],
                'fetched_at'       => now()->toIso8601String(),
            ]);
        }

        // Authorize: ensure the resolved branch is inside the caller's scope.
        $this->authorizeBranchScope($request, $branchId);

        // 1) Categories + items (eager-load items, active only).
        $categories = ItemCategory::query()
            ->where('status', Status::ACTIVE)
            ->orderBy('sort')
            ->orderBy('id')
            ->with(['items' => function ($q): void {
                $q->where('status', Status::ACTIVE)
                    ->orderBy('order')
                    ->orderBy('id');
            }])
            ->get();

        $allItemIds = $categories->flatMap(fn (ItemCategory $cat) => $cat->items->pluck('id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        // 2) Per-branch item overrides (one SELECT) — BranchScope auto-filters
        //    to the caller's branch_id (or none for admin). We additionally
        //    constrain by the resolved branchId to be explicit.
        $itemOverrides = ItemBranchAvailability::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereIn('item_id', $allItemIds ?: [0])
            ->get(['item_id', 'is_available', 'unavailable_reason'])
            ->keyBy('item_id');

        $categoriesPayload = $categories->map(function (ItemCategory $cat) use ($itemOverrides): array {
            return [
                'id'    => (int) $cat->id,
                'name'  => (string) $cat->name,
                'slug'  => (string) ($cat->slug ?? ''),
                'items' => $cat->items->map(function (Item $item) use ($itemOverrides): array {
                    $override = $itemOverrides->get((int) $item->id);
                    $isAvailable = (bool) $item->is_available;
                    $reason = null;
                    if ($override !== null) {
                        $overrideAvailable = (bool) $override->is_available;
                        if (! $overrideAvailable) {
                            $isAvailable = false;
                            $reason = $override->unavailable_reason !== null
                                ? (string) $override->unavailable_reason
                                : null;
                        }
                    }
                    return [
                        'id'           => (int) $item->id,
                        'name'         => (string) $item->name,
                        'slug'         => (string) ($item->slug ?? ''),
                        'thumb'        => (string) $item->thumb,
                        'is_available' => $isAvailable,
                        'reason'       => $reason,
                    ];
                })->values()->all(),
            ];
        })->values()->all();

        // 3) Extras (active, grouped by group_label, deduped by name).
        $extras = ItemExtra::query()
            ->where('status', Status::ACTIVE)
            ->orderBy('group_label')
            ->orderBy('name')
            ->orderBy('id')
            ->get(['id', 'name', 'group_label']);

        $allExtraIds = $extras->pluck('id')->map(fn ($id): int => (int) $id)->all();

        // 4) Per-branch extra rupture (one SELECT, polymorphic).
        $extraStockLevels = $allExtraIds === []
            ? collect()
            : StockLevel::query()
                ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('branch_id', $branchId)
                ->where('stockable_type', ItemExtra::class)
                ->whereIn('stockable_id', $allExtraIds)
                ->whereNotNull('manual_unavailable_reason')
                ->get(['stockable_id'])
                ->keyBy(fn ($row): int => (int) $row->stockable_id);

        $extraGroupsPayload = $this->buildGroupedPayload(
            $extras,
            fn (ItemExtra $e): string => (string) ($e->group_label ?? self::EXTRA_GROUP_OTHER),
            fn (ItemExtra $e): string => (string) $e->name,
            fn (ItemExtra $e): ?string => $e->thumb,
            $extraStockLevels,
            'extra_ids',
            fn (string $groupKey): string => $this->humaniseExtraGroupLabel($groupKey),
        );

        // [Wave M1 round-2 A-015 dedupe] Two raw group_label values can humanise
        // to the same display_name (e.g. legacy 'supplement' + 'supplements'),
        // surfacing as duplicate rail rows. Merge any groups sharing the same
        // display_name so the admin rail never shows two identical buckets.
        $extraGroupsPayload = $this->mergeGroupsByDisplayName($extraGroupsPayload, 'extra_ids');

        // [Wave M1 round-3 A-015 cross-axis dedupe] An ItemCategory may share a
        // name with an extra group's display_name (real case: ItemCategory id=8
        // "Suppléments" + extra group_label='supplement' → both label "Suppléments").
        // The rail concatenates categories + extras + variations, so a colliding
        // pair surfaces as TWO identical rows. Disambiguate the extra side with
        // a positional suffix that reflects its semantic ("à composer" =
        // composer/wizard dimension).
        $categoryNameSet = array_flip(array_map(
            fn (array $cat): string => (string) $cat['name'],
            $categoriesPayload
        ));
        foreach ($extraGroupsPayload as &$group) {
            $displayName = (string) ($group['display_name'] ?? '');
            if ($displayName !== '' && isset($categoryNameSet[$displayName])) {
                $group['display_name'] = $displayName . ' (à composer)';
            }
        }
        unset($group);

        // 5) Variations (active, grouped by item_attribute_id, deduped by name).
        $variations = ItemVariation::query()
            ->where('status', Status::ACTIVE)
            ->whereNotNull('item_attribute_id')
            ->with(['itemAttribute:id,name'])
            ->orderBy('item_attribute_id')
            ->orderBy('name')
            ->orderBy('id')
            ->get();

        $allVariationIds = $variations->pluck('id')->map(fn ($id): int => (int) $id)->all();

        $variationStockLevels = $allVariationIds === []
            ? collect()
            : StockLevel::query()
                ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                ->where('branch_id', $branchId)
                ->where('stockable_type', ItemVariation::class)
                ->whereIn('stockable_id', $allVariationIds)
                ->whereNotNull('manual_unavailable_reason')
                ->get(['stockable_id'])
                ->keyBy(fn ($row): int => (int) $row->stockable_id);

        // Group variations by attribute, dedupe by variation name.
        $byAttribute = $variations->groupBy(fn (ItemVariation $v): int => (int) $v->item_attribute_id);
        $variationGroupsPayload = [];
        foreach ($byAttribute as $attributeId => $bucket) {
            $attributeId = (int) $attributeId;
            $attribute = $bucket->first()->itemAttribute;
            $attributeName = (string) ($attribute->name ?? ('#' . $attributeId));

            $byName = $bucket->groupBy(fn (ItemVariation $v): string => (string) $v->name);
            $items = [];
            foreach ($byName as $name => $rows) {
                $ids = $rows->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
                $unavailableCount = 0;
                foreach ($ids as $rowId) {
                    if ($variationStockLevels->has($rowId)) {
                        $unavailableCount++;
                    }
                }
                /** @var ItemVariation $first */
                $first = $rows->first();
                $items[] = [
                    'name'                 => (string) $name,
                    'variation_ids'        => $ids,
                    'thumb'                => $first->thumb,
                    'is_available'         => $unavailableCount === 0,
                    'any_unavailable_count'=> $unavailableCount,
                    'total_count'          => count($ids),
                ];
            }
            usort($items, fn ($a, $b): int => strcmp($a['name'], $b['name']));

            $variationGroupsPayload[] = [
                'attribute_id'   => $attributeId,
                'attribute_name' => $attributeName,
                'items'          => $items,
            ];
        }
        // Stable ordering by attribute name.
        usort($variationGroupsPayload, fn ($a, $b): int => strcmp($a['attribute_name'], $b['attribute_name']));

        // [Wave M1 round-3 A-015 cross-axis dedupe] Same protection for the
        // variations axis — an ItemCategory.name colliding with a variation
        // attribute_name would surface as duplicate rail rows.
        foreach ($variationGroupsPayload as &$vGroup) {
            $attributeName = (string) ($vGroup['attribute_name'] ?? '');
            if ($attributeName !== '' && isset($categoryNameSet[$attributeName])) {
                $vGroup['attribute_name'] = $attributeName . ' (variation)';
            }
        }
        unset($vGroup);

        return response()->json([
            'branch_id'        => $branchId,
            'categories'       => $categoriesPayload,
            'extra_groups'     => $extraGroupsPayload,
            'variation_groups' => $variationGroupsPayload,
            'fetched_at'       => now()->toIso8601String(),
        ]);
    }

    /**
     * Shared dedupe-by-name + ruptured-count aggregator for the extras axis.
     * Variations need a different shape (attribute_id) and are inlined above.
     *
     * @param  \Illuminate\Support\Collection $rows         Collection of models.
     * @param  callable $groupKeyOf                         model -> group key string.
     * @param  callable $nameOf                             model -> display name.
     * @param  callable $thumbOf                            model -> thumb URL or null.
     * @param  \Illuminate\Support\Collection $rupturedById row keyed by id when ruptured.
     * @param  string $idsField                             output field name (e.g. 'extra_ids').
     * @param  callable $displayNameOf                      group key -> display name.
     * @return array<int, array<string, mixed>>
     */
    private function buildGroupedPayload(
        $rows,
        callable $groupKeyOf,
        callable $nameOf,
        callable $thumbOf,
        $rupturedById,
        string $idsField,
        callable $displayNameOf
    ): array {
        $byGroup = $rows->groupBy($groupKeyOf);
        $payload = [];
        foreach ($byGroup as $groupKey => $bucket) {
            $byName = $bucket->groupBy($nameOf);
            $items = [];
            foreach ($byName as $name => $groupRows) {
                $ids = $groupRows->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
                $unavailableCount = 0;
                foreach ($ids as $rowId) {
                    if ($rupturedById->has($rowId)) {
                        $unavailableCount++;
                    }
                }
                $first = $groupRows->first();
                $items[] = [
                    'name'                 => (string) $name,
                    $idsField              => $ids,
                    'thumb'                => $thumbOf($first),
                    'is_available'         => $unavailableCount === 0,
                    'any_unavailable_count'=> $unavailableCount,
                    'total_count'          => count($ids),
                ];
            }
            usort($items, fn ($a, $b): int => strcmp($a['name'], $b['name']));

            $payload[] = [
                'group_label'  => (string) $groupKey,
                'display_name' => $displayNameOf((string) $groupKey),
                'items'        => $items,
            ];
        }
        usort($payload, fn ($a, $b): int => strcmp($a['display_name'], $b['display_name']));

        return $payload;
    }

    /**
     * [Wave M1 round-2 A-015 dedupe]
     * Merge entries in a buildGroupedPayload() result that share the same
     * `display_name`. Items inside merged groups are themselves re-deduped by
     * name (id arrays unioned, OOS counts summed). Preserves the input order
     * (first-seen wins for `group_label`).
     *
     * @param  array<int, array<string, mixed>> $groups
     * @param  string $idsField  e.g. 'extra_ids'
     * @return array<int, array<string, mixed>>
     */
    private function mergeGroupsByDisplayName(array $groups, string $idsField): array
    {
        $byDisplay = [];
        foreach ($groups as $group) {
            $displayName = (string) ($group['display_name'] ?? '');
            if (! isset($byDisplay[$displayName])) {
                $byDisplay[$displayName] = $group;
                continue;
            }
            // Merge items by name. Preserve insertion order via positional index.
            $existing = &$byDisplay[$displayName];
            $itemsByName = [];
            foreach (($existing['items'] ?? []) as $item) {
                $itemsByName[(string) $item['name']] = $item;
            }
            foreach (($group['items'] ?? []) as $item) {
                $name = (string) $item['name'];
                if (! isset($itemsByName[$name])) {
                    $itemsByName[$name] = $item;
                    continue;
                }
                $prev = $itemsByName[$name];
                $mergedIds = array_values(array_unique(array_merge(
                    (array) ($prev[$idsField] ?? []),
                    (array) ($item[$idsField] ?? []),
                )));
                $mergedUnavailable = (int) ($prev['any_unavailable_count'] ?? 0)
                    + (int) ($item['any_unavailable_count'] ?? 0);
                $mergedTotal = count($mergedIds);
                $itemsByName[$name] = [
                    'name'                  => $name,
                    $idsField               => $mergedIds,
                    'thumb'                 => $prev['thumb'] ?? ($item['thumb'] ?? null),
                    'is_available'          => $mergedUnavailable === 0,
                    'any_unavailable_count' => $mergedUnavailable,
                    'total_count'           => $mergedTotal,
                ];
            }
            $items = array_values($itemsByName);
            usort($items, fn ($a, $b): int => strcmp($a['name'], $b['name']));
            $existing['items'] = $items;
        }
        return array_values($byDisplay);
    }

    private function humaniseExtraGroupLabel(string $key): string
    {
        if (isset(self::EXTRA_GROUP_LABELS[$key])) {
            return self::EXTRA_GROUP_LABELS[$key];
        }
        if ($key === self::EXTRA_GROUP_OTHER || $key === '') {
            // [Wave M1 round-3 A-009] "Autres" was ambiguous in the rail when
            // it landed first by alphabetical sort — admins could not tell
            // what the bucket actually contains. Use a more explicit label
            // that signals "ingredient extras without a group_label" rather
            // than a generic catch-all.
            return 'Autres ingrédients';
        }
        return Str::title(str_replace('_', ' ', $key));
    }
}
