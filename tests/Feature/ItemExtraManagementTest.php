<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\ItemCategory;
use App\Enums\Status;
use Spatie\Permission\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

class ItemExtraManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seedItemPermissions();
    }

    private function seedItemPermissions(): void
    {
        $permissions = ['items', 'items_create', 'items_edit', 'items_delete', 'items_show'];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }

    private function apiKey(): string
    {
        return env('MIX_API_KEY', 'test-api-key');
    }

    // [ONB-02 T-2.1.3 2026-08-27] tax_id est désormais obligatoire (article sans taxe = facturé 0 % en silence)
    private function taxeDeTest(): \App\Models\Tax
    {
        return \App\Models\Tax::firstOrCreate(
            ['code' => 'TEST-VAT-10'],
            ['name' => 'TVA 10 % (test)', 'tax_rate' => 10,
             'type' => \App\Enums\TaxType::PERCENTAGE, 'status' => \App\Enums\Status::ACTIVE]
        );
    }

    public function test_item_store_creates_extras(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_show']);
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create(['name' => 'Test Cat', 'slug' => 'test-cat', 'status' => Status::ACTIVE]);

        $response = $this->withHeaders([
            'x-api-key' => $this->apiKey(),
        ])->postJson('/api/admin/item', [
            'name' => 'Test Item',
            'item_category_id' => $category->id,
            'item_type' => 1,
            'price' => 10.00,
            'is_featured' => 1,
            'status' => Status::ACTIVE,
            'order' => 1,
            'tax_id' => $this->taxeDeTest()->id,
            'extras' => json_encode([
                ['name' => 'Supplément Fromage', 'price' => 1.00, 'status' => Status::ACTIVE],
                ['name' => 'Supplément Jambon', 'price' => 1.00, 'status' => Status::ACTIVE],
            ]),
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('item_extras', ['name' => 'Supplément Fromage']);
        $this->assertDatabaseHas('item_extras', ['name' => 'Supplément Jambon']);
    }

    public function test_item_update_syncs_extras(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_show']);
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create(['name' => 'Test Cat 2', 'slug' => 'test-cat-2', 'status' => Status::ACTIVE]);
        $item = Item::create([
            'name' => 'Test Item 2', 'slug' => 'test-item-2',
            'item_category_id' => $category->id, 'item_type' => 1,
            'price' => 10.00, 'is_featured' => 1, 'status' => Status::ACTIVE, 'order' => 1,
        ]);
        $extra = ItemExtra::create(['item_id' => $item->id, 'name' => 'Old Extra', 'price' => 1.00, 'status' => Status::ACTIVE]);

        $response = $this->withHeaders([
            'x-api-key' => $this->apiKey(),
        ])->postJson("/api/admin/item/{$item->id}", [
            'name' => 'Test Item 2',
            'item_category_id' => $category->id,
            'item_type' => 1,
            'price' => 10.00,
            'is_featured' => 1,
            'status' => Status::ACTIVE,
            'order' => 1,
            'tax_id' => $this->taxeDeTest()->id,
            'extras' => json_encode([
                ['name' => 'New Extra', 'price' => 2.00, 'status' => Status::ACTIVE],
            ]),
        ]);

        $response->assertStatus(200);
        // Old extra should be deleted
        $this->assertDatabaseMissing('item_extras', ['id' => $extra->id, 'deleted_at' => null]);
        // New extra should exist
        $this->assertDatabaseHas('item_extras', ['item_id' => $item->id, 'name' => 'New Extra']);
    }

    public function test_item_update_rejects_foreign_nested_variation_id(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_show']);
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create(['name' => 'Scoped Cat A', 'slug' => 'scoped-cat-a', 'status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['name' => 'Scoped Attribute', 'status' => Status::ACTIVE]);
        $target = $this->makeScopedItem($category, 'Scoped Target Item');
        $foreign = $this->makeScopedItem($category, 'Scoped Foreign Item');
        $ownVariation = ItemVariation::create([
            'item_id' => $target->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Own Chicken',
            'price' => 1.00,
            'status' => Status::ACTIVE,
        ]);
        $foreignVariation = ItemVariation::create([
            'item_id' => $foreign->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Foreign Beef',
            'price' => 2.00,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->withHeaders([
            'x-api-key' => $this->apiKey(),
        ])->postJson("/api/admin/item/{$target->id}", $this->itemUpdatePayload($target, [
            'variations' => json_encode([
                ['id' => $ownVariation->id, 'name' => 'Own Chicken', 'price' => 1.00],
                ['id' => $foreignVariation->id, 'name' => 'Hijacked Beef', 'price' => 9.99],
            ]),
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseHas('item_variations', [
            'id' => $foreignVariation->id,
            'item_id' => $foreign->id,
            'name' => 'Foreign Beef',
            'price' => 2.00,
        ]);
    }

    public function test_item_update_rejects_foreign_nested_extra_id(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_show']);
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::create(['name' => 'Scoped Cat B', 'slug' => 'scoped-cat-b', 'status' => Status::ACTIVE]);
        $target = $this->makeScopedItem($category, 'Scoped Target Extra Item');
        $foreign = $this->makeScopedItem($category, 'Scoped Foreign Extra Item');
        $ownExtra = ItemExtra::create(['item_id' => $target->id, 'name' => 'Own Cheese', 'price' => 1.00, 'status' => Status::ACTIVE]);
        $foreignExtra = ItemExtra::create(['item_id' => $foreign->id, 'name' => 'Foreign Bacon', 'price' => 2.00, 'status' => Status::ACTIVE]);

        $response = $this->withHeaders([
            'x-api-key' => $this->apiKey(),
        ])->postJson("/api/admin/item/{$target->id}", $this->itemUpdatePayload($target, [
            'extras' => json_encode([
                ['id' => $ownExtra->id, 'name' => 'Own Cheese', 'price' => 1.00, 'status' => Status::ACTIVE],
                ['id' => $foreignExtra->id, 'name' => 'Hijacked Bacon', 'price' => 9.99, 'status' => Status::ACTIVE],
            ]),
        ]));

        $response->assertStatus(422);
        $this->assertDatabaseHas('item_extras', [
            'id' => $foreignExtra->id,
            'item_id' => $foreign->id,
            'name' => 'Foreign Bacon',
            'price' => 2.00,
        ]);
    }

    // =====================================================================
    //  [AUDIT 2026-07-13 P2 — Finding 1] Unicité du nom d'extra PAR produit.
    //  Les params de route sont des modèles (`{item}`, `{itemExtra}`) ; l'ancien
    //  `route('item.id')` renvoyait NULL → la garde ne bloquait jamais un doublon.
    // =====================================================================

    private function actAsCatalogAdmin(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_show']);
        Sanctum::actingAs($admin, ['*']);
    }

    public function test_extra_store_rejects_duplicate_name_same_item(): void
    {
        $this->actAsCatalogAdmin();
        $category = ItemCategory::create(['name' => 'Dup Cat', 'slug' => 'dup-cat', 'status' => Status::ACTIVE]);
        $item = $this->makeScopedItem($category, 'Dup Item');
        ItemExtra::create(['item_id' => $item->id, 'name' => 'Cheddar', 'price' => 1.00, 'status' => Status::ACTIVE]);

        $response = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->postJson("/api/admin/item/extra/{$item->id}", [
                'name' => 'Cheddar', 'price' => 1.50, 'status' => Status::ACTIVE,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    public function test_extra_store_allows_same_name_on_different_items(): void
    {
        $this->actAsCatalogAdmin();
        $category = ItemCategory::create(['name' => 'Diff Cat', 'slug' => 'diff-cat', 'status' => Status::ACTIVE]);
        $itemA = $this->makeScopedItem($category, 'Item A');
        $itemB = $this->makeScopedItem($category, 'Item B');
        ItemExtra::create(['item_id' => $itemA->id, 'name' => 'Cheddar', 'price' => 1.00, 'status' => Status::ACTIVE]);

        $response = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->postJson("/api/admin/item/extra/{$itemB->id}", [
                'name' => 'Cheddar', 'price' => 1.00, 'status' => Status::ACTIVE,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('item_extras', ['item_id' => $itemB->id, 'name' => 'Cheddar']);
    }

    public function test_extra_update_allows_editing_current_row(): void
    {
        $this->actAsCatalogAdmin();
        $category = ItemCategory::create(['name' => 'Edit Cat', 'slug' => 'edit-cat', 'status' => Status::ACTIVE]);
        $item = $this->makeScopedItem($category, 'Edit Item');
        $extra = ItemExtra::create(['item_id' => $item->id, 'name' => 'Cheddar', 'price' => 1.00, 'status' => Status::ACTIVE]);

        // Ré-enregistre la MÊME ligne (même nom) → doit passer grâce à ignore(id).
        $response = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->putJson("/api/admin/item/extra/{$item->id}/{$extra->id}", [
                'name' => 'Cheddar', 'price' => 1.20, 'status' => Status::ACTIVE,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('item_extras', ['id' => $extra->id, 'price' => 1.20]);
    }

    private function makeScopedItem(ItemCategory $category, string $name): Item
    {
        return Item::create([
            'name' => $name,
            'slug' => \Illuminate\Support\Str::slug($name),
            'item_category_id' => $category->id,
            'item_type' => 1,
            'price' => 10.00,
            'is_featured' => 1,
            'status' => Status::ACTIVE,
            'order' => 1,
        ]);
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function itemUpdatePayload(Item $item, array $overrides = []): array
    {
        return array_merge([
            'name' => $item->name,
            'item_category_id' => $item->item_category_id,
            'item_type' => $item->item_type,
            'price' => $item->price,
            'is_featured' => $item->is_featured,
            'status' => $item->status,
            'order' => $item->order,
        ], $overrides);
    }
}
