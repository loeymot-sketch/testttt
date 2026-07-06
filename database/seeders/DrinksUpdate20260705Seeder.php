<?php

namespace Database\Seeders;

use App\Enums\ItemType;
use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemCategory;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * [BOISSONS-UPDATE 2026-07-05] Owner : ajout de nouvelles boissons au menu (images fournies
 * dans public/images/menu). SSOT = table items (cat « boissons ») → apparait automatiquement
 * sur CAISSE + BORNE + KDS + web (funnel) et devient gerable en STOCK (rupture dashboard →
 * ItemBranchAvailability, synchronise sur toutes les surfaces).
 *
 * Idempotent : firstOrCreate par slug + restore si soft-deleted + refresh nom/prix/image.
 *   Run :  php artisan db:seed --class=DrinksUpdate20260705Seeder
 *
 * Images cablees dans config/menu_images.php (slug → fichier). Prix aligne au canon sodas 1,90
 * (l'owner peut ajuster dans l'admin). Fuze Tea / Hawaï / Perrier ajoutees avec une
 * image de REPLI distincte (owner n'a pas fourni le visuel dedie) — a swap 1 ligne des
 * reception de fuze-tea.png / hawai.png / perrier.png.
 * [OWNER8 2026-07-06] « Fanta Hawai 33cl » renomme « Hawaï 33cl » (slug hawai,
 * migration en place via legacy_slugs — pas de doublon, id conserve).
 */
class DrinksUpdate20260705Seeder extends Seeder
{
    public function run(): void
    {
        $cat = ItemCategory::where('slug', 'boissons')->whereNull('deleted_at')->first();
        if (! $cat) {
            $this->command->warn('  boissons category missing — abort.');

            return;
        }

        // slug DOIT correspondre a config/menu_images.php pour l'image.
        $drinks = [
            ['slug' => 'coca-cherry',  'name' => 'Coca Cherry 33cl',   'price' => 1.90, 'description' => 'Coca-Cola Cherry'],
            ['slug' => 'tropico',      'name' => 'Tropico 33cl',        'price' => 1.90, 'description' => 'Tropico'],
            ['slug' => 'ice-tea',      'name' => 'Ice Tea Pêche 33cl',  'price' => 1.90, 'description' => 'Ice Tea saveur pêche'],
            ['slug' => 'fanta-citron', 'name' => 'Fanta Citron 33cl',   'price' => 1.90, 'description' => 'Fanta Citron'],
            // [2026-07-05] 3 boissons demandées par l'owner SANS visuel dédié dans le
            // dossier fourni → image de repli DISTINCTE (config/menu_images.php), à
            // remplacer dès réception de fuze-tea.png / hawai.png / perrier.png.
            // Elles sont dès maintenant commandables + gérables en stock (SSOT items).
            ['slug' => 'fuze-tea',     'name' => 'Fuze Tea 33cl',       'price' => 1.90, 'description' => 'Fuze Tea'],
            // [OWNER8 2026-07-06] Renommage owner : « Fanta Hawai 33cl » → « Hawaï 33cl ».
            // legacy_slugs migre la ligne existante EN PLACE (slug + nom ; id conservé →
            // stock, commandes et disponibilité intacts ; jamais de doublon).
            ['slug' => 'hawai',        'name' => 'Hawaï 33cl',          'price' => 1.90, 'description' => 'Hawaï', 'legacy_slugs' => ['fanta-hawai']],
            ['slug' => 'perrier',      'name' => 'Perrier 33cl',        'price' => 1.90, 'description' => 'Perrier (eau gazeuse)'],
        ];

        $created = 0;
        DB::transaction(function () use ($drinks, $cat, &$created) {
            foreach ($drinks as $d) {
                $row = Item::where('slug', $d['slug'])->withTrashed()->first();
                // [OWNER8 2026-07-06] Migration renommage : retrouve la ligne sous son
                // slug LEGACY (ex. fanta-hawai) et la renomme en place (slug + nom).
                if (! $row && ! empty($d['legacy_slugs'])) {
                    $row = Item::whereIn('slug', $d['legacy_slugs'])->withTrashed()->first();
                }
                if ($row) {
                    if ($row->deleted_at) {
                        $row->restore();
                    }
                    $row->fill([
                        'item_category_id' => $cat->id,
                        'slug'             => $d['slug'],
                        'name'             => $d['name'],
                        'price'            => $d['price'],
                        'description'      => $d['description'],
                        'status'           => Status::ACTIVE,
                        'is_available'     => 1,
                        'is_halal'         => 1,
                        'item_type'        => ItemType::VEG,
                    ])->save();
                    $this->command->line("  drink refreshed: {$d['slug']} (id={$row->id})");

                    continue;
                }

                $new = Item::create([
                    'item_category_id' => $cat->id,
                    'slug'             => $d['slug'],
                    'name'             => $d['name'],
                    'price'            => $d['price'],
                    'description'      => $d['description'],
                    'kiosk_emoji'      => '',
                    'status'           => Status::ACTIVE,
                    'is_available'     => 1,
                    'is_featured'      => 0,
                    'is_halal'         => 1,
                    'is_vegetarian'    => 1,
                    'item_type'        => ItemType::VEG,
                    'kds_station'      => 'none',
                    'order'            => 0,
                ]);
                $created++;
                $this->command->line("  drink CREATED: {$d['slug']} (id={$new->id})");
            }
        });

        $this->command->info("  boissons-update: {$created} created, ".(count($drinks) - $created)." refreshed in cat={$cat->id}.");
    }
}
