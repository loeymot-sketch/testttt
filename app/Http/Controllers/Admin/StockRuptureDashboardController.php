<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

class StockRuptureDashboardController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:items_show'])->only('lastSummary', 'lowAlerts');
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
            ->where('unavailable_reason', 'stock_rupture')
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
}
