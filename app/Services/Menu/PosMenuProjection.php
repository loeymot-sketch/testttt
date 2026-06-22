<?php

namespace App\Services\Menu;

use App\Enums\Status;
use App\Models\ItemCategory;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * PosMenuProjection — V1.5 staged convergence shim.
 *
 * Encapsulates the POS catalog projection so that PosCategoryController and
 * ItemController can be migrated to MenuProjectionService::forChannel('pos')
 * behind a feature flag without breaking the legacy payload contract.
 *
 * Three modes (driven by config/catalog_v15.php):
 *
 *   1. legacy (default)
 *      Returns whatever the existing controller logic builds. This service
 *      acts as a pass-through; no behavioral change.
 *
 *   2. shadow_compare
 *      Builds BOTH the legacy and unified payloads. Returns legacy. Logs
 *      structural diffs to storage/logs/catalog-shadow-diff.log so that
 *      ops can prove parity before flipping `enabled`.
 *
 *   3. unified
 *      Calls MenuProjectionService::forChannel('pos', $branchId), adapts
 *      the response to the legacy shape (categories array + virtual id:0
 *      "all_items" header), and returns it.
 *
 * Kill-switch (FK_CATALOG_UNIFIED_PROJECTION_KILL_SWITCH=true) forces
 * legacy regardless of `enabled`.
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_1_CATALOG_SYNC_2026-05-02.md §D Vague 2.
 * Plan : plans/PLAN_CV1-CATALOG-CONVERGENCE-001_2026-05-02.md tasks 2.1 → 2.7.
 */
class PosMenuProjection
{
    public function __construct(
        private readonly MenuProjectionService $menuProjectionService,
        private readonly mixed $unifiedPayloadResolver = null,
    ) {}

    /**
     * Build the POS categories + items payload for a given branch.
     *
     * @param int      $branchId   Active branch of the authenticated POS user
     * @param callable $legacyBuilder  Closure that returns the existing
     *                                 PosCategoryController array literal
     *                                 (categories with media + virtual id:0).
     *                                 Injected by the controller so this
     *                                 service stays unaware of the legacy
     *                                 implementation details.
     *
     * @return array  Categories array, legacy-compatible shape.
     */
    public function forBranch(int $branchId, callable $legacyBuilder): array
    {
        if ($this->isKillSwitchActive()) {
            return $legacyBuilder();
        }

        $mode = $this->mode();

        return match ($mode) {
            'unified'        => $this->buildUnified($branchId, $legacyBuilder),
            'shadow_compare' => $this->buildShadowCompare($branchId, $legacyBuilder),
            default          => $legacyBuilder(),
        };
    }

    /**
     * Resolve current operational mode from config + env.
     *
     * Returns one of: 'legacy', 'shadow_compare', 'unified'.
     */
    public function mode(): string
    {
        if (config('catalog_v15.unified_projection.enabled', false) === true) {
            return 'unified';
        }
        if (config('catalog_v15.unified_projection.shadow_compare', false) === true) {
            return 'shadow_compare';
        }
        return 'legacy';
    }

    public function isKillSwitchActive(): bool
    {
        return (bool) config('catalog_v15.unified_projection.kill_switch', false);
    }

    /**
     * Run the unified path through MenuProjectionService and adapt to legacy shape.
     *
     * TODO (Codex — task 2.2 of plan):
     * - Implement adaptUnifiedToLegacyShape() with explicit mapping:
     *     unified.categories[i].id           -> legacy.id
     *     unified.categories[i].name         -> legacy.name (kiosk_label is NOT applied on POS)
     *     unified.categories[i].sort         -> legacy.sort  (pos_sort fallback honored by service)
     *     unified.categories[i].items[]      -> NOT included in PosCategoryController response;
     *                                           items live behind /api/admin/item with ?surface=pos
     *     INJECT virtual id:0 'all_items' header at index 0 (legacy parity).
     * - Cover with tests/Feature/Menu/PosCategoryProjectionParityTest.php (Vague 2 sentinel).
     */
    private function buildUnified(int $branchId, callable $legacyBuilder): array
    {
        try {
            $unified = $this->resolveUnifiedPayload($branchId);
            return $this->adaptUnifiedToLegacyShape($unified, $legacyBuilder);
        } catch (Throwable $e) {
            Log::channel('stack')->error('[catalog.unified-failed]', [
                'branch_id' => $branchId,
                'error' => $e->getMessage(),
            ]);

            // Unified path is opt-in; never break POS runtime list on failure.
            return $legacyBuilder();
        }
    }

