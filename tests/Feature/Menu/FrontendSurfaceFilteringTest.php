<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [AUDIT 2026-04-17 R1] Contract test for frontend list endpoints.
 *
 * The `GET /api/frontend/item` and `GET /api/frontend/item-category` endpoints
 * must honour the optional `?surface=kiosk|pos|web` query to project only
 * entities visible on the caller's channel. Legacy callers (no query) keep
 * the full catalog, preserving V1 back-compat.
 *
 *   - NULL `channels`           → visible on every surface (legacy default).
 *   - `channels = ['kiosk']`    → hidden on `pos` / `web`.
 *   - Unknown surface value     → treated as no filter (defensive).
 *
 * @see App\Services\ItemService::applyChannelsFilter
 * @see App\Services\ItemCategoryService::applyChannelsFilter
 */
class FrontendSurfaceFilteringTest extends TestCase
{
    use RefreshDatabase;

    private Tax $tax;

    /**
     * [Kiosk Phase 9.1.14] Gate this contract test on MySQL.
     *
     * The service layer uses `whereJsonContains('channels', $surface)`, which
     * translates to `JSON_CONTAINS(channels, '"kiosk"')` under MySQL. SQLite's
     * JSON driver does not implement this predicate against ad-hoc JSON
     * columns stored as TEXT, and the query blows up with HTTP 422 — which
     * used to mask a real regression by making the 3 filtered-surface tests
     * fail silently in the SQLite test suite instead of failing the build.
     *
     * Fix (per stop-the-bleed plan):
     *   - CI job `.github/workflows/phpunit.yml` runs the full PHPUnit suite
     *     against MySQL 8.0 so this contract is honored end-to-end.
     *   - Locally, we SKIP cleanly with an explicit message. We do NOT add a
     *     SQLite fallback that pretends everything works — the audit insists
     *     that a fallback would mask the very regression this test is meant
     *     to catch.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $driver = config('database.default');
        $dbDriver = config("database.connections.$driver.driver");
        if ($dbDriver !== 'mysql') {
            $this->markTestSkipped(
                "Surface filtering contract relies on MySQL JSON_CONTAINS. " .
                "Current driver: {$dbDriver}. This test runs in the phpunit.yml " .
                "CI job against MySQL 8.0. See docs/TESTING.md §surface-filtering."
            );
        }

        $this->tax = Tax::factory()->create(['status' => Status::ACTIVE]);
    }

    /** Create a category row with the declared channels array (null = all). */
    private function seedCategory(string $name, ?array $channels): ItemCategory
    {
        return ItemCategory::factory()->create([
            'status' => Status::ACTIVE,
            'name' => $name,
            'channels' => $channels,
        ]);
    }

