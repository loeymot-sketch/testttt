<?php

namespace Database\Seeders;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * ============================================================================
 * Wave O — O7 RESTORE : Le Cayenne Desserts + Boissons + addon visibility heal
 * ============================================================================
 *
 * Restores the 3 desserts (cat=9) + 8 drinks (cat=10) that the canonical
 * artisan chain (menu:reset-le-cayenne -> menu:heal-light-v2 -> v2-round2 -> v3
 * -> v3.1) does NOT seed. Owner reported (2026-05-20) that these categories
 * were populated in the historical menu state and got lost in a wipe.
 *
 * SSOT mirror : mobile/data/menu.js — DRINKS cat 10 (8 items) + DESSERTS cat 9
 * (3 items). Prices + slugs match menu.js exactly.
 *
 * Also re-classifies the 3 menu-addon items (menu-frites-boisson, frites-seules,
 * boisson-seule) so they remain resolvable by slug (used by heal-light-v2
 * preflight + composer step 5 menu_component addon) but no longer pollute the
 * sandwich-cayenne category in POS / Kiosk browse. They are set to
 * channels=["admin"] which the PosCategoryController query treats as "not POS".
 *
 * Idempotent : firstOrCreate by slug, updateOrInsert pattern for channel patch.
 *
 * Run :  php artisan db:seed --class=RestoreLeCayenneDessertsAndDrinksSeeder
 *
 * @see app/Console/Commands/MenuResetLeCayenneCommand.php (sibling SSOT)
 * @see mobile/data/menu.js (DRINKS + DESSERTS arrays)
 * @see app/Http/Controllers/Admin/PosCategoryController.php (channel filter)
 */
