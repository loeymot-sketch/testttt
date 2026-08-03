<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [F-VARIATION-ATTR-SCOPE 2026-07-15 / P2] L'unicité de nom de variation était scopée
 * (item_id) seulement → un même nom de viande légitime sous deux groupes d'attributs
 * distincts (« Viande 1 » ET « Viande 2 » d'un tacos) était refusé 422 → 66 variations
 * jumelles live (6 produits tacos) inéditables via l'endpoint dédié. Le scope inclut
 * désormais item_attribute_id. Le garde par (item, attribut) reste actif.
 */
class ItemVariationAttributeScopeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        foreach (['items', 'items_create', 'items_edit', 'items_delete', 'items_show'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
    }

    private function actAsCatalogAdmin(): void
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo(['items', 'items_create', 'items_edit', 'items_show']);
        Sanctum::actingAs($admin, ['*']);
    }

    private function apiKey(): string
    {
        return env('MIX_API_KEY', 'test-api-key');
    }

    private function makeItem(): Item
    {
        $category = ItemCategory::create(['name' => 'Tacos', 'slug' => 'tacos-'.uniqid(), 'status' => Status::ACTIVE]);

        return Item::create([
            'name' => 'Big Tacos '.uniqid(),
            'slug' => 'big-tacos-'.uniqid(),
            'item_category_id' => $category->id,
            'item_type' => 1,
            'price' => 10.00,
            'is_featured' => 1,
            'status' => Status::ACTIVE,
            'order' => 1,
        ]);
    }

    private function attribute(string $name): ItemAttribute
    {
        return ItemAttribute::factory()->create(['name' => $name, 'status' => Status::ACTIVE]);
    }

    /** LA CORRECTION — même nom de viande sous deux groupes d'attributs distincts = autorisé. */
    public function test_variation_store_allows_same_name_under_different_attribute(): void
    {
        $this->actAsCatalogAdmin();
        $item = $this->makeItem();
        $viande1 = $this->attribute('Viande 1');
        $viande2 = $this->attribute('Viande 2');

        ItemVariation::create([
            'item_id' => $item->id,
            'item_attribute_id' => $viande1->id,
            'name' => 'Poulet mariné',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->postJson("/api/admin/item/variation/{$item->id}", [
                'name' => 'Poulet mariné',
                'item_attribute_id' => $viande2->id,
                'price' => 0,
                'status' => Status::ACTIVE,
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('item_variations', [
            'item_id' => $item->id,
            'item_attribute_id' => $viande2->id,
            'name' => 'Poulet mariné',
        ]);
    }

    /** LE GARDE reste actif — doublon sous le MÊME attribut = 422. */
    public function test_variation_store_rejects_duplicate_name_under_same_attribute(): void
    {
        $this->actAsCatalogAdmin();
        $item = $this->makeItem();
        $viande1 = $this->attribute('Viande 1');

        ItemVariation::create([
            'item_id' => $item->id,
            'item_attribute_id' => $viande1->id,
            'name' => 'Poulet mariné',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->postJson("/api/admin/item/variation/{$item->id}", [
                'name' => 'Poulet mariné',
                'item_attribute_id' => $viande1->id,
                'price' => 0,
                'status' => Status::ACTIVE,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('name');
    }

    /** LA CORRECTION — le gérant peut éditer (prix) une variation jumelle en gardant son nom. */
    public function test_variation_update_allows_editing_twin_keeping_name(): void
    {
        $this->actAsCatalogAdmin();
        $item = $this->makeItem();
        $viande1 = $this->attribute('Viande 1');
        $viande2 = $this->attribute('Viande 2');

        ItemVariation::create([
            'item_id' => $item->id,
            'item_attribute_id' => $viande1->id,
            'name' => 'Poulet mariné',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);
        $twin = ItemVariation::create([
            'item_id' => $item->id,
            'item_attribute_id' => $viande2->id,
            'name' => 'Poulet mariné',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->putJson("/api/admin/item/variation/{$item->id}/{$twin->id}", [
                'name' => 'Poulet mariné',
                'item_attribute_id' => $viande2->id,
                'price' => 1.50,
                'status' => Status::ACTIVE,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('item_variations', ['id' => $twin->id, 'price' => 1.50]);
    }
}
