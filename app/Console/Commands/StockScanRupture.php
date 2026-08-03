<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use App\Services\Menu\AvailabilityService;
use App\Services\Stock\ChoiceAvailabilityResolver;
use Illuminate\Support\Collection;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * StockScanRupture — Mission #2 Vague 2 action 2.1.
 *
 * Preventive auto-86 cron. Scans stock_levels per branch and flips
 * item_branch_availability.is_available=false for items whose stockable
 * variations / extras / addon targets all reached on_hand <= 0.
 *
 * Today, auto-86 is REACTIVE: it only fires on the next order that
 * consumes the last unit (cf. AvailabilityService::decrementForOrder
 * + StockService::syncItemAvailabilityForStockLevel). This command
 * fills the preventive gap so that an item with all stockable variations
 * exhausted does not stay "available" between orders.
 *
 * Configuration: see config/catalog_v15.php → auto_86_preventive_cron.
 *
 * Schedule: registered in app/Console/Kernel.php once the flag is on:
 *
 *   $schedule->command('stock:scan-rupture')
 *            ->cron(config('catalog_v15.auto_86_preventive_cron.cron_expression'))
 *            ->withoutOverlapping()
 *            ->onOneServer()
 *            ->when(fn () => config('catalog_v15.auto_86_preventive_cron.enabled', false));
 *
 * Audit: reports/audit/CLAUDE_ULTRA_REVIEW_MISSION_2_STOCK_COMPOSITION_2026-05-02.md §A.1 #7
 * Plan : plans/PLAN_CV1-LIFECYCLE-UX-001_2026-05-02.md task 2.1
 */
class StockScanRupture extends Command
{
    /**
     * Signature accepts:
     *  - --branch=<id> : scan a single branch (defaults to all active branches)
     *  - --dry-run     : log what would be flipped, mutate nothing
     */
    protected $signature = 'stock:scan-rupture {--branch=} {--dry-run}';

    protected $description = 'Preventive auto-86: flip is_available=false for items whose stockable variations are all out of stock.';

