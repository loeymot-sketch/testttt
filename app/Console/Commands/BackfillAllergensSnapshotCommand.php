<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\OrderItem;
use App\Models\Scopes\BranchScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [Sprint H4 Z3-NEW-005 2026-05-17] Backfill allergens_snapshot on
 * order_items rows that pre-date the snapshot column (added 2026-04-18
 * by migration 2026_04_18_140004_add_allergens_snapshot_to_order_items).
 *
 * Mirror (read-only) of `OrderItemAllergenSnapshot::resolveSnapshot()`:
 *   1. Try `Item::allergens` pivot (post-2026-04-18 normalized source).
 *   2. Fallback to `Item::allergen_flags` JSON cache (legacy items that
 *      pre-date the pivot — pre-2026-04-18 fixtures).
 *
 * NF525 §8 — composition_snapshot and adjacent snapshots are immutable
 * once written. This command ONLY populates rows where the snapshot is
 * NULL; existing snapshots (even differing) are never overwritten.
 *
 * Multi-tenant: bypasses `BranchScope` deliberately (`withoutGlobalScope`).
 * Ops backfill is a cross-tenant administrative task; running it via CLI
 * without an authenticated user would skip the scope anyway (see
 * `BranchScope::apply` console guard), but the explicit opt-out is
 * defensive — works identically under `actingAs()` (tests) and bare CLI.
 *
 * Write path: `DB::table('order_items')->update(...)` with `json_encode(...,
 * JSON_UNESCAPED_UNICODE)` — byte-identical to the production write site
 * `OrderItemAllergenSnapshot::hydrate()` and the FR rename backfill migration
 * `2026_04_20_131600_backfill_fr_codes_in_order_items_allergens_snapshot`.
 * Bypasses the Eloquent `'array'` cast to avoid double-encoding.
 *
 * Best-effort: reflects CURRENT item state, not historical. If an item's
 * allergen list changed between order time and backfill time, the snapshot
 * is approximate. Chefs reading legacy orders should treat backfilled rows
 * as a hint, not a fiscal-grade guarantee. Rows for items hard-deleted
 * before backfill remain NULL (logged as warnings).
 *
 * Idempotent — re-runnable; only NULL rows match.
 */
class BackfillAllergensSnapshotCommand extends Command
{
    protected $signature = 'foodking:backfill:allergens-snapshot
                            {--dry-run : List what would be written without modifying the DB.}
                            {--chunk=500 : Rows per batch.}';

    protected $description = 'Backfill allergens_snapshot on legacy order_items rows (NULL → current Item allergens).';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $chunk = max(1, (int) $this->option('chunk'));

        $scanned = 0;
        $backfilled = 0;
        $orphans = 0;
        $emptyAllergens = 0;

        OrderItem::withoutGlobalScope(BranchScope::class)
            ->whereNull('allergens_snapshot')
            ->orderBy('id')
            ->chunkById($chunk, function ($rows) use (&$scanned, &$backfilled, &$orphans, &$emptyAllergens, $dryRun): void {
                foreach ($rows as $row) {
                    $scanned++;
                    // withTrashed: a soft-deleted item still carries valid allergen data
                    // (the order was placed against that item; the soft-delete only
                    // removes it from the active catalog).
                    $item = Item::withTrashed()->find($row->item_id);
                    if (! $item) {
                        $orphans++;
                        Log::warning('[Backfill][Z3-NEW-005] orphan order_item — no items row', [
                            'order_item_id' => $row->id,
                            'item_id'       => $row->item_id,
                        ]);
                        continue;
                    }

                    $codes = $this->resolveAllergenCodes($item);

                    if ($dryRun) {
                        $backfilled++;
                        continue;
                    }

                    // Direct DB::update mirrors the FR rename backfill precedent +
                    // OrderItemAllergenSnapshot::hydrate() write shape. Bypasses
                    // the Eloquent 'array' cast → no double-encoding.
                    DB::table('order_items')->where('id', $row->id)->update([
                        'allergens_snapshot' => json_encode($codes, JSON_UNESCAPED_UNICODE),
                    ]);
                    $backfilled++;
                    if ($codes === []) {
                        $emptyAllergens++;
                    }
                }
            });

        $verb = $dryRun ? 'would write' : 'wrote';
        $summary = sprintf(
            '[Backfill][Z3-NEW-005] scanned=%d %s=%d orphans=%d empty_allergens=%d dry_run=%s',
            $scanned,
            $verb,
            $backfilled,
            $orphans,
            $emptyAllergens,
            $dryRun ? 'yes' : 'no'
        );
        $this->info($summary);
        Log::info('[Backfill][Z3-NEW-005] complete', [
            'scanned'         => $scanned,
            'backfilled'      => $backfilled,
            'orphans'         => $orphans,
            'empty_allergens' => $emptyAllergens,
            'dry_run'         => $dryRun,
        ]);

        return self::SUCCESS;
    }

    /**
     * Pivot-first read path; allergen_flags JSON fallback for pre-pivot legacy items.
     * Mirrors `OrderItemAllergenSnapshot::resolveSnapshot()`.
     *
     * @return array<int, string>
     */
    private function resolveAllergenCodes(Item $item): array
    {
        $pivotCodes = $item->allergens()
            ->orderBy('sort')
            ->pluck('code')
            ->filter(fn ($code): bool => is_string($code) && $code !== '')
            ->values()
            ->all();

        if ($pivotCodes !== []) {
            return $pivotCodes;
        }

        // Legacy fallback: items that pre-date the item_allergen pivot stored their
        // codes in `allergen_flags` (JSON array). Cast 'array' → already decoded.
        $legacy = $item->allergen_flags ?? [];
        if (! is_array($legacy)) {
            return [];
        }

        return collect($legacy)
            ->filter(fn ($code): bool => is_string($code) && $code !== '')
            ->unique()
            ->values()
            ->all();
    }
}
