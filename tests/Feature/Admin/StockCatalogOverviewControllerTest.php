<?php

namespace Tests\Feature\Admin;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [Mission 1 — Stock-Rupture UI Simplification 2026-05-21]
 *
 * Tests the new GET /api/admin/stock/catalog-overview endpoint which powers
 * the unified "Produits & Stock" admin page. Endpoint must:
 *   - require auth + items_show permission
 *   - resolve branch_id (admin from query param, staff from own branch)
 *   - return categories + extra_groups (deduped by name within group_label)
 *     + variation_groups (deduped by name within attribute)
 *   - reflect per-branch rupture state from item_branch_availability (items)
 *     and stock_levels (extras + variations) with no N+1
 *
 * The endpoint is READ-ONLY. Toggling continues to flow through the existing
 * AvailabilityController endpoints (untouched).
 */
class StockCatalogOverviewControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branchA;
    private Branch $branchB;
    private User $admin;
    private User $managerA;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        // Ensure permission exists even if the role seeder layout changes.
        Permission::firstOrCreate(['name' => 'items_show', 'guard_name' => 'sanctum']);

        $this->branchA = Branch::factory()->create(['status' => Status::ACTIVE]);
        $this->branchB = Branch::factory()->create(['status' => Status::ACTIVE]);

        $this->admin = User::factory()->create(['branch_id' => 0]);
        $this->admin->assignRole('Admin');
        $this->admin->givePermissionTo('items_show');

        $this->managerA = User::factory()->create(['branch_id' => $this->branchA->id]);
        $this->managerA->assignRole('Branch Manager');
        $this->managerA->givePermissionTo('items_show');
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $response = $this->getJson('/api/admin/stock/catalog-overview');

        $this->assertContains($response->status(), [401, 403], 'Unauthenticated request must be rejected (401/403).');
    }

    public function test_user_without_items_show_permission_is_forbidden(): void
    {
        $user = User::factory()->create(['branch_id' => $this->branchA->id]);
        // No items_show permission granted.
        Sanctum::actingAs($user, ['*']);

        $response = $this->getJson('/api/admin/stock/catalog-overview');

        $response->assertStatus(403);
    }

    public function test_admin_can_pick_branch_via_query_param(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $this->branchA->id);

        $response->assertOk();
        $response->assertJsonPath('branch_id', $this->branchA->id);
        $response->assertJsonStructure([
            'branch_id',
            'categories',
            'extra_groups',
            'variation_groups',
            'fetched_at',
        ]);
    }

    public function test_staff_is_pinned_to_own_branch_even_without_query_param(): void
    {
        Sanctum::actingAs($this->managerA, ['*']);

        $response = $this->getJson('/api/admin/stock/catalog-overview');

        $response->assertOk();
        $response->assertJsonPath('branch_id', $this->branchA->id);
    }

    public function test_empty_catalog_returns_empty_arrays_not_error(): void
    {
        Sanctum::actingAs($this->admin, ['*']);

        $response = $this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $this->branchA->id);

        $response->assertOk();
        $this->assertSame([], $response->json('categories'));
        $this->assertSame([], $response->json('extra_groups'));
        $this->assertSame([], $response->json('variation_groups'));
    }

    public function test_item_unavailable_in_branch_reports_is_available_false(): void
    {
        $category = ItemCategory::factory()->create([
            'name' => 'Burgers',
            'slug' => 'burgers',
            'status' => Status::ACTIVE,
        ]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'name' => 'Big Cayenne',
            'slug' => 'big-cayenne',
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);

        // Branch A has the item marked OOS, Branch B has no override.
        ItemBranchAvailability::create([
            'branch_id' => $this->branchA->id,
            'item_id' => $item->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock_manual',
            'daily_reset_at' => now(),
        ]);

        Sanctum::actingAs($this->admin, ['*']);

        $responseA = $this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $this->branchA->id);
        $responseA->assertOk();
        $categoriesA = $responseA->json('categories');
        $this->assertCount(1, $categoriesA);
        $this->assertCount(1, $categoriesA[0]['items']);
        $this->assertSame((int) $item->id, $categoriesA[0]['items'][0]['id']);
        $this->assertFalse($categoriesA[0]['items'][0]['is_available'], 'Branch A item must be OOS');
        $this->assertSame('out_of_stock_manual', $categoriesA[0]['items'][0]['reason']);

        $responseB = $this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $this->branchB->id);
        $responseB->assertOk();
        $categoriesB = $responseB->json('categories');
        $this->assertTrue($categoriesB[0]['items'][0]['is_available'], 'Branch B has no override → item in stock');
        $this->assertNull($categoriesB[0]['items'][0]['reason']);
    }

    /**
     * [global-flag-defeats-dashboard-toggle 2026-07-22 / Vague 2] The GLOBAL
     * items.is_available flag (item-edit form) overrides the branch toggle silently.
     * The payload must surface `globally_disabled` so the dashboard can badge WHY the
     * EN-STOCK toggle is inert instead of showing a dead switch.
     */
    public function test_item_payload_exposes_globally_disabled_flag(): void
    {
        $category = ItemCategory::factory()->create(['name' => 'Burgers', 'slug' => 'burgers', 'status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);

        $available = Item::factory()->create([
            'name' => 'Cayenne', 'slug' => 'cayenne', 'item_category_id' => $category->id,
            'tax_id' => $tax->id, 'status' => Status::ACTIVE, 'is_available' => true,
        ]);
        $globallyOff = Item::factory()->create([
            'name' => 'Retired', 'slug' => 'retired', 'item_category_id' => $category->id,
            'tax_id' => $tax->id, 'status' => Status::ACTIVE, 'is_available' => false,
        ]);

        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $this->branchA->id);
        $response->assertOk();

        $items = collect($response->json('categories')[0]['items'])->keyBy('id');

        $this->assertArrayHasKey('globally_disabled', $items[$available->id]);
        $this->assertFalse($items[$available->id]['globally_disabled'], 'Globally enabled item → globally_disabled false');
        $this->assertTrue($items[$available->id]['is_available']);

        $this->assertTrue($items[$globallyOff->id]['globally_disabled'], 'items.is_available=false → globally_disabled true');
        $this->assertFalse($items[$globallyOff->id]['is_available'], 'Global flag off → effective state OOS');
    }

    public function test_extras_grouped_by_label_and_deduped_by_name(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);

        // Three items each defining their own "Algérienne" sauce supp.
        $sauceIds = [];
        foreach (['Burger A', 'Burger B', 'Burger C'] as $itemName) {
            $item = Item::factory()->create([
                'name' => $itemName,
                'item_category_id' => $category->id,
                'tax_id' => $tax->id,
                'status' => Status::ACTIVE,
            ]);
            $extra = ItemExtra::create([
                'item_id' => $item->id,
                'name' => 'Algérienne',
                'group_label' => 'sauce_supp',
                'price' => 0.50,
                'status' => Status::ACTIVE,
            ]);
            $sauceIds[] = (int) $extra->id;
        }

        // Mark one of the three Algérienne rows as OOS in branch A.
        StockLevel::withoutEvents(function () use ($sauceIds): void {
            StockLevel::create([
                'branch_id' => $this->branchA->id,
                'stockable_type' => ItemExtra::class,
                'stockable_id' => $sauceIds[0],
                'on_hand' => 0,
                'reserved' => 0,
                'manual_unavailable_reason' => 'out_of_stock_manual',
                'manual_unavailable_since' => now(),
            ]);
        });

        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $this->branchA->id);
        $response->assertOk();

        $extraGroups = $response->json('extra_groups');
        $this->assertCount(1, $extraGroups, 'one group_label');
        $this->assertSame('sauce_supp', $extraGroups[0]['group_label']);
        $this->assertSame('Sauces supplémentaires', $extraGroups[0]['display_name']);
        $this->assertCount(1, $extraGroups[0]['items'], 'three rows dedupe to one Algérienne');
        $algerian = $extraGroups[0]['items'][0];
        $this->assertSame('Algérienne', $algerian['name']);
        $this->assertCount(3, $algerian['extra_ids']);
        $this->assertSame(3, $algerian['total_count']);
        $this->assertSame(1, $algerian['any_unavailable_count']);
        $this->assertFalse($algerian['is_available'], '≥1 ruptured → group flagged OOS');
    }

    public function test_variations_grouped_by_attribute_and_deduped_by_name(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create([
            'name' => 'Crudité',
            'status' => Status::ACTIVE,
        ]);

        // Two items both define a "Tomate" variation under the Crudité attribute.
        $variationIds = [];
        foreach (['Tacos A', 'Tacos B'] as $itemName) {
            $item = Item::factory()->create([
                'name' => $itemName,
                'item_category_id' => $category->id,
                'tax_id' => $tax->id,
                'status' => Status::ACTIVE,
            ]);
            $variation = ItemVariation::create([
                'item_id' => $item->id,
                'item_attribute_id' => $attribute->id,
                'name' => 'Tomate',
                'price' => 0.00,
                'status' => Status::ACTIVE,
            ]);
            $variationIds[] = (int) $variation->id;
        }

        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $this->branchA->id);
        $response->assertOk();

        $variationGroups = $response->json('variation_groups');
        $this->assertCount(1, $variationGroups);
        $this->assertSame((int) $attribute->id, $variationGroups[0]['attribute_id']);
        $this->assertSame('Crudité', $variationGroups[0]['attribute_name']);
        $this->assertCount(1, $variationGroups[0]['items'], 'two variation rows dedupe to one Tomate');
        $tomato = $variationGroups[0]['items'][0];
        $this->assertSame('Tomate', $tomato['name']);
        $this->assertCount(2, $tomato['variation_ids']);
        $this->assertSame(2, $tomato['total_count']);
        $this->assertSame(0, $tomato['any_unavailable_count']);
        $this->assertTrue($tomato['is_available']);
    }

    /**
     * [RUPTURE-VIANDE-UNE-FOIS 2026-11-15] « Viande 1 / 2 / 3 » = un seul rayon : une viande se met en rupture une fois,
     * pour tous les emplacements de tous les plats.
     */
    public function test_les_emplacements_viande_forment_un_seul_rayon(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $v1 = ItemAttribute::factory()->create(['name' => 'Viande 1', 'status' => Status::ACTIVE]);
        $v2 = ItemAttribute::factory()->create(['name' => 'Viande 2', 'status' => Status::ACTIVE]);
        $sauce = ItemAttribute::factory()->create(['name' => 'Sauce', 'status' => Status::ACTIVE]);
        $item = Item::factory()->create(['name' => 'Tacos L', 'item_category_id' => $category->id, 'tax_id' => $tax->id, 'status' => Status::ACTIVE]);
        $ids = [];
        foreach ([[$v1, 'Viande Hachée'], [$v2, 'Viande Hachée'], [$v1, 'Poulet mariné'], [$sauce, 'Blanche']] as [$attr, $name]) {
            $ids[$attr->name . '/' . $name] = (int) ItemVariation::create([
                'item_id' => $item->id, 'item_attribute_id' => $attr->id, 'name' => $name, 'price' => 0.00, 'status' => Status::ACTIVE,
            ])->id;
        }
        // Rupture posée sur le SEUL emplacement 2 : la carte de la viande doit le signaler.
        app(\App\Services\Menu\AvailabilityService::class)->toggleVariation($ids['Viande 2/Viande Hachée'], $this->branchA->id, false, 'stock_rupture');

        Sanctum::actingAs($this->admin, ['*']);
        $groups = collect($this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $this->branchA->id)->assertOk()->json('variation_groups'));

        $this->assertCount(2, $groups, 'Sauce + un seul rayon viande (plus « Viande 1 » et « Viande 2 » séparés)');
        $viandes = $groups->firstWhere('attribute_name', 'Viandes (tous les emplacements)');
        $this->assertNotNull($viandes);
        $this->assertSame(min((int) $v1->id, (int) $v2->id), $viandes['attribute_id']);
        $hachee = collect($viandes['items'])->firstWhere('name', 'Viande Hachée');
        $this->assertEqualsCanonicalizing([$ids['Viande 1/Viande Hachée'], $ids['Viande 2/Viande Hachée']], $hachee['variation_ids']);
        $this->assertFalse($hachee['is_available'], 'rupture partielle = carte signalée, un toucher la remet partout');
        $this->assertSame(1, $hachee['any_unavailable_count']);
        $this->assertTrue(collect($viandes['items'])->firstWhere('name', 'Poulet mariné')['is_available']);
    }

    public function test_shape_contract_required_fields_present(): void
    {
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
        ]);

        Sanctum::actingAs($this->admin, ['*']);
        $response = $this->getJson('/api/admin/stock/catalog-overview?branch_id=' . $this->branchA->id);
        $response->assertOk();

        $response->assertJsonStructure([
            'branch_id',
            'categories' => [
                '*' => [
                    'id',
                    'name',
                    'slug',
                    'items' => [
                        '*' => [
                            'id',
                            'name',
                            'slug',
                            'thumb',
                            'is_available',
                            'reason',
                        ],
                    ],
                ],
            ],
            'extra_groups',
            'variation_groups',
            'fetched_at',
        ]);
    }
}
