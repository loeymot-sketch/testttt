<?php

namespace App\Console\Commands;

use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Scopes\BranchScope;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * [audit-360 W7 owner-found 2026-06-22] Removes catalogue test-pollution (phantom/duplicate
 * products + test categories + test kiosk promos) that accumulates in non-production DBs from
 * abuse/e2e campaigns (items created via the admin API and never cleaned). The owner reported
 * fake product names + duplications displaying in the borne and the caisse.
 *
 * SAFETY: dry-run by DEFAULT (lists what it would remove). Pass --force to apply. Items and
 * categories use SoftDeletes, so a --force run is RECOVERABLE (restore()). Patterns are
 * deliberately narrow — only names that cannot be a real Le Cayenne menu item.
 *
 * Real V1 canonical = ~45 items / ~13 categories (Sandwich/Galette/Burgers/Tacos/Bols/
 * Boissons/etc.). None match these patterns.
 */
class CleanTestCatalogDataCommand extends Command
{
    protected $signature = 'catalog:clean-test-data {--force : actually soft-delete (default is dry-run)}';

    protected $description = 'Remove test-pollution items/categories/kiosk-promos from the catalogue (dry-run unless --force)';

    /** SQL LIKE patterns that can only be test-generated names, never a real menu product. */
    private array $patterns = [
        'E2E-%', '%E2E-AUDIT%', 'RED-TEAM-%', 'ZZ-TEST-%', 'wval%', '%-TestItem-%',
        'central-cat-%', '%CENTRAL-CAT-%', '% (copie)', '%TEST PROD%', '%Burger Test%',
    ];

    public function handle(): int
    {
        $force = (bool) $this->option('force');
        $this->info($force ? 'APPLYING catalogue test-data cleanup (soft-delete).' : 'DRY-RUN — nothing will be deleted. Pass --force to apply.');

        $items = $this->matches(Item::query()->withoutGlobalScopes()->whereNull('deleted_at'), 'name');
        $cats  = $this->matches(ItemCategory::query()->withoutGlobalScopes()->whereNull('deleted_at'), 'name');
        $promos = $this->promoMatches();

        $this->line('');
        $this->table(['Type', 'ID', 'Name'], array_merge(
            $items->map(fn ($r) => ['item', $r->id, $r->name])->all(),
            $cats->map(fn ($r) => ['category', $r->id, $r->name])->all(),
            $promos->map(fn ($r) => ['kiosk_promo', $r->id, $r->code ?? ''])->all(),
        ));
        $total = $items->count() + $cats->count() + $promos->count();
        $this->info("Matched $total test-pollution row(s): {$items->count()} items, {$cats->count()} categories, {$promos->count()} promos.");

        if (! $force) {
            $this->warn('Dry-run only. Re-run with --force to soft-delete (recoverable).');
            return self::SUCCESS;
        }

        if ($items->isNotEmpty()) {
            Item::withoutGlobalScopes()->whereIn('id', $items->pluck('id'))->delete();
        }
        if ($cats->isNotEmpty()) {
            ItemCategory::withoutGlobalScopes()->whereIn('id', $cats->pluck('id'))->delete();
        }
        if ($promos->isNotEmpty() && DB::getSchemaBuilder()->hasTable('kiosk_promos')) {
            DB::table('kiosk_promos')->whereIn('id', $promos->pluck('id'))->delete();
        }
        $this->info("Done. Soft-deleted $total row(s) (restore() to undo items/categories).");
        return self::SUCCESS;
    }

    private function matches($query, string $col)
    {
        return $query->where(function ($q) use ($col) {
            foreach ($this->patterns as $p) {
                $q->orWhere($col, 'like', $p);
            }
        })->get(['id', $col]);
    }

    private function promoMatches()
    {
        if (! DB::getSchemaBuilder()->hasTable('kiosk_promos')) {
            return collect();
        }
        return DB::table('kiosk_promos')->where(function ($q) {
            foreach (['BORNEAUDIT%', '%AUDIT%', 'E2E-%', 'TEST%', 'ZZ-%'] as $p) {
                $q->orWhere('code', 'like', $p);
            }
        })->get(['id', 'code']);
    }
}
