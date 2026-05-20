<?php

namespace Database\Seeders;

use App\Models\Item;
use Illuminate\Database\Seeder;
use Throwable;

/**
 * ============================================================================
 * Wave O — O8 RESTORE : Le Cayenne ALL product images via Spatie MediaLibrary
 * ============================================================================
 *
 * Owner reported (2026-05-20): "Il n'y a aucun produit en image. Je veux que
 * tu remettes toutes les images comme avant." After the menu wipe / reseed
 * chain, Spatie media records for items were lost. Only 3 items (ids 1, 2, 3
 * = menu/frites/boisson addons) retained their item-default placeholder.
 *
 * This seeder attaches one primary image per item via the 'item' collection
 * (matching Item model registerMediaConversions: thumb / cover / preview).
 *
 * SOURCES (in priority order, first that exists wins):
 *   1. `storage/app/public/<id>/<owner-photo>.png` — high-res owner photos
 *      placed during the V3.0 / V3.1 menu heal cycles (big_cayenne, galette,
 *      bowls, big_burger, chicken_burger, etc.)
 *   2. `public/images/menu/<filename>.png` — studio PNGs catalogued in
 *      config/menu_images.php (drinks, desserts, fallbacks)
 *
 * Idempotent: skips any item that already has media in the 'item' collection.
 * Logs each attempt with status: attached / skipped / no-source.
 *
 * Run:  php artisan db:seed --class=RestoreLeCayenneItemImagesSeeder
 * Verify: php artisan tinker --execute='echo App\Models\Item::has("media")->count();'
 *
 * Frozen-zone: 0 touch. NF525: not impacted (media only).
 *
 * @see app/Models/Item.php (HasMedia / registerMediaConversions 'item')
 * @see config/menu_images.php (legacy slug-based mapping reference)
 * @see app/Console/Commands/MenuHealLightV3Command.php (V3 attachProductImages pattern)
 */