class RestoreLeCayenneDessertsAndDrinksSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $this->command->info('Wave O — O7 : Restore Le Cayenne desserts + boissons + addon visibility heal');

        DB::transaction(function () {
            $this->restoreDesserts();
            $this->restoreDrinks();
            $this->hideMenuAddonsFromPosBrowse();
        });

        $this->command->info('Wave O — O7 : restore complete.');
    }

    private function restoreDesserts(): void
    {
        $cat = ItemCategory::where('slug', 'desserts')->whereNull('deleted_at')->first();
        if (! $cat) {
            $this->command->warn('  desserts category missing — skipping desserts.');
            return;
        }

        $desserts = [
            [
                'slug'        => 'glace',
                'name'        => 'Glace',
                'price'       => 3.80,
                'description' => 'Glace artisanale',
                'kiosk_emoji' => '',
            ],
            [
                'slug'        => 'tarte-daim',
                'name'        => 'Tarte Daim',
                'price'       => 3.80,
                'description' => 'Tarte au Daim',
                'kiosk_emoji' => '',
            ],
            [
                'slug'        => 'tiramisu',
                'name'        => 'Tiramisu',
                'price'       => 3.80,
                'description' => 'Tiramisu maison',
                'kiosk_emoji' => '',
            ],
        ];

        $created = 0;
        foreach ($desserts as $d) {
            $row = Item::where('slug', $d['slug'])->withTrashed()->first();
            if ($row) {
                if ($row->deleted_at) {
                    $row->restore();
                }
                $row->fill([
                    'item_category_id' => $cat->id,
                    'name'             => $d['name'],
                    'price'            => $d['price'],
                    'description'      => $d['description'],
                    'kiosk_emoji'      => $d['kiosk_emoji'],
                    'status'           => Status::ACTIVE,
                    'is_available'     => 1,
                    'is_halal'         => 0,
                    'item_type'        => \App\Enums\ItemType::VEG,
                ])->save();
                $this->command->line("  desserts: id={$row->id} {$d['slug']} refreshed.");
                continue;
            }

            $created++;
            $new = Item::create([
                'item_category_id' => $cat->id,
                'slug'             => $d['slug'],
                'name'             => $d['name'],
                'price'            => $d['price'],
                'description'      => $d['description'],
                'kiosk_emoji'      => $d['kiosk_emoji'],
                'status'           => Status::ACTIVE,
                'is_available'     => 1,
                'is_featured'      => 0,
                'is_halal'         => 0,
                'is_vegetarian'    => 1,
                'item_type'        => \App\Enums\ItemType::VEG,
                'kds_station'      => 'none',
                'order'            => 0,
            ]);
            $this->command->line("  desserts: id={$new->id} {$d['slug']} CREATED.");
        }

        $this->command->info("  desserts: {$created} created in cat={$cat->id}.");
    }

    private function restoreDrinks(): void
    {
        $cat = ItemCategory::where('slug', 'boissons')->whereNull('deleted_at')->first();
        if (! $cat) {
            $this->command->warn('  boissons category missing — skipping drinks.');
            return;
        }

        $drinks = [
            // [MENU-CANON 2026-06-26] sodas alignés au canon Le Cayenne 1,90 (était 1,50,
            // superseded par OwnerMenuUpdate20260623Seeder ; ce seeder ne doit plus régresser
            // la DB à un re-run). Eau 1,00 et Capri-Sun 1,50 restent au canon.
            ['slug' => 'coca',        'name' => 'Coca-Cola 33cl',      'price' => 1.90, 'description' => 'Coca-Cola original',   'kiosk_emoji' => ''],
            ['slug' => 'coca-zero',   'name' => 'Coca-Cola Zero 33cl', 'price' => 1.90, 'description' => 'Coca-Cola sans sucre', 'kiosk_emoji' => ''],
            ['slug' => 'fanta',       'name' => 'Fanta Orange 33cl',   'price' => 1.90, 'description' => 'Fanta Orange',         'kiosk_emoji' => ''],
            ['slug' => 'sprite',      'name' => 'Sprite 33cl',         'price' => 1.90, 'description' => 'Sprite',               'kiosk_emoji' => ''],
            ['slug' => 'oasis',       'name' => 'Oasis Tropical 33cl', 'price' => 1.90, 'description' => 'Oasis Tropical',       'kiosk_emoji' => ''],
            ['slug' => 'orangina',    'name' => 'Orangina 33cl',       'price' => 1.90, 'description' => 'Orangina',             'kiosk_emoji' => ''],
            ['slug' => 'eau-plate',   'name' => 'Eau Plate 50cl',      'price' => 1.00, 'description' => 'Eau minérale',         'kiosk_emoji' => ''],
            ['slug' => 'capri-sun',   'name' => 'Capri-Sun',           'price' => 1.50, 'description' => 'Capri-Sun 20cl',       'kiosk_emoji' => ''],
        ];

        $created = 0;
        foreach ($drinks as $d) {
            $row = Item::where('slug', $d['slug'])->withTrashed()->first();
            if ($row) {
                if ($row->deleted_at) {
                    $row->restore();
                }
                $row->fill([
                    'item_category_id' => $cat->id,
                    'name'             => $d['name'],
                    'price'            => $d['price'],
                    'description'      => $d['description'],
                    'kiosk_emoji'      => $d['kiosk_emoji'],
                    'status'           => Status::ACTIVE,
                    'is_available'     => 1,
                    'is_halal'         => 1,
                    'item_type'        => \App\Enums\ItemType::VEG,
                ])->save();
                $this->command->line("  drinks: id={$row->id} {$d['slug']} refreshed.");
                continue;
            }

            $created++;
            $new = Item::create([
                'item_category_id' => $cat->id,
                'slug'             => $d['slug'],
                'name'             => $d['name'],
                'price'            => $d['price'],
                'description'      => $d['description'],
                'kiosk_emoji'      => $d['kiosk_emoji'],
                'status'           => Status::ACTIVE,
                'is_available'     => 1,
                'is_featured'      => 0,
                'is_halal'         => 1,
                'is_vegetarian'    => 1,
                'item_type'        => \App\Enums\ItemType::VEG,
                'kds_station'      => 'none',
                'order'            => 0,
            ]);
            $this->command->line("  drinks: id={$new->id} {$d['slug']} CREATED.");
        }

        $this->command->info("  drinks: {$created} created in cat={$cat->id}.");
    }

    /**
     * Re-classify the 3 menu-addon items (menu-frites-boisson, frites-seules,
     * boisson-seule) so they no longer appear in POS / Kiosk category browse.
     *
     * They MUST remain queryable by slug (heal-light-v2 preflight + composer
     * step 5 menu_component addon depend on them). We set channels=["admin"]
     * — PosCategoryController filters items by `whereNull('channels') OR
     * whereJsonContains('channels','pos')`, so non-null channels NOT containing
     * "pos" hide the item from POS browse while keeping it in DB.
     */
    private function hideMenuAddonsFromPosBrowse(): void
    {
        $addonSlugs = ['menu-frites-boisson', 'frites-seules', 'boisson-seule'];
        $patched = 0;

        foreach ($addonSlugs as $slug) {
            $row = Item::where('slug', $slug)->first();
            if (! $row) {
                $this->command->warn("  addon-hide: slug={$slug} not found, skipping.");
                continue;
            }

            $targetChannels = ['admin'];
            $currentChannels = is_array($row->channels) ? $row->channels : (is_string($row->channels) ? json_decode($row->channels, true) : null);

            if (is_array($currentChannels) && ! in_array('pos', $currentChannels, true) && ! in_array('kiosk', $currentChannels, true)) {
                $this->command->line("  addon-hide: id={$row->id} {$slug} already hidden — skipping.");
                continue;
            }

            $row->channels = $targetChannels;
            $row->save();
            $patched++;
            $this->command->line("  addon-hide: id={$row->id} {$slug} channels=[admin] applied.");
        }

        $this->command->info("  addon-hide: {$patched} item(s) channel-patched.");
    }
}
