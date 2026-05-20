<?php

namespace Database\Seeders;

use App\Models\Item;
use App\Services\AllergenService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * V1 Le Cayenne — Wave P Round 2-B (2026-05-19)
 *
 * Seeds applicable EU 1169/2011 (FIC) Annex II allergens for the 45 Le Cayenne
 * menu items. Required for production ship to France (legal compliance,
 * 14 mandatory allergen disclosure on prepared foods).
 *
 * Pattern (verified with advisor 2026-05-19):
 * - DB allergen codes are FRENCH (gluten, oeufs, lait, moutarde, sesame,
 *   sulfites, fruits_a_coque, etc.) — see `AllergensSeeder`.
 * - Source of truth = `item_allergen` pivot table. `items.allergen_flags`
 *   JSON column is a projected cache.
 * - `AllergenService::projectFlags($item)` syncs BOTH from a sequential
 *   array of codes assigned to `$item->allergen_flags` (cast = 'array').
 * - Items that are genuinely allergen-free (water, plain sodas, plain
 *   frites) get `[]` (= "checked, none") rather than NULL, so coverage
 *   is unambiguous.
 *
 * Idempotent: re-run = same final state (sync diffs the pivot, JSON cast
 * normalizes the order). Items not in the mapping are left untouched.
 *
 * Owner-decision-overridable: if a real chef provides corrections, the
 * mapping below is the single edit point — re-run the seeder.
 *
 * Conservative bias: when in doubt (commercial sauces typically contain
 * sulfites/mustard, restaurant breads typically contain gluten/eggs),
 * we OVER-flag rather than under-flag. Food safety errs cautious.
 *
 * Out of scope: traces / shared-fryer cross-contamination warnings.
 * Those should be displayed as a banner ("nos friteuses peuvent contenir
 * des traces de gluten") rather than per-item, which is consistent with
 * French fast-food allergen disclosure practice.
 */
class LeCayenneAllergenSeeder extends Seeder
{
    /**
     * Slug → array of French allergen codes from `allergens.code`.
     *
     * Rationale by category:
     * - Sandwiches/Galettes: bun = gluten+eggs+possibly sesame; cheese = lait;
     *   commercial sauces (mayo/burger/algerienne) = oeufs+moutarde+sulfites
     * - Tacos: tortilla = gluten; cheese sauce = lait; commercial sauces idem
     * - Burgers: bun = gluten+oeufs+sesame; cheese = lait; sauces idem
     * - Bowls: rice base = none; frites base = none; sauces vary by marinade
     *   (curry/tandoori contain milk+mustard; crispy = breaded so gluten)
     * - Frites seules: pure potato; cross-contamination noted via banner
     * - Suppléments cheese: lait; sauces: oeufs+moutarde+sulfites
     * - Desserts: tiramisu/tarte/glace as per recipe
     * - Boissons: none (sodas, water, capri-sun fruit juice)
     * - Menu enfant nuggets: gluten+oeufs+lait (breaded chicken + sauce)
     *
     * @var array<string, list<string>>
     */
    private array $mapping = [
        // === Sandwiches Cayenne (faluche bun + meat + cheese + sauces) ===
        'sandwich-cayenne-classique' => ['gluten', 'oeufs', 'lait', 'moutarde', 'sulfites'],
        'big-cayenne'                => ['gluten', 'oeufs', 'lait', 'moutarde', 'sulfites'],

        // === Sandwiches Classique (same faluche bun base) ===
        'sandwich-classique-faluche' => ['gluten', 'oeufs', 'lait', 'moutarde', 'sulfites'],
        'big-classique'              => ['gluten', 'oeufs', 'lait', 'moutarde', 'sulfites'],

        // === Galettes (galette = wheat flour wrap) ===
        'galette-normale'            => ['gluten', 'oeufs', 'lait', 'moutarde', 'sulfites'],
        'galette-cayenne'            => ['gluten', 'oeufs', 'lait', 'moutarde', 'sulfites'],

        // === Tacos (wheat tortilla + cheese sauce + sauces) ===
        'tacos-1-viande'             => ['gluten', 'lait', 'moutarde', 'sulfites'],
        'big-tacos-2-viandes'        => ['gluten', 'lait', 'moutarde', 'sulfites'],

        // === Burgers (sesame bun + egg in bread + cheese + sauces) ===
        'chicken-burger'             => ['gluten', 'oeufs', 'lait', 'sesame', 'moutarde', 'sulfites'],
        'big-chicken'                => ['gluten', 'oeufs', 'lait', 'sesame', 'moutarde', 'sulfites'],

        // === Bols Gourmands - Frites base (no rice gluten path) ===
        // Mariné = teriyaki-style (soja); Curry = curry sauce (moutarde, lait often);
        // Tandoori = tandoori (lait, moutarde); Crispy = breaded (gluten, oeufs).
        'bowl-frites-marine'         => ['soja', 'moutarde', 'sulfites'],
        'bowl-frites-curry'          => ['lait', 'moutarde', 'sulfites'],
        'bowl-frites-tandoori'       => ['lait', 'moutarde', 'sulfites'],
        'bowl-frites-crispy'         => ['gluten', 'oeufs', 'lait', 'moutarde', 'sulfites'],

        // === Bols Gourmands - Riz base ===
        'bowl-riz-marine'            => ['soja', 'moutarde', 'sulfites'],
        'bowl-riz-curry'             => ['lait', 'moutarde', 'sulfites'],
        'bowl-riz-tandoori'          => ['lait', 'moutarde', 'sulfites'],
        'bowl-riz-crispy'            => ['gluten', 'oeufs', 'lait', 'moutarde', 'sulfites'],

        // === Frites seules (pure potato; shared-oil cross-contam = banner) ===
        'petite-frites'              => [],
        'grande-frites'              => [],

        // === Menu (frites + boisson) — composer item, default frites+sodas ===
        'menu-frites-boisson'        => [],
        'frites-seules'              => [],
        'boisson-seule'              => [],

        // === Suppléments fromage (milk) ===
        'supp-cheddar'               => ['lait'],
        'supp-raclette'              => ['lait'],
        'supp-emmental'              => ['lait'],
        'supp-boursin'               => ['lait'],

        // === Suppléments autres ===
        'supp-oeuf'                  => ['oeufs'],
        'supp-legumes-sautes'        => [],
        'supp-jambon'                => ['sulfites'],         // ham typically nitrite/sulfite preserved
        'supp-oignons-frits'         => ['gluten'],           // fried in shared oil + often flour-coated
        'supp-champignons'           => [],
        'supp-boule-gratinee'        => ['gluten', 'lait'],   // bread bowl gratin

        // === Menu enfant (nuggets = breaded chicken + sauce) ===
        'menu-nuggets'               => ['gluten', 'oeufs', 'lait', 'moutarde'],

        // === Desserts ===
        'glace'                      => ['lait', 'oeufs'],
        'tarte-daim'                 => ['gluten', 'lait', 'oeufs', 'fruits_a_coque'],
        'tiramisu'                   => ['gluten', 'lait', 'oeufs'],

        // === Boissons (sodas, water, fruit juice — no mandatory allergens) ===
        'coca'                       => [],
        'coca-zero'                  => [],
        'fanta'                      => [],
        'sprite'                     => [],
        'oasis'                      => [],
        'orangina'                   => [],
        'eau-plate'                  => [],
        'capri-sun'                  => [],
    ];

    public function run(): void
    {
        $service = app(AllergenService::class);
        $codes = DB::table('allergens')->pluck('code')->all();

        foreach ($this->mapping as $slug => $allergens) {
            // Validate codes (defense in depth: typo guard).
            foreach ($allergens as $code) {
                if (!in_array($code, $codes, true)) {
                    throw new \RuntimeException(
                        "LeCayenneAllergenSeeder: unknown allergen code '{$code}' for slug '{$slug}'. "
                        . 'Valid codes: ' . implode(', ', $codes)
                    );
                }
            }

            $item = Item::query()->where('slug', $slug)->first();
            if (!$item) {
                // Slug not present (e.g. seeder run on partial menu). Skip.
                continue;
            }

            if ($allergens === []) {
                // "Checked, none" — explicit empty disclosure. AllergenService::projectFlags()
                // ignores empty input (it would read the pivot back), so we write directly.
                $item->allergen_flags = [];
                $item->allergens()->sync([]);
                $item->save();
                continue;
            }

            // Assign as sequential array → projectFlags() normalizes order and
            // syncs the pivot (item_allergen) + rewrites the JSON cache.
            $item->allergen_flags = $allergens;
            $service->projectFlags($item);
            $item->save();
        }
    }
}