    /** Create an item row bound to the given category. */
    private function seedItem(string $name, ItemCategory $category, ?array $channels): Item
    {
        return Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'status' => Status::ACTIVE,
            'name' => $name,
            'channels' => $channels,
        ]);
    }

    public function test_item_list_without_surface_returns_every_item(): void
    {
        $this->withoutMiddleware();
        $allCat = $this->seedCategory('All', null);
        $kioskCat = $this->seedCategory('KioskOnly', ['kiosk']);

        $legacyItem = $this->seedItem('LegacyNullChannels', $allCat, null);
        $kioskItem = $this->seedItem('KioskExclusive', $kioskCat, ['kiosk']);
        $posItem = $this->seedItem('PosExclusive', $allCat, ['pos']);

        $response = $this->getJson('/api/frontend/item');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains($legacyItem->name, $names);
        $this->assertContains($kioskItem->name, $names);
        $this->assertContains($posItem->name, $names);
    }

    public function test_item_list_with_surface_kiosk_hides_pos_only_items(): void
    {
        $this->withoutMiddleware();
        $allCat = $this->seedCategory('All', null);

        $legacyItem = $this->seedItem('LegacyNullChannels', $allCat, null);
        $kioskItem = $this->seedItem('KioskExclusive', $allCat, ['kiosk']);
        $posItem = $this->seedItem('PosExclusive', $allCat, ['pos']);

        $response = $this->getJson('/api/frontend/item?surface=kiosk');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains($legacyItem->name, $names, 'Legacy NULL channels must stay visible on kiosk');
        $this->assertContains($kioskItem->name, $names, 'Kiosk-tagged items must appear on kiosk');
        $this->assertNotContains($posItem->name, $names, 'POS-only items must NOT leak on kiosk');
    }

    public function test_item_list_with_surface_pos_hides_kiosk_only_items(): void
    {
        $this->withoutMiddleware();
        $allCat = $this->seedCategory('All', null);

        $legacyItem = $this->seedItem('LegacyNullChannels', $allCat, null);
        $kioskItem = $this->seedItem('KioskExclusive', $allCat, ['kiosk']);
        $posItem = $this->seedItem('PosExclusive', $allCat, ['pos']);

        $response = $this->getJson('/api/frontend/item?surface=pos');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains($legacyItem->name, $names);
        $this->assertContains($posItem->name, $names);
        $this->assertNotContains($kioskItem->name, $names);
    }

    public function test_item_list_with_unknown_surface_falls_back_to_all(): void
    {
        $this->withoutMiddleware();
        $allCat = $this->seedCategory('All', null);

        $legacyItem = $this->seedItem('LegacyNullChannels', $allCat, null);
        $kioskItem = $this->seedItem('KioskExclusive', $allCat, ['kiosk']);

        $response = $this->getJson('/api/frontend/item?surface=mobile');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains($legacyItem->name, $names);
        $this->assertContains(
            $kioskItem->name,
            $names,
            'Unknown surface must behave like no filter (defensive) to avoid hiding legitimate items'
        );
    }

    public function test_category_list_with_surface_kiosk_hides_pos_only_categories(): void
    {
        $this->withoutMiddleware();
        $legacyCat = $this->seedCategory('LegacyNullChannels', null);
        $kioskCat = $this->seedCategory('KioskExclusive', ['kiosk']);
        $posCat = $this->seedCategory('PosExclusive', ['pos']);

        $response = $this->getJson('/api/frontend/item-category?surface=kiosk');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains($legacyCat->name, $names);
        $this->assertContains($kioskCat->name, $names);
        $this->assertNotContains($posCat->name, $names);
    }

    public function test_category_list_without_surface_returns_every_category(): void
    {
        $this->withoutMiddleware();
        $legacyCat = $this->seedCategory('LegacyNullChannels', null);
        $kioskCat = $this->seedCategory('KioskExclusive', ['kiosk']);
        $posCat = $this->seedCategory('PosExclusive', ['pos']);

        $response = $this->getJson('/api/frontend/item-category');

        $response->assertOk();
        $names = collect($response->json('data'))->pluck('name')->all();
        $this->assertContains($legacyCat->name, $names);
        $this->assertContains($kioskCat->name, $names);
        $this->assertContains($posCat->name, $names);
    }

    /** @see ItemController::index — default ?surface=pos for POS-runtime users without items_show */
    public function test_admin_item_list_pos_operator_without_surface_defaults_like_surface_pos(): void
    {
        $driver = config('database.default');
        $dbDriver = config("database.connections.$driver.driver");
        if ($dbDriver !== 'mysql') {
            $this->markTestSkipped('Admin POS surface sentinel requires MySQL JSON_CONTAINS (same as frontend contract).');
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $this->assertTrue($operator->can('pos'));
        $this->assertFalse($operator->can('items_show'));

        $category = $this->seedCategory('AdminPosDefaultSurfCat', null);
        $legacyItem = $this->seedItem('AdminPosDefaultLegacy', $category, null);
        $kioskItem = $this->seedItem('AdminPosDefaultKioskExcl', $category, ['kiosk']);
        $posItem = $this->seedItem('AdminPosDefaultPosExcl', $category, ['pos']);

        $base = '/api/admin/item?paginate=0&status=' . Status::ACTIVE . '&item_category_id=' . $category->id;

        $names = collect(
            $this->actingAs($operator, 'sanctum')->getJson($base)->assertOk()->json('data')
        )->pluck('name')->all();

        $this->assertContains($legacyItem->name, $names);
        $this->assertContains($posItem->name, $names);
        $this->assertNotContains($kioskItem->name, $names, 'POS runtime without surface must not leak kiosk-exclusive SKUs');
    }

    public function test_admin_item_list_admin_without_surface_keeps_legacy_all_channels_visible(): void
    {
        $driver = config('database.default');
        $dbDriver = config("database.connections.$driver.driver");
        if ($dbDriver !== 'mysql') {
            $this->markTestSkipped('Admin POS surface sentinel requires MySQL JSON_CONTAINS (same as frontend contract).');
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');
        $this->assertTrue($admin->can('items_show'));

        $category = $this->seedCategory('AdminLegacySurfCat', null);
        $legacyItem = $this->seedItem('AdminLegacySurfNull', $category, null);
        $kioskItem = $this->seedItem('AdminLegacySurfKiosk', $category, ['kiosk']);
        $posItem = $this->seedItem('AdminLegacySurfPos', $category, ['pos']);

        $base = '/api/admin/item?paginate=0&status=' . Status::ACTIVE . '&item_category_id=' . $category->id;

        $names = collect(
            $this->actingAs($admin, 'sanctum')->getJson($base)->assertOk()->json('data')
        )->pluck('name')->all();

        $this->assertContains($legacyItem->name, $names);
        $this->assertContains($posItem->name, $names);
        $this->assertContains($kioskItem->name, $names, 'Admin without explicit surface stays catalog-wide');
    }

    public function test_admin_item_list_pos_operator_implicit_surface_matches_explicit_surface_pos(): void
    {
        $driver = config('database.default');
        $dbDriver = config("database.connections.$driver.driver");
        if ($dbDriver !== 'mysql') {
            $this->markTestSkipped('Admin POS surface sentinel requires MySQL JSON_CONTAINS (same as frontend contract).');
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');

        $category = $this->seedCategory('AdminExplicitPosParityCat', null);
        $this->seedItem('ExplicitParityLegacy', $category, null);
        $this->seedItem('ExplicitParityKiosk', $category, ['kiosk']);
        $this->seedItem('ExplicitParityPos', $category, ['pos']);

        $q = '?paginate=0&status=' . Status::ACTIVE . '&item_category_id=' . $category->id;

        $implicit = collect(
            $this->actingAs($operator, 'sanctum')->getJson('/api/admin/item' . $q)->assertOk()->json('data')
        )->pluck('name')->sort()->values()->all();

        $explicit = collect(
            $this->actingAs($operator, 'sanctum')->getJson('/api/admin/item' . $q . '&surface=pos')->assertOk()->json('data')
        )->pluck('name')->sort()->values()->all();

        $this->assertSame($explicit, $implicit);
    }

    /** Client-declared surface wins (no forcing pos onto ?surface=kiosk). */
    public function test_admin_item_list_pos_operator_with_surface_kiosk_is_not_overridden_to_pos(): void
    {
        $driver = config('database.default');
        $dbDriver = config("database.connections.$driver.driver");
        if ($dbDriver !== 'mysql') {
            $this->markTestSkipped('Admin POS surface sentinel requires MySQL JSON_CONTAINS (same as frontend contract).');
        }

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');

        $category = $this->seedCategory('AdminKioskOverrideCat', null);
        $legacyItem = $this->seedItem('KioskSurfLegacy', $category, null);
        $kioskItem = $this->seedItem('KioskSurfKioskOnly', $category, ['kiosk']);
        $posItem = $this->seedItem('KioskSurfPosOnly', $category, ['pos']);

        $url = '/api/admin/item?paginate=0&status=' . Status::ACTIVE .
            '&item_category_id=' . $category->id . '&surface=kiosk';

        $names = collect(
            $this->actingAs($operator, 'sanctum')->getJson($url)->assertOk()->json('data')
        )->pluck('name')->all();

        $this->assertContains($legacyItem->name, $names);
        $this->assertContains($kioskItem->name, $names);
        $this->assertNotContains($posItem->name, $names, 'Explicit kiosk surface must not be replaced by POS default');
    }
}
