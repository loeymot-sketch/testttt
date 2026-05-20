<?php

namespace Tests\Feature\Sentinels;

use App\Models\Item;
use Database\Seeders\LeCayenneAllergenSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * [V1 Le Cayenne — Wave P Round 2-B sentinel, downgraded by Wave Q-4 2026-05-20]
 *
 * ## Why this test is currently @group manual (not run in CI)
 *
 * The original sentinel (2026-05-19) asserted `>=80%` per-item allergen
 * coverage on the Le Cayenne menu, backed by `LeCayenneAllergenSeeder`. On
 * 2026-05-20 the owner identified that the seeded allergens were guessed by
 * the orchestrator (not chef-confirmed) — they were retracted (the seeder is
 * now a NOOP, `items.allergen_flags = []` for all items, `item_allergen`
 * pivot truncated). See `database/seeders/LeCayenneAllergenSeeder.php`
 * docblock for the full audit trail.
 *
 * The CI threshold no longer holds. Rather than delete the assertions
 * (which would erase the FIC-compliance acceptance criteria), we mark them
 * `@group manual`:
 *
 *  - CI: skipped (does not block ship of V1 LOCAL post owner decision).
 *  - Manual run: `vendor/bin/phpunit --group manual --filter
 *    AllergenCoverageSentinelTest` once real chef data is populated AND the
 *    seeder body is restored to a non-empty mapping.
 *
 * ## Re-enabling for production ship to France
 *
 * EU Regulation 1169/2011 (FIC) Annex II + EAA 2025 require disclosure of
 * the 14 mandatory allergens on prepared foods. To re-enable this sentinel
 * as a CI gate, follow these steps:
 *
 *  1. Owner / chef provides real per-item allergens (signed-off list).
 *  2. Either populate them via an admin UI editor (preferred — restaurant
 *     team owns the data) OR replace the body of
 *     `LeCayenneAllergenSeeder::run()` with the confirmed mapping.
 *  3. Update `REQUIRED_ALLERGENS` and `EXPLICITLY_NONE` below to match the
 *     real menu (the existing values are heuristic and were defined against
 *     the retracted guess).
 *  4. Remove the `@group manual` annotations (or convert them to `@test`)
 *     so the sentinel runs in CI.
 *  5. Document the gate in `docs/BUSINESS_RULES.md` ("FIC compliance gate
 *     active").
 *
 * ## SSOT consistency assertion preserved
 *
 * The `ssot_cache_consistency_holds_post_seed` test pattern (JSON cache
 * vs pivot equality) remains valuable regardless of data content — but
 * because it depends on `LeCayenneAllergenSeeder` populating data, it is
 * also `@group manual` until the seeder is restored. A non-content-
 * dependent SSOT sentinel (`Item::all()->each(fn $i => ...)`) belongs in a
 * separate test class; intentionally not added here to avoid drift between
 * "this test class = FIC compliance gate" and "general SSOT health".
 */
class AllergenCoverageSentinelTest extends TestCase
{
    use RefreshDatabase;

    /** Minimum % of POS-visible items that MUST have allergen_flags set. */
    private const MIN_COVERAGE_PCT = 80.0;

    /**
     * Specific items + their REQUIRED allergens (test asserts these are
     * a SUBSET of the seeded flags — extra is OK, missing is not).
     *
     * NOTE (Wave Q-4 2026-05-20): the values below were defined against the
     * retracted Wave P R2-B guess. They MUST be reviewed against real
     * chef-confirmed data before this sentinel is re-enabled in CI.
     *
     * @var array<string, list<string>>
     */
    private const REQUIRED_ALLERGENS = [
        // Bread-based → gluten + lait (cheese) MUST be present.
        'sandwich-cayenne-classique' => ['gluten', 'lait'],
        'big-cayenne'                => ['gluten', 'lait'],
        'big-classique'              => ['gluten', 'lait'],
        'galette-cayenne'            => ['gluten', 'lait'],

        // Sesame bun.
        'big-chicken'                => ['gluten', 'sesame'],
        'chicken-burger'             => ['gluten', 'sesame'],

        // Wheat tortilla + cheese sauce.
        'tacos-1-viande'             => ['gluten', 'lait'],
        'big-tacos-2-viandes'        => ['gluten', 'lait'],

        // Breaded crispy chicken bowl.
        'bowl-frites-crispy'         => ['gluten', 'oeufs'],
        'bowl-riz-crispy'            => ['gluten', 'oeufs'],

        // Desserts — eggs + milk + (Daim = nuts).
        'tiramisu'                   => ['gluten', 'lait', 'oeufs'],
        'tarte-daim'                 => ['lait', 'fruits_a_coque'],
        'glace'                      => ['lait', 'oeufs'],

        // Kid's nuggets meal = breaded chicken.
        'menu-nuggets'               => ['gluten', 'oeufs'],

        // Cheese supplement = milk only.
        'supp-cheddar'               => ['lait'],
        'supp-boursin'               => ['lait'],
    ];

    /**
     * Items that MUST be explicitly `[]` (= "checked, none") — empty
     * disclosure is required by FIC; NULL is ambiguous.
     *
     * @var list<string>
     */
    private const EXPLICITLY_NONE = [
        'eau-plate',
        'coca',
        'coca-zero',
        'fanta',
        'sprite',
        'capri-sun',
        'petite-frites',
        'grande-frites',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        // Build the minimum item catalogue the sentinel reasons about.
        // We rely on the existing menu seeders (CompleteFrenchMenuSeeder /
        // ItemCategoryWizardSeeder) used by the full DatabaseSeeder, but
        // to keep this test FAST + DETERMINISTIC we seed only what we
        // assert on.
        $this->seed(\Database\Seeders\AllergensSeeder::class);
        $this->seedMinimalLeCayenneItems();
        $this->seed(LeCayenneAllergenSeeder::class);
    }

    /**
     * @test
     * @group manual
     */
    public function coverage_meets_eu_1169_minimum_threshold(): void
    {
        $total = Item::query()
            ->whereIn('slug', array_merge(
                array_keys(self::REQUIRED_ALLERGENS),
                self::EXPLICITLY_NONE,
            ))
            ->count();

        $covered = Item::query()
            ->whereIn('slug', array_merge(
                array_keys(self::REQUIRED_ALLERGENS),
                self::EXPLICITLY_NONE,
            ))
            ->whereNotNull('allergen_flags')
            ->count();

        $this->assertGreaterThan(0, $total, 'Expected the test catalogue to be seeded.');

        $pct = ($covered / $total) * 100.0;
        $this->assertGreaterThanOrEqual(
            self::MIN_COVERAGE_PCT,
            $pct,
            sprintf(
                'EU 1169/2011 FIC coverage below threshold: %.1f%% (%d/%d items with allergen_flags). '
                . 'Run `php artisan db:seed --class=LeCayenneAllergenSeeder --force` to heal.',
                $pct, $covered, $total,
            ),
        );
    }

    /**
     * @test
     * @group manual
     */
    public function required_allergens_are_set_per_signature_item(): void
    {
        foreach (self::REQUIRED_ALLERGENS as $slug => $required) {
            $item = Item::query()->where('slug', $slug)->first();
            $this->assertNotNull($item, "Expected item '{$slug}' to be present in catalogue.");

            // Both flags JSON and pivot must contain the required allergens (SSOT discipline).
            $flags = (array) ($item->allergen_flags ?? []);
            $pivot = $item->allergens()->pluck('code')->all();

            foreach ($required as $code) {
                $this->assertContains(
                    $code,
                    $flags,
                    "Item '{$slug}' MUST declare allergen '{$code}' in allergen_flags JSON cache (EU FIC).",
                );
                $this->assertContains(
                    $code,
                    $pivot,
                    "Item '{$slug}' MUST declare allergen '{$code}' in item_allergen pivot (SSOT).",
                );
            }
        }
    }

    /**
     * @test
     * @group manual
     */
    public function explicitly_none_items_are_empty_array_not_null(): void
    {
        foreach (self::EXPLICITLY_NONE as $slug) {
            $item = Item::query()->where('slug', $slug)->first();
            $this->assertNotNull($item, "Expected item '{$slug}' to be present in catalogue.");

            $this->assertSame(
                [],
                (array) $item->allergen_flags,
                "Item '{$slug}' MUST disclose '[]' (checked-none) per FIC — NULL is ambiguous, "
                . '[] = explicit no-mandatory-allergen.',
            );
            $this->assertSame(
                0,
                $item->allergens()->count(),
                "Item '{$slug}' pivot rows MUST be 0 (matching empty flags).",
            );
        }
    }

    /**
     * @test
     * @group manual
     */
    public function ssot_cache_consistency_holds_post_seed(): void
    {
        // For every item the seeder touched, allergen_flags JSON cache
        // and item_allergen pivot MUST agree (set equality).
        $slugs = array_merge(array_keys(self::REQUIRED_ALLERGENS), self::EXPLICITLY_NONE);
        foreach ($slugs as $slug) {
            $item = Item::query()->where('slug', $slug)->first();
            if (!$item) {
                continue;
            }
            $flags = collect((array) ($item->allergen_flags ?? []))->sort()->values()->all();
            $pivot = $item->allergens()->orderBy('code')->pluck('code')->all();
            $this->assertSame(
                $flags,
                $pivot,
                "SSOT drift on '{$slug}': allergen_flags=" . json_encode($flags)
                . " vs pivot=" . json_encode($pivot)
                . ' — run AllergenService::projectFlags() to converge.',
            );
        }
    }

    /**
     * Seed only the items the sentinel asserts on, minimal columns.
     * Avoids depending on the full menu seeder (which is slow and
     * could change without affecting this test's invariant).
     */
    private function seedMinimalLeCayenneItems(): void
    {
        $catId = DB::table('item_categories')->insertGetId([
            'name' => 'Sentinel Category',
            'slug' => 'sentinel-cat',
            'status' => 1,
            'sort' => 1,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $slugs = array_merge(
            array_keys(self::REQUIRED_ALLERGENS),
            self::EXPLICITLY_NONE,
        );

        foreach ($slugs as $slug) {
            DB::table('items')->insert([
                'item_category_id' => $catId,
                'name' => ucfirst(str_replace('-', ' ', $slug)),
                'slug' => $slug,
                'price' => 5.00,
                'status' => 1,
                'item_type' => 'item',
                'order' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }
}