class RestoreLeCayenneItemImagesSeeder extends Seeder
{
    /**
     * Mapping: item slug → ordered list of candidate source paths (relative
     * to repo root). First path that exists on disk wins.
     *
     * High-res owner photos in storage/app/public/<id>/ live ABOVE
     * public/images/menu/*.png studio PNGs in priority — owner explicitly
     * placed those during V3 heal cycles.
     */
    private const IMAGE_SOURCES = [
        // ─────────────────────────────────────────────────────────────────
        // cat 1 — Sandwich Cayenne (+ addons)
        // ─────────────────────────────────────────────────────────────────
        'menu-frites-boisson'        => ['public/images/menu/menu_complet.png'],
        'frites-seules'              => ['public/images/menu/frites.png'],
        'boisson-seule'              => ['public/images/menu/boisson.png'],
        'sandwich-cayenne-classique' => [
            'storage/app/public/249/sandwich_cayenne.png',
            'public/images/menu/sandwich_cayenne.png',
        ],
        'big-cayenne' => [
            'storage/app/public/79/big_cayenne_2v_avec_oeuf_cheddar.png',
            'public/images/menu/sandwich_cayenne.png',
        ],

        // ─────────────────────────────────────────────────────────────────
        // cat 2 — Galette
        // ─────────────────────────────────────────────────────────────────
        'galette-normale' => [
            'public/images/menu/generated_sandwich-classique-galette.png',
            'storage/app/public/81/galette-tondory-cayenne.png',
        ],
        'galette-cayenne' => [
            'storage/app/public/81/galette-tondory-cayenne.png',
            'public/images/menu/generated_sandwich-classique-galette.png',
        ],

        // ─────────────────────────────────────────────────────────────────
        // cat 3 — Sandwich Classique
        // ─────────────────────────────────────────────────────────────────
        'sandwich-classique-faluche' => [
            'public/images/menu/generated_sandwich-classique-pain.png',
            'public/images/menu/sandwich_terminator.png',
        ],
        'big-classique' => [
            'public/images/menu/sandwich_terminator.png',
            'public/images/menu/sandwich_mega.png',
        ],

        // ─────────────────────────────────────────────────────────────────
        // cat 4 — Burgers
        // ─────────────────────────────────────────────────────────────────
        'chicken-burger' => [
            'storage/app/public/79/chicken_burger.png',  // 764KB owner photo
            'public/images/menu/chicken_burger.png',
            'storage/app/public/307/chicken_burger.png',
        ],
        'big-chicken' => [
            'public/images/menu/big_burger.png',  // 750KB
            'storage/app/public/79/chicken_burger.png',
        ],

        // ─────────────────────────────────────────────────────────────────
        // cat 5 — Tacos
        // ─────────────────────────────────────────────────────────────────
        'tacos-1-viande' => [
            'public/images/menu/tacos.png',
            'storage/app/public/61/tacos.png',
        ],
        'big-tacos-2-viandes' => [
            'public/images/menu/tacos.png',
            'storage/app/public/61/tacos.png',
        ],

        // ─────────────────────────────────────────────────────────────────
        // cat 6 — Bols Gourmands (8 dedicated owner photos in /87-94/)
        // Naming convention from owner: chicken=mariné, curie=curry,
        // tondory=tandoori, cryspie=crispy
        // ─────────────────────────────────────────────────────────────────
        'bowl-frites-marine'   => ['storage/app/public/87/bol-frite-chicken.png'],
        'bowl-frites-curry'    => ['storage/app/public/88/bol-frite-curie.png'],
        'bowl-frites-tandoori' => ['storage/app/public/89/bol-frite-tondory.png'],
        'bowl-frites-crispy'   => ['storage/app/public/90/bol-frite-cryspie.png'],
        'bowl-riz-marine'      => ['storage/app/public/91/bol-riz-chicken.png'],
        'bowl-riz-curry'       => ['storage/app/public/92/bol-riz-curie.png'],
        'bowl-riz-tandoori'    => ['storage/app/public/93/bol-riz-tondory.png'],
        'bowl-riz-crispy'      => ['storage/app/public/94/bol-riz-cryspie.png'],

        // ─────────────────────────────────────────────────────────────────
        // cat 7 — Frites
        // ─────────────────────────────────────────────────────────────────
        'petite-frites' => ['public/images/menu/frites.png'],
        'grande-frites' => ['public/images/menu/frites.png'],

        // ─────────────────────────────────────────────────────────────────
        // cat 8 — Suppléments
        // ─────────────────────────────────────────────────────────────────
        'supp-cheddar'         => ['public/images/menu/supplement_cheddar.png'],
        'supp-raclette'        => ['public/images/menu/supplement_raclette.png'],
        'supp-emmental'        => ['public/images/menu/supplement_fromage.png'],
        'supp-oeuf'            => ['public/images/menu/supplement_oeuf.png'],
        'supp-legumes-sautes'  => ['public/images/menu/supplement_fromage.png'], // no veg supp PNG — generic fallback (TODO owner upload)
        'supp-jambon'          => ['public/images/menu/supplement_jambon.png'],
        'supp-oignons-frits'   => ['public/images/menu/supplement_fromage.png'], // no oignon PNG (TODO)
        'supp-champignons'     => ['public/images/menu/supplement_fromage.png'], // no champignon PNG (TODO)
        'supp-boule-gratinee'  => ['public/images/menu/supplement_galette.png'],
        'supp-boursin'         => ['public/images/menu/supplement_boursin.png'],

        // ─────────────────────────────────────────────────────────────────
        // cat 9 — Desserts
        // ─────────────────────────────────────────────────────────────────
        'glace'      => ['public/images/menu/glace.png'],
        'tarte-daim' => ['public/images/menu/tarte_daim.png'],
        'tiramisu'   => ['public/images/menu/tiramisu.png'],

        // ─────────────────────────────────────────────────────────────────
        // cat 10 — Boissons
        // ─────────────────────────────────────────────────────────────────
        'coca'      => ['public/images/menu/coca_cola.png'],
        'coca-zero' => ['public/images/menu/coca_zero.png'],
        'fanta'     => ['public/images/menu/fanta.png'],
        'sprite'    => ['public/images/menu/sprite.png'],
        'oasis'     => ['public/images/menu/oasis_tropical.png'],
        'orangina'  => ['public/images/menu/orangina.png'],
        'eau-plate' => ['public/images/menu/eau.png'],
        'capri-sun' => ['public/images/menu/capri_sun.png'],

        // ─────────────────────────────────────────────────────────────────
        // cat 11 — Menu enfant
        // ─────────────────────────────────────────────────────────────────
        'menu-nuggets' => [
            'public/images/menu/generated_menu-nuggets-enfant.png',
            'public/images/menu/viande_nuggets.png',
            'public/images/menu/tenders.png',
        ],
    ];

