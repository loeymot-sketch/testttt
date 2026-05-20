<?php

namespace Tests\Feature\Pos;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [2026-05-18 F-4] POS first-page featured category filter.
 *
 * Owner spec: when an operator lands on `/admin/pos`, the category strip
 * shows only the curated best-seller set; everything else stays reachable
 * via the "Toutes" toggle or the search input.
 *
 * The backend signals featured status with a `featured` boolean on each
 * /admin/pos-category row (additive, non-breaking). Featured-first sort
 * preserves existing intra-bucket order. Empty config = "no filter" =
 * every category marked featured = strip renders identical to legacy.
 *
 * Convergence filter: `php artisan test --filter='PosFeaturedCategoriesEndpoint'`.
 */
class PosFeaturedCategoriesEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        // Use Admin (branch_id=0) so the controller's `posRuntimeBranchId`
        // path stays null and categories without items aren't filtered out
        // by the whereHas('items') runtime guard. The featured-flag contract
        // is orthogonal to the runtime-branch availability filter.
        $this->operator = User::factory()->create(['branch_id' => 0]);
        $this->operator->assignRole('Admin');
    }

    private function makeCategories(): array
    {
        // Use distinct IDs to make config-allowlist exact.
        $cats = [
            ItemCategory::factory()->create(['name' => 'Sandwich Cayenne', 'status' => Status::ACTIVE]),
            ItemCategory::factory()->create(['name' => 'Galette', 'status' => Status::ACTIVE]),
            ItemCategory::factory()->create(['name' => 'Desserts', 'status' => Status::ACTIVE]),
            ItemCategory::factory()->create(['name' => 'Boissons', 'status' => Status::ACTIVE]),
        ];
        return $cats;
    }

    public function test_empty_allowlist_marks_every_category_as_featured(): void
    {
        Config::set('pos.featured_category_ids', []);
        $this->makeCategories();

        $this->actingAs($this->operator, 'sanctum');
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-category');
        $response->assertStatus(200);

        $rows = $response->json('data');
        $this->assertIsArray($rows);
        foreach ($rows as $row) {
            $this->assertArrayHasKey('featured', $row, 'Every row must carry the additive featured field.');
            $this->assertTrue(
                (bool) $row['featured'],
                "Empty allowlist must mark every category featured (id={$row['id']} should be true).",
            );
        }
    }

    public function test_allowlist_marks_only_those_ids_as_featured(): void
    {
        $cats = $this->makeCategories();
        // Allow only the first two (Sandwich Cayenne + Galette).
        Config::set('pos.featured_category_ids', [$cats[0]->id, $cats[1]->id]);

        $this->actingAs($this->operator, 'sanctum');
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-category');
        $response->assertStatus(200);

        $rows = collect($response->json('data'));
        $featuredIds = $rows->where('featured', true)->pluck('id')->all();
        $unfeaturedIds = $rows->where('featured', false)->pluck('id')->all();

        $this->assertContains($cats[0]->id, $featuredIds, 'Sandwich Cayenne must be featured.');
        $this->assertContains($cats[1]->id, $featuredIds, 'Galette must be featured.');
        $this->assertContains($cats[2]->id, $unfeaturedIds, 'Desserts must NOT be featured.');
        $this->assertContains($cats[3]->id, $unfeaturedIds, 'Boissons must NOT be featured.');
        // The id=0 "all-items" sentinel is always featured (always rendered).
        $this->assertTrue(
            (bool) $rows->where('id', 0)->first()['featured'],
            'all-items sentinel (id=0) must always be featured.',
        );
    }

    public function test_response_orders_featured_categories_first(): void
    {
        $cats = $this->makeCategories();
        // Mark only the LAST two as featured. Sort must move them to the front.
        Config::set('pos.featured_category_ids', [$cats[2]->id, $cats[3]->id]);

        $this->actingAs($this->operator, 'sanctum');
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-category');
        $response->assertStatus(200);

        $rows = $response->json('data');
        // Strip the 'all-items' sentinel — it's always first by virtue of being
        // pre-pended in the controller, regardless of featured-sort.
        $real = array_values(array_filter($rows, fn (array $r): bool => (int) $r['id'] !== 0));
        $this->assertGreaterThanOrEqual(2, count($real));
        $this->assertTrue(
            (bool) $real[0]['featured'],
            'First non-sentinel row must be featured after the controller usort.',
        );
        $this->assertTrue(
            (bool) $real[1]['featured'],
            'Second non-sentinel row must also be featured (sort is featured-first).',
        );
    }

    public function test_additive_field_does_not_break_legacy_response_shape(): void
    {
        // Empty allowlist preserves legacy behavior; assert all required
        // legacy fields are still present.
        Config::set('pos.featured_category_ids', []);
        Config::set('pos.featured_category_slugs', []);
        $this->makeCategories();

        $this->actingAs($this->operator, 'sanctum');
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-category');
        $response->assertStatus(200);

        $row = collect($response->json('data'))->first(fn (array $r): bool => (int) $r['id'] !== 0);
        $this->assertNotNull($row);
        foreach (['id', 'name', 'slug', 'thumb', 'cover', 'featured'] as $field) {
            $this->assertArrayHasKey($field, $row, "Legacy field `{$field}` must remain present.");
        }
    }

    /**
     * [2026-05-20 P-OWNER-3 root-cause heal — slug resolution sentinel]
     *
     * The whole point of moving from raw IDs → slugs is environment-portability:
     * IDs drift after seed/reset cycles, slugs don't. This test verifies the
     * resolution path end-to-end: config carries slugs, controller resolves
     * them to DB IDs at request time, response marks ONLY those rows featured.
     *
     * Sentinel guards against silent regression to the broken state where
     * config-IDs no longer match DB-IDs and every category falls out of the
     * allowlist (the exact bug owner reported 2026-05-19 — supplements
     * landing on the first POS screen).
     */
    public function test_slug_allowlist_resolves_to_correct_category_ids(): void
    {
        $cats = $this->makeCategories();
        // Force deterministic slugs so the resolution path has something to chew on.
        // Models created by ItemCategory::factory() may carry random slugs; rewrite
        // them to the canonical Le Cayenne best-seller set.
        $cats[0]->update(['slug' => 'sandwich-cayenne']);
        $cats[1]->update(['slug' => 'galette']);
        $cats[2]->update(['slug' => 'desserts']);
        $cats[3]->update(['slug' => 'boissons']);

        // Slug allowlist = the two main-course categories ONLY.
        Config::set('pos.featured_category_ids', []);
        Config::set('pos.featured_category_slugs', ['sandwich-cayenne', 'galette']);

        $this->actingAs($this->operator, 'sanctum');
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-category');
        $response->assertStatus(200);

        $rows = collect($response->json('data'));
        $featuredSlugs = $rows->where('featured', true)
            ->pluck('slug')
            ->reject(fn (string $slug): bool => $slug === 'all-items')
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            ['sandwich-cayenne', 'galette'],
            $featuredSlugs,
            'Slug-based allowlist must resolve to exactly the requested slugs (the env-portable contract).',
        );

        // The other two MUST be unfeatured — guards against the legacy bug
        // where every category collapsed to featured=true on misconfig.
        $unfeaturedSlugs = $rows->where('featured', false)->pluck('slug')->all();
        $this->assertContains('desserts', $unfeaturedSlugs);
        $this->assertContains('boissons', $unfeaturedSlugs);
    }

    /**
     * [2026-05-20 P-OWNER-3 root-cause heal — ID fallback sentinel]
     *
     * Backward compatibility: when slug allowlist is empty (or resolves to
     * nothing because dev fixtures don't seed slugs), the legacy
     * `pos.featured_category_ids` knob continues to work. This preserves
     * the old contract for ops that still ship IDs via env.
     */
    public function test_id_fallback_when_slug_allowlist_empty(): void
    {
        $cats = $this->makeCategories();
        Config::set('pos.featured_category_slugs', []);
        Config::set('pos.featured_category_ids', [$cats[0]->id, $cats[2]->id]);

        $this->actingAs($this->operator, 'sanctum');
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/pos-category');
        $response->assertStatus(200);

        $rows = collect($response->json('data'));
        $featuredIds = $rows->where('featured', true)
            ->pluck('id')
            ->reject(fn (int $id): bool => $id === 0)
            ->values()
            ->all();

        $this->assertEqualsCanonicalizing(
            [$cats[0]->id, $cats[2]->id],
            $featuredIds,
            'Empty slug list must fall back to the legacy ID knob (back-compat contract).',
        );
    }
}
