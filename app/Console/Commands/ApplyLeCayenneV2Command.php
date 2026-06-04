<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Apply Le Cayenne V2 owner-curated catalog refresh (2026-05-21).
 *
 * Idempotent maintenance command:
 *   1. Sync canonical 13 sauces on every item that has a "Sauce *" attribute
 *      step. Deletes deprecated sauce options, inserts the 12 + Sans Sauce.
 *   2. Clean Spatie media rows for collections 'item' / 'item-category' so the
 *      slug-keyed config/menu_images.php mapping drives image resolution.
 *   3. Refresh DB-level pages (Cookies / Contact) and site copyright to drop
 *      FoodKing strings and use Le Cayenne brand.
 *   4. Clear caches (config + view) so refreshed config + assets ship.
 *
 * Safe to re-run. Past orders' composition_snapshot is immutable (NF525) and
 * not touched. Only future wizard options are affected.
 */
class ApplyLeCayenneV2Command extends Command
{
    protected $signature = 'menu:apply-le-cayenne-v2 {--dry-run : show planned changes without executing}';

    protected $description = 'Apply Le Cayenne V2 catalog refresh (canonical 13 sauces + Spatie media cleanup + DB branding).';

    /** Canonical 13 sauces (owner mandate 2026-05-21). */
    private const CANONICAL_SAUCES = [
        'Mayonnaise',
        'Ketchup',
        'Blanche',
        'Hannibal',
        'Samouraï',
        'Algérienne',
        'Andalouse',
        'Curry',
        'Barbecue',
        'Harissa',
        'Sauce Fromagère Maison',
        'Sauce Spicy Maison',
        'Sans Sauce',
    ];

    /** Renames from legacy DB names → canonical names (handled before delete). */
    private const SAUCE_RENAMES = [
        'Spicy'                  => 'Sauce Spicy Maison',
        'Sauce fromagère maison' => 'Sauce Fromagère Maison',
        'Sauce fromagere maison' => 'Sauce Fromagère Maison',
        'Samurai'                => 'Samouraï',
        'Algerienne'             => 'Algérienne',
    ];

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        if ($dryRun) {
            $this->warn('DRY RUN — no DB writes.');
        }

        $this->info('=== Step 1/4: Sync canonical 13 sauces ===');
        $this->syncSauces($dryRun);

        $this->info('=== Step 2/4: Clean Spatie media for items + categories ===');
        $this->cleanSpatieMedia($dryRun);

        $this->info('=== Step 3/4: Refresh DB branding (pages + footer) ===');
        $this->refreshBranding($dryRun);

        $this->info('=== Step 4/5: Invalidate kiosk menu cache + bump MenuSnapshot ===');
        if (! $dryRun) {
            // PR review P0-1: bust the kiosk menu cache + bump snapshot version
            // for every branch so the kiosk/POS/web clients pull the refreshed
            // projection instead of serving the pre-update payload from cache.
            foreach (DB::table('branches')->pluck('id') as $branchId) {
                \Cache::forget("kiosk.menu.branch.{$branchId}");
                if (class_exists(\App\Services\Menu\MenuSnapshot::class)) {
                    try {
                        \App\Services\Menu\MenuSnapshot::make()->bump((int) $branchId);
                    } catch (\Throwable $e) {
                        $this->warn("  MenuSnapshot::bump({$branchId}) failed: {$e->getMessage()}");
                    }
                }
            }
            $this->info('  branches invalidated: ' . DB::table('branches')->count());
        }

        $this->info('=== Step 5/5: Clear caches ===');
        if (! $dryRun) {
            $this->call('cache:clear');
            $this->call('config:clear');
            $this->call('view:clear');
        }

        $this->info('✓ Le Cayenne V2 applied.');