    /** @var array{attached:int,skipped:int,no_source:int,error:int,no_mapping:int} */
    private array $stats = [
        'attached'   => 0,
        'skipped'    => 0,
        'no_source'  => 0,
        'error'      => 0,
        'no_mapping' => 0,
    ];

    /** @var string[] Items where no source image was found — owner manual upload needed */
    private array $todoOwner = [];

    public function run(): void
    {
        $this->command->info('Wave O — O8 : Restore Le Cayenne product images (Spatie MediaLibrary)');
        $this->command->line('');

        $repoRoot = base_path();

        foreach (Item::orderBy('id')->get() as $item) {
            $slug = $item->slug;

            // 1. Idempotency — skip if item already has media in 'item' collection.
            if ($item->media()->where('collection_name', 'item')->exists()) {
                $this->stats['skipped']++;
                $this->command->line(sprintf(
                    '  [%3d] %-40s SKIP (already has media)',
                    $item->id,
                    $slug
                ));
                continue;
            }

            // 2. Look up candidate sources.
            $candidates = self::IMAGE_SOURCES[$slug] ?? [];
            if (empty($candidates)) {
                $this->stats['no_mapping']++;
                $this->todoOwner[] = "{$item->id} | {$slug} | {$item->name} (no mapping in seeder)";
                $this->command->warn(sprintf(
                    '  [%3d] %-40s NO MAPPING (TODO owner upload)',
                    $item->id,
                    $slug
                ));
                continue;
            }

            // 3. Find first existing source.
            $sourcePath = null;
            foreach ($candidates as $rel) {
                $abs = $repoRoot . '/' . $rel;
                if (is_file($abs)) {
                    $sourcePath = $abs;
                    break;
                }
            }

            if (! $sourcePath) {
                $this->stats['no_source']++;
                $this->todoOwner[] = "{$item->id} | {$slug} | {$item->name} (mapping exists but no file found: "
                    . implode(', ', $candidates) . ')';
                $this->command->warn(sprintf(
                    '  [%3d] %-40s NO SOURCE FILE EXISTS (TODO owner)',
                    $item->id,
                    $slug
                ));
                continue;
            }

            // 4. Attach via Spatie MediaLibrary — collection 'item'.
            try {
                $item->addMedia($sourcePath)
                    ->preservingOriginal()
                    ->toMediaCollection('item');

                $this->stats['attached']++;
                $this->command->info(sprintf(
                    '  [%3d] %-40s OK   <- %s',
                    $item->id,
                    $slug,
                    str_replace($repoRoot . '/', '', $sourcePath)
                ));
            } catch (Throwable $e) {
                $this->stats['error']++;
                $this->command->error(sprintf(
                    '  [%3d] %-40s FAIL : %s',
                    $item->id,
                    $slug,
                    $e->getMessage()
                ));
            }
        }

        $this->renderSummary();
    }

    private function renderSummary(): void
    {
        $this->command->line('');
        $this->command->line('═══════════════════════════════════════════════════════');
        $this->command->line(' Wave O — O8 image restore summary');
        $this->command->line('═══════════════════════════════════════════════════════');
        $this->command->line('  attached       : ' . $this->stats['attached']);
        $this->command->line('  skipped (had)  : ' . $this->stats['skipped']);
        $this->command->line('  no source file : ' . $this->stats['no_source']);
        $this->command->line('  no mapping     : ' . $this->stats['no_mapping']);
        $this->command->line('  errors         : ' . $this->stats['error']);
        $this->command->line('  total items    : ' . Item::count());
        $this->command->line('═══════════════════════════════════════════════════════');

        if (! empty($this->todoOwner)) {
            $this->command->line('');
            $this->command->warn('TODO — Owner manual upload via Admin > Items needed for:');
            foreach ($this->todoOwner as $line) {
                $this->command->line('  - ' . $line);
            }
        }
    }
}
