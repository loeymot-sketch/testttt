<?php

namespace App\Services\Menu;

use App\Services\Kiosk\KioskMenuService;
use Illuminate\Support\Facades\Cache;
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
        $unified = $this->menuProjectionService->forChannel('pos', $branchId);
        return $this->adaptUnifiedToLegacyShape($unified, $legacyBuilder);
    }

    /**
     * Run BOTH paths, return legacy, log structural diff.
     */
    private function buildShadowCompare(int $branchId, callable $legacyBuilder): array
    {
        $legacy = $legacyBuilder();

        try {
            $unified = $this->menuProjectionService->forChannel('pos', $branchId);
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
        // SAFETY FALLBACK: until adaptation logic is implemented, return legacy.
        // This guarantees that flipping `enabled=true` prematurely cannot
        // break production — at worst it costs one extra DB read.
        return $legacyBuilder();
    }

    /**
     * Structural diff (keys, sort order, ids) between legacy and adapted.
     *
     * STUB — to be implemented in plan task 2.5 with category-id set diff,
     * item-id set diff per category, and ordering check.
     */
    private function structuralDiff(array $legacy, array $adapted): array
    {
        return [
            'todo' => 'implement-in-task-2.5',
        ];
    }
}