        return self::SUCCESS;
    }

    private function syncSauces(bool $dryRun): void
    {
        $sauceAttrIds = DB::table('item_attributes')
            ->whereRaw('LOWER(name) LIKE ?', ['%sauce%'])
            ->pluck('id')
            ->all();

        if (! $sauceAttrIds) {
            $this->warn('  No sauce attributes found, skipping.');
            return;
        }

        // (item_id, attribute_id) pairs that have sauce variations.
        $pairs = DB::table('item_variations')
            ->whereIn('item_attribute_id', $sauceAttrIds)
            ->select('item_id', 'item_attribute_id')
            ->distinct()
            ->get();

        $renamed = 0;
        $deleted = 0;
        $inserted = 0;

        foreach ($pairs as $pair) {
            $current = DB::table('item_variations')
                ->where('item_id', $pair->item_id)
                ->where('item_attribute_id', $pair->item_attribute_id)
                ->get(['id', 'name']);

            // 1. Rename legacy names to canonical.
            foreach ($current as $row) {
                if (isset(self::SAUCE_RENAMES[$row->name])) {
                    $target = self::SAUCE_RENAMES[$row->name];
                    if (! $dryRun) {
                        DB::table('item_variations')
                            ->where('id', $row->id)
                            ->update(['name' => $target, 'updated_at' => now()]);
                    }
                    $renamed++;
                }
            }

            // Refresh after rename so duplicate detection uses canonical names.
            $current = DB::table('item_variations')
                ->where('item_id', $pair->item_id)
                ->where('item_attribute_id', $pair->item_attribute_id)
                ->get(['id', 'name']);

            $currentNames = $current->pluck('name')->all();

            // 2. Delete names not in canonical 13.
            foreach ($current as $row) {
                if (! in_array($row->name, self::CANONICAL_SAUCES, true)) {
                    if (! $dryRun) {
                        DB::table('item_variations')->where('id', $row->id)->delete();
                    }
                    $deleted++;
                }
            }

            // 3. Insert canonical names that are missing.
            $price = DB::table('item_variations')
                ->where('item_id', $pair->item_id)
                ->where('item_attribute_id', $pair->item_attribute_id)
                ->orderBy('id')
                ->value('price') ?? 0;

            foreach (self::CANONICAL_SAUCES as $canonical) {
                if (! in_array($canonical, $currentNames, true)) {
                    if (! $dryRun) {
                        DB::table('item_variations')->insert([
                            'item_id'           => $pair->item_id,
                            'item_attribute_id' => $pair->item_attribute_id,
                            'name'              => $canonical,
                            'price'             => $price,
                            'caution'           => 0,
                            'status'            => \App\Enums\Status::ACTIVE, // 5 — kiosk/POS filter requirement
                            'visible_on'        => null,
                            'created_at'        => now(),
                            'updated_at'        => now(),
                        ]);
                    }
                    $inserted++;
                }
            }
        }

        $this->info("  pairs scanned: " . count($pairs));
        $this->info("  renamed: $renamed | deleted: $deleted | inserted: $inserted");
    }

    private function cleanSpatieMedia(bool $dryRun): void
    {
        $itemMedia = DB::table('media')
            ->whereIn('model_type', ['App\\Models\\Item', 'App\\Models\\ItemCategory'])
            ->whereIn('collection_name', ['item', 'item-category'])
            ->get(['id', 'model_type', 'model_id', 'file_name', 'disk']);

        $this->info('  media rows to remove: ' . $itemMedia->count());

        foreach ($itemMedia as $m) {
            if (! $dryRun) {
                // Delete physical file (best-effort).
                try {
                    Storage::disk($m->disk)->deleteDirectory((string) $m->id);
                } catch (\Throwable $e) {
                    // ignore — file may already be gone
                }
                DB::table('media')->where('id', $m->id)->delete();
            }
        }
    }

    private function refreshBranding(bool $dryRun): void
    {
        if (! $dryRun) {
            // Pages: replace FoodKing in user-visible page descriptions.
            $affected = DB::table('pages')
                ->whereIn('slug', ['cookies-policy', 'about-us', 'contact-us'])
                ->get(['id', 'slug', 'description']);

            foreach ($affected as $p) {
                $desc = (string) $p->description;
                $new = str_replace(
                    ['FoodKing'],
                    ['Le Cayenne'],
                    $desc
                );
                if ($new !== $desc) {
                    DB::table('pages')->where('id', $p->id)->update([
                        'description' => $new,
                        'updated_at'  => now(),
                    ]);
                    $this->info("  page '{$p->slug}' rebranded.");
                }
            }

            // Settings: site_copyright (group=site) — replace FoodKing if present.
            $copyrightRow = DB::table('settings')->where('key', 'site_copyright')->first();
            if ($copyrightRow && str_contains((string) $copyrightRow->payload, 'FoodKing')) {
                $newPayload = str_replace('FoodKing SaaS', 'Le Cayenne', $copyrightRow->payload);
                $newPayload = str_replace('FoodKing', 'Le Cayenne', $newPayload);
                DB::table('settings')->where('id', $copyrightRow->id)->update([
                    'payload'    => $newPayload,
                    'updated_at' => now(),
                ]);
                $this->info('  setting site_copyright rebranded.');
            }

            // mail_from_name
            $mfn = DB::table('settings')->where('key', 'mail_from_name')->first();
            if ($mfn && str_contains((string) $mfn->payload, 'FoodKing')) {
                DB::table('settings')->where('id', $mfn->id)->update([
                    'payload'    => str_replace('FoodKing - Inilabs Food Manager', 'Le Cayenne', $mfn->payload),
                    'updated_at' => now(),
                ]);
                $this->info('  setting mail_from_name rebranded.');
            }
        } else {
            $this->info('  (dry-run) would scan pages + site_copyright + mail_from_name.');
        }
    }
}
