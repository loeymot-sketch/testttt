<?php

namespace Tests\Feature\Stock;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [GOAL CMS GESTION 2026-06-10 — Wave S2, T-S2.1]
 *
 * Vue stock hiérarchique : `catalogOverview` doit émettre `parent_id` par
 * catégorie pour que le rail du dashboard puisse imbriquer les
 * sous-catégories sous leur parent (cat → sous-cat → produits → état).
 * EXTEND-only de l'endpoint existant (RED P2-3 : pas de doublon d'endpoint).
 */
class HierarchicalStockOverviewTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        Permission::firstOrCreate(['name' => 'items_show', 'guard_name' => 'sanctum']);
    }

    public function test_catalog_overview_emits_parent_id_per_category(): void
    {
        $branch = Branch::factory()->create();
        $parent = ItemCategory::factory()->create(['status' => 5]);
        $child = ItemCategory::factory()->create(['status' => 5, 'parent_id' => $parent->id]);
        Item::factory()->create(['item_category_id' => $child->id, 'status' => 5]);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items_show']);

        $response = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->getJson('/api/admin/stock/catalog-overview?branch_id=' . $branch->id);

        $response->assertSuccessful();

        $categories = collect($response->json('categories'));
        $parentRow = $categories->firstWhere('id', $parent->id);
        $childRow = $categories->firstWhere('id', $child->id);

        $this->assertNotNull($parentRow, 'parent category missing from overview');
        $this->assertNotNull($childRow, 'child category missing from overview');
        $this->assertNull($parentRow['parent_id'], 'top-level category must emit parent_id null');
        $this->assertSame((int) $parent->id, (int) $childRow['parent_id'], 'sub-category must emit its parent_id');

        $this->assertCount(1, $childRow['items'], 'child category items must be present');
    }
}