    public function handle(): int
    {
        if (config('catalog_v15.auto_86_preventive_cron.enabled', false) !== true) {
            $this->info('[stock:scan-rupture] Skipped: catalog_v15.auto_86_preventive_cron.enabled=false');
            return self::SUCCESS;
        }

        $dryRun = (bool) $this->option('dry-run');
        $branchOpt = $this->option('branch');

        $this->info(sprintf(
            '[stock:scan-rupture] Starting (dry_run=%s, branch=%s)',
            $dryRun ? 'true' : 'false',
            $branchOpt ?? 'ALL_ACTIVE',
        ));

        $branches = $this->targetBranches($branchOpt);
        $items = Item::query()
            ->where('status', Status::ACTIVE)
            ->with(['variations', 'extras', 'addons.addonItem'])
            ->get();

        $resolver = app(ChoiceAvailabilityResolver::class);
        $availability = app(AvailabilityService::class);

        foreach ($branches as $branch) {
            $start = microtime(true);
            $branchId = (int) $branch->id;
            $counters = [
                'items_scanned' => 0,
                'items_flipped' => 0,
                'items_would_flip' => 0,
                'items_already_unavailable' => 0,
                'items_skipped_partial_stock' => 0,
            ];

            $choiceSnapshot = $resolver->snapshotForItems($items, $branchId);
            $itemStockLevels = StockLevel::query()
                ->where('branch_id', $branchId)
                ->where('stockable_type', Item::class)
                ->whereIn('stockable_id', $items->pluck('id')->map(fn ($id): int => (int) $id)->all())
                ->get()
                ->keyBy('stockable_id');

            $availabilityRows = ItemBranchAvailability::query()
                ->where('branch_id', $branchId)
                ->whereIn('item_id', $items->pluck('id')->map(fn ($id): int => (int) $id)->all())
                ->get()
                ->keyBy('item_id');

            foreach ($items as $item) {
                $counters['items_scanned']++;

                if (! $this->itemShouldBeAuto86d($item, $choiceSnapshot[(int) $item->id] ?? [], $itemStockLevels->get((int) $item->id))) {
                    $counters['items_skipped_partial_stock']++;
                    continue;
                }

                $availabilityRow = $availabilityRows->get((int) $item->id);
                if ($availabilityRow && ! (bool) $availabilityRow->is_available) {
                    $counters['items_already_unavailable']++;
                    continue;
                }

                if ($dryRun) {
                    $counters['items_would_flip']++;
                    Log::info('[stock.scan-rupture-would-flip]', [
                        'branch_id' => $branchId,
                        'item_id' => (int) $item->id,
                        'reason' => 'stock_rupture',
                    ]);
                    continue;
                }

                // [panel-manual-86-reason-collision 2026-07-23] Rupture AUTO préventive :
                // provenance NON manuelle ($manual=false) → manual_unavailable_since reste
                // null → réactivable au restock (le cron re-scanne et re-86 si toujours à
                // sec). NE PAS confondre avec un 86 humain (sticky, cf. AvailabilityService).
                $availability->toggle((int) $item->id, $branchId, false, 'stock_rupture', manual: false);
                $counters['items_flipped']++;
            }

            $summary = array_merge($counters, [
                'branch_id' => $branchId,
                'duration_ms' => (int) ((microtime(true) - $start) * 1000),
                'ran_at' => now()->toIso8601String(),
                'dry_run' => $dryRun,
            ]);

            Cache::put("stock_scan_rupture:last_summary:branch:{$branchId}", $summary, now()->addDay());
            Log::info('[stock.scan-rupture-summary]', $summary);
            $this->info(sprintf(
                '[stock:scan-rupture] Branch %d scanned=%d flipped=%d would_flip=%d already_unavailable=%d partial_stock=%d',
                $branchId,
                $summary['items_scanned'],
                $summary['items_flipped'],
                $summary['items_would_flip'],
                $summary['items_already_unavailable'],
                $summary['items_skipped_partial_stock']
            ));
        }

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Branch>
     */
    private function targetBranches(mixed $branchOpt): Collection
    {
        $query = Branch::query()->whereNull('deleted_at');

        if ($branchOpt !== null && $branchOpt !== '') {
            return $query->whereKey((int) $branchOpt)->get();
        }

        // [WJ-5 / WI-2 P1 2026-05-19] Status propagation gap heal — mirror
        // Wave 2d FISCAL-ADV3C-01. The branches table straddles two "active"
        // sentinels: legacy literal `1` (BranchFactory + prod seed) and
        // canonical `Status::ACTIVE = 5` (post-enum services). The pending
        // owner-flagged data migration `UPDATE branches SET status=5 WHERE
        // status=1` would have silently no-op'd the every-5-min auto-86
        // preventive cron pre-heal (`where('status', 1)` returns empty
        // post-migration). `whereIn` is safe pre- and post-migration.
        // See Kernel::activeBranchIds() PHPDoc for the canonical rationale.
        return $query->whereIn('status', [Status::ACTIVE, 1])->get();
    }

    /**
     * @param  array<string, array<int, array{is_available: bool, unavailable_reason: ?string}>>  $choiceSnapshot
     */
    private function itemShouldBeAuto86d(Item $item, array $choiceSnapshot, ?StockLevel $itemStockLevel): bool
    {
        $choiceStates = collect($choiceSnapshot['variations'] ?? [])
            ->merge($choiceSnapshot['extras'] ?? [])
            ->merge($choiceSnapshot['addons'] ?? []);

        if ($choiceStates->isNotEmpty()) {
            return $choiceStates->every(fn (array $state): bool => ($state['is_available'] ?? true) === false);
        }

        if ($this->hasStockableChoices($item)) {
            return false;
        }

        return $itemStockLevel !== null && (int) $itemStockLevel->on_hand <= 0;
    }

    private function hasStockableChoices(Item $item): bool
    {
        return collect($item->variations ?? collect())->contains(fn (ItemVariation $variation): bool => (int) $variation->status === Status::ACTIVE)
            || collect($item->extras ?? collect())->contains(fn (ItemExtra $extra): bool => (int) $extra->status === Status::ACTIVE)
            || collect($item->addons ?? collect())->isNotEmpty();
    }
}
