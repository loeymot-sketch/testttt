<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Jobs\Observability\SloEvaluatorJob;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use App\Services\Observability\SloMetricCollector;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * [WJ-5 / WI-2 P1 — Status propagation gap sentinel, 2026-05-19]
 *
 * Sentinel for the FISCAL-ADV3B-01 / Wave 2d FISCAL-ADV3C-01 bug class
 * propagating to non-fiscal cron lanes.
 *
 * The branches table currently straddles two "active" sentinels:
 *  - legacy literal `1` (BranchFactory default + pre-enum prod seed)
 *  - canonical `App\Enums\Status::ACTIVE = 5` (post-enum services)
 *
 * The owner-flagged data migration `UPDATE branches SET status=5 WHERE
 * status=1` is pending (cf. BranchFactory docblock + Kernel::activeBranchIds
 * PHPDoc). Two cron lanes still used the literal `where('status', 1)`
 * filter pre-heal:
 *   - SloEvaluatorJob:48 — K-9 every-5-min SLO evaluator
 *   - StockScanRupture:168 — auto-86 preventive scan (every 5 min when on)
 *
 * Post-migration, both would have silently iterated an empty branch
 * collection: zero ActionLog entries, zero auto-86 flips, zero Slack
 * alerts — recreating the exact "silent skip" class FISCAL-ADV3B-01
 * was opened to close. This sentinel locks in the heal:
 *
 * 1. Canonical Status::ACTIVE branches MUST be iterated by SloEvaluatorJob
 *    (Wave 2d migration target — fails RED if filter still `where=1` only).
 * 2. Canonical Status::ACTIVE branches MUST be picked up by StockScanRupture
 *    (same bug class — fails RED if filter still `where=1` only).
 * 3. Legacy status=1 branches MUST keep working (backward compatible
 *    pre-migration — guards against an over-eager fix that flips the
 *    sole filter to Status::ACTIVE and breaks the pre-migration prod DB).
 */
class StatusPropagationGapSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * [WJ-5 / WI-2 P1] SLO evaluator MUST iterate Status::ACTIVE (=5) branches.
     *
     * Pre-heal: `Branch::query()->where('status', 1)->get()` — empty.
     * Post-heal: `whereIn('status', [Status::ACTIVE, 1])` — present.
     */
    public function test_slo_evaluator_iterates_canonical_status_active_branch(): void
    {
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        (new SloEvaluatorJob())->handle(new SloMetricCollector());

        $this->assertNotNull(
            ActionLog::query()
                ->where('branch_id', $branch->id)
                ->where('action', 'slo_evaluation')
                ->first(),
            'SloEvaluatorJob MUST iterate Status::ACTIVE (=5) branches post owner-flagged '
            .'data migration UPDATE branches SET status=5 WHERE status=1 — otherwise the '
            .'every-5-min SLO sweep blanks silently (FISCAL-ADV3B-01 propagation gap).'
        );
    }

    /**
     * [WJ-5 / WI-2 P1] SLO evaluator MUST stay backward compatible with legacy status=1.
     *
     * Until the owner runs the data migration, branches still carry the
     * literal `1` (BranchFactory default + prod seed). Iterating must
     * keep working — guards against an over-eager `where=Status::ACTIVE`
     * fix that would break the pre-migration prod DB.
     */
    public function test_slo_evaluator_iterates_legacy_status_one_branch(): void
    {
        $branch = Branch::factory()->create(['status' => 1]);

        (new SloEvaluatorJob())->handle(new SloMetricCollector());

        $this->assertNotNull(
            ActionLog::query()
                ->where('branch_id', $branch->id)
                ->where('action', 'slo_evaluation')
                ->first(),
            'SloEvaluatorJob MUST stay backward compatible with legacy status=1 '
            .'(pre-migration prod DB). Filter must accept both literals: whereIn([Status::ACTIVE, 1]).'
        );
    }

    /**
     * [WJ-5 / WI-2 P1] StockScanRupture MUST iterate Status::ACTIVE (=5) branches.
     *
     * Pre-heal: `$query->where('status', 1)->get()` at line 168 — empty.
     * Post-heal: `whereIn('status', [Status::ACTIVE, 1])` — present.
     *
     * We seed an item with one stockable variation whose on_hand=0 on
     * the canonical-status branch. If the command iterates that branch,
     * the item flips to is_available=false and ItemAvailabilityChanged
     * fires. If the command silently skips (pre-heal), neither happens.
     */
    public function test_stock_scan_rupture_iterates_canonical_status_active_branch(): void
    {
        config(['catalog_v15.auto_86_preventive_cron.enabled' => true]);
        Cache::flush();
        Event::fake([ItemAvailabilityChanged::class]);

        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        [$item, $variation] = $this->itemWithOutOfStockVariation($branch);

        $exit = Artisan::call('stock:scan-rupture');
        $this->assertSame(0, $exit);

        $this->assertDatabaseHas('item_branch_availability', [
            'branch_id'    => $branch->id,
            'item_id'      => $item->id,
            'is_available' => 0,
        ]);

        Event::assertDispatched(ItemAvailabilityChanged::class, function ($event) use ($branch, $item): bool {
            return (int) $event->branchId === (int) $branch->id
                && (int) $event->itemId === (int) $item->id;
        });
    }

    /**
     * [WJ-5 / WI-2 P1] StockScanRupture MUST stay backward compatible with legacy status=1.
     *
     * Same coverage as the SLO backward-compat test: the legacy literal
     * branch must keep being picked up by the auto-86 cron.
     */
    public function test_stock_scan_rupture_iterates_legacy_status_one_branch(): void
    {
        config(['catalog_v15.auto_86_preventive_cron.enabled' => true]);
        Cache::flush();
        Event::fake([ItemAvailabilityChanged::class]);

        $branch = Branch::factory()->create(['status' => 1]);
        [$item, $variation] = $this->itemWithOutOfStockVariation($branch);

        $exit = Artisan::call('stock:scan-rupture');
        $this->assertSame(0, $exit);

        $this->assertDatabaseHas('item_branch_availability', [
            'branch_id'    => $branch->id,
            'item_id'      => $item->id,
            'is_available' => 0,
        ]);
    }

    /**
     * [WJ-5 / WI-2 P1] Inactive branches MUST be excluded by both lanes.
     *
     * Guard against an over-eager fix that drops the status filter
     * altogether (e.g. `Branch::query()->get()`). Status::INACTIVE (=10)
     * branches must NOT receive an SLO snapshot.
     */
    public function test_inactive_branch_is_excluded_by_both_lanes(): void
    {
        config(['catalog_v15.auto_86_preventive_cron.enabled' => true]);
        Cache::flush();
        Event::fake([ItemAvailabilityChanged::class]);

        $inactive = Branch::factory()->create(['status' => Status::INACTIVE]);

        // SLO lane
        (new SloEvaluatorJob())->handle(new SloMetricCollector());
        $this->assertNull(
            ActionLog::query()
                ->where('branch_id', $inactive->id)
                ->where('action', 'slo_evaluation')
                ->first(),
            'Status::INACTIVE branches must NOT be evaluated by SloEvaluatorJob.'
        );

        // StockScanRupture lane (seed item but don't expect a flip)
        [$item, $variation] = $this->itemWithOutOfStockVariation($inactive);

        $exit = Artisan::call('stock:scan-rupture');
        $this->assertSame(0, $exit);

        $this->assertDatabaseMissing('item_branch_availability', [
            'branch_id' => $inactive->id,
            'item_id'   => $item->id,
        ]);
    }

    /**
     * Builds an Item with a single stockable variation whose on_hand=0
     * on the given branch — guarantees StockScanRupture would flip the
     * item to is_available=false IF the branch is iterated.
     *
     * Mirrors the manual-seed pattern in StockScanRuptureCommandTest
     * (no factory exists for ItemVariation in this codebase).
     *
     * @return array{0: Item, 1: ItemVariation}
     */
    private function itemWithOutOfStockVariation(Branch $branch): array
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['status' => Status::ACTIVE]);

        $variation = ItemVariation::query()->create([
            'item_id'           => $item->id,
            'item_attribute_id' => $attribute->id,
            'name'              => 'Choice A',
            'price'             => 0,
            'status'            => Status::ACTIVE,
        ]);

        StockLevel::query()->create([
            'branch_id'      => $branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id'   => $variation->id,
            'on_hand'        => 0,
            'reserved'       => 0,
            'threshold_low'  => 2,
        ]);

        return [$item, $variation];
    }
}