    /**
     * Run BOTH paths, return legacy, log structural diff.
     */
    private function buildShadowCompare(int $branchId, callable $legacyBuilder): array
    {
        $legacy = $legacyBuilder();

        try {
            $unified = $this->resolveUnifiedPayload($branchId);
            $adapted = $this->adaptUnifiedToLegacyShape($unified, fn () => $legacy);
            $diff    = $this->structuralDiff($legacy, $adapted);

            if ($diff !== []) {
                Log::channel('stack')->warning('[catalog.shadow-diff]', [
                    'branch_id' => $branchId,
                    'mode'      => 'shadow_compare',
                    'diff'      => $diff,
                ]);
            }
        } catch (Throwable $e) {
            // Shadow comparison must never break the live POS request.
            Log::channel('stack')->error('[catalog.shadow-compare-failed]', [
                'branch_id' => $branchId,
                'error'     => $e->getMessage(),
            ]);
        }

        return $legacy;
    }

    /**
     * Adapt the unified MenuProjectionService payload to the legacy
     * PosCategoryController array literal shape.
     *
     * STUB — to be implemented in plan task 2.2.
     */
    private function adaptUnifiedToLegacyShape(array $unified, callable $legacyBuilder): array
    {
        $categories = collect($unified['categories'] ?? []);
        if ($categories->isEmpty()) {
            return [$this->virtualAllItemsCategory()];
        }

        $categoryIds = $categories
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->filter(fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        $metaById = ItemCategory::query()
            ->with('media')
            ->whereIn('id', $categoryIds)
            ->get()
            ->keyBy('id');

        $projected = $categories->map(function (array $category) use ($metaById): ?array {
            $id = (int) ($category['id'] ?? 0);
            if ($id < 1) {
                return null;
            }

            /** @var ItemCategory|null $meta */
            $meta = $metaById->get($id);

            return [
                'id'          => $id,
                'name'        => (string) ($category['name'] ?? ''),
                'slug'        => (string) ($category['slug'] ?? ''),
                'description' => (string) ($meta?->description ?? ''),
                'status'      => (int) ($meta?->status ?? Status::ACTIVE),
                'thumb'       => $meta?->thumb,
                'cover'       => $meta?->cover,
                // [2026-05-18 RED R-1 heal] Preserve the additive `featured`
                // flag through the unified-projection adapter. The legacy
                // PosCategoryController response carries it (F-4 fix); without
                // this copy the POS first-page filter silently disappears the
                // moment `FK_CATALOG_UNIFIED_PROJECTION_ENABLED=true` flips on.
                'featured'    => (bool) ($category['featured'] ?? false),
            ];
        })
            ->filter()
            ->values()
            ->all();

        return array_merge([$this->virtualAllItemsCategory()], $projected);
    }

    /**
     * Structural diff (keys, sort order, ids) between legacy and adapted.
     *
     * STUB — to be implemented in plan task 2.5 with category-id set diff,
     * item-id set diff per category, and ordering check.
     */
    private function structuralDiff(array $legacy, array $adapted): array
    {
        $legacyCollection = collect($legacy);
        $adaptedCollection = collect($adapted);

        $legacyIds = $legacyCollection->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();
        $adaptedIds = $adaptedCollection->pluck('id')->map(fn ($id): int => (int) $id)->values()->all();

        $diff = [];
        if ($legacyIds !== $adaptedIds) {
            $diff['ids_order'] = [
                'legacy' => $legacyIds,
                'adapted' => $adaptedIds,
            ];
            $diff['ids_only_in_legacy'] = array_values(array_diff($legacyIds, $adaptedIds));
            $diff['ids_only_in_adapted'] = array_values(array_diff($adaptedIds, $legacyIds));
        }

        $legacyById = $legacyCollection->keyBy('id');
        $adaptedById = $adaptedCollection->keyBy('id');
        $nameMismatches = [];

        /** @var int $id */
        foreach (array_intersect($legacyIds, $adaptedIds) as $id) {
            $legacyName = (string) ($legacyById->get($id)['name'] ?? '');
            $adaptedName = (string) ($adaptedById->get($id)['name'] ?? '');
            if ($legacyName !== $adaptedName) {
                $nameMismatches[] = [
                    'id' => (int) $id,
                    'legacy' => $legacyName,
                    'adapted' => $adaptedName,
                ];
            }
        }

        if ($nameMismatches !== []) {
            $diff['name_mismatches'] = $nameMismatches;
        }

        return $diff;
    }

    private function virtualAllItemsCategory(): array
    {
        return [
            'id' => 0,
            'name' => trans('all.label.all_items'),
            'slug' => 'all-items',
            'thumb' => asset('images/default/all-category.png'),
            'cover' => asset('images/default/all-category.png'),
            // [2026-05-18 RED R-1 heal] Sentinel always featured-true so the
            // POS strip never hides the "Toutes les ..." entry.
            'featured' => true,
        ];
    }

    private function resolveUnifiedPayload(int $branchId): array
    {
        if (is_callable($this->unifiedPayloadResolver)) {
            return ($this->unifiedPayloadResolver)($branchId);
        }

        return $this->menuProjectionService->forChannel('pos', $branchId);
    }
}
