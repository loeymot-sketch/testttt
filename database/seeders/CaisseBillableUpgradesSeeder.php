<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Item;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [LOT A / ultra-audit 2026-06-10 — CAISSE-01 + CAISSE-01-BIS]
 *
 * Seeds the billable upgrade constructs that the frozen POS wizard patch
 * (public/js/pos-wizard.js:4142-4168, LOCK_CAISSE-01 signed 2026-06-09)
 * looks up BY NAME (/grande/i, /cheddar/i) on the frites items. The patch is
 * graceful-by-construction: it stayed dormant (upgrades shown +2,00 € but
 * billed 0,00 €) because no matching ItemExtra existed — the previous seeder
 * (AlignFritesWizardProfilesSeeder) targeted item ids 361/402/403 which do
 * not exist in the real Le Cayenne catalog.
 *
 * Items are resolved BY NAME (anti-drift: ids differ across DB resets);
 * idempotent via firstOrCreate-style checks.
 *
 * NOTE — viande supplémentaire (+2,50 €) and extra sauces (+0,50 €) CANNOT be
 * activated by data alone: the frozen wizard maps them through VARIATION ids
 * into extra-checkbox syncing (id-namespace mismatch, pos-wizard.js:3883-3894
 * + :3776-3800). Billing those requires a frozen-zone patch → owner gate
 * (LOCK_CAISSE-01 v2). Documented in GOAL_ULTRA_AUDIT_SYSTEMES §G.
 */
class CaisseBillableUpgradesSeeder extends Seeder
{
    /** Exact catalog names of the frites items carrying the upgrade step. */
    private const FRITES_ITEM_NAMES = [
        'Menu (Frites + Boisson)',
        'Frites Seules',
        'Petite Frites',
        'Grande Frites',
    ];

    private const UPGRADES = [
        ['name' => 'Grande Portion', 'price' => 1.00],
        ['name' => 'Cheddar Fondu',  'price' => 1.00],
    ];

    public function run(): void
    {
        $items = Item::query()
            ->whereIn('name', self::FRITES_ITEM_NAMES)
            ->whereNull('deleted_at')
            ->get(['id', 'name']);

        if ($items->isEmpty()) {
            $this->command?->warn('CaisseBillableUpgradesSeeder: no frites item found by name — catalog drift? Nothing seeded.');
            return;
        }

        $created = 0;
        foreach ($items as $item) {
            foreach (self::UPGRADES as $upgrade) {
                $exists = DB::table('item_extras')
                    ->where('item_id', $item->id)
                    ->where('name', $upgrade['name'])
                    ->whereNull('deleted_at')
                    ->exists();
                if ($exists) {
                    continue;
                }
                DB::table('item_extras')->insert([
                    'item_id'      => $item->id,
                    'name'         => $upgrade['name'],
                    'price'        => $upgrade['price'],
                    'is_available' => 1,
                    'status'       => Status::ACTIVE,
                    'created_at'   => now(),
                    'updated_at'   => now(),
                ]);
                $created++;
            }
        }

        $this->command?->info(sprintf(
            'CaisseBillableUpgradesSeeder: %d upgrade extras ensured on %d frites items (%s).',
            $created,
            $items->count(),
            $items->pluck('id')->implode(', ')
        ));
    }
}
