<?php

namespace Tests\Feature;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [P3 heal 2026-07-07] Les prix de variations/extras postés en blob JSON à
 * l'édition d'un item n'étaient pas validés (numeric/non-négatif). Un prix
 * négatif ou non-numérique traversait ItemRequest et corrompait le pricing SSOT.
 */
class ItemModifierPriceValidationTest extends TestCase
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

    private function actingAsAdmin(): void
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

    // [ONB-02 T-2.1.3 2026-08-27] tax_id est désormais obligatoire (article sans taxe = facturé 0 % en silence)
    private function taxeDeTest(): \App\Models\Tax
    {
        return \App\Models\Tax::firstOrCreate(
            ['code' => 'TEST-VAT-10'],
            ['name' => 'TVA 10 % (test)', 'tax_rate' => 10,
             'type' => \App\Enums\TaxType::PERCENTAGE, 'status' => \App\Enums\Status::ACTIVE]
        );
    }

    private function makeItem(ItemCategory $category, string $name): Item
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

    private function basePayload(Item $item): array
    {
        return [
            'name' => $item->name,
            'item_category_id' => $item->item_category_id,
            'item_type' => $item->item_type,
            'price' => $item->price,
            'is_featured' => $item->is_featured,
            'status' => $item->status,
            'order' => $item->order,
        ];
    }

    public function test_item_update_rejects_negative_variation_price(): void
    {
        $this->actingAsAdmin();
        $category = ItemCategory::create(['name' => 'Neg Var Cat', 'slug' => 'neg-var-cat', 'status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande', 'status' => Status::ACTIVE]);
        $item = $this->makeItem($category, 'Neg Var Item');
        $variation = ItemVariation::create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Poulet',
            'price' => 1.00,
            'status' => Status::ACTIVE,
        ]);

        $resp = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->postJson("/api/admin/item/{$item->id}", $this->basePayload($item) + [
                'variations' => json_encode([
                    ['id' => $variation->id, 'name' => 'Poulet', 'price' => -5.00],
                ]),
            ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['variations.0.price']);
        // Prix négatif NON persisté
        $this->assertDatabaseHas('item_variations', ['id' => $variation->id, 'price' => 1.00]);
    }

    public function test_item_update_rejects_non_numeric_extra_price(): void
    {
        $this->actingAsAdmin();
        $category = ItemCategory::create(['name' => 'NaN Extra Cat', 'slug' => 'nan-extra-cat', 'status' => Status::ACTIVE]);
        $item = $this->makeItem($category, 'NaN Extra Item');
        $extra = ItemExtra::create(['item_id' => $item->id, 'name' => 'Fromage', 'price' => 1.00, 'status' => Status::ACTIVE]);

        $resp = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->postJson("/api/admin/item/{$item->id}", $this->basePayload($item) + [
                'extras' => json_encode([
                    ['id' => $extra->id, 'name' => 'Fromage', 'price' => 'gratuit', 'status' => Status::ACTIVE],
                ]),
            ]);

        $resp->assertStatus(422);
        $resp->assertJsonValidationErrors(['extras.0.price']);
        $this->assertDatabaseHas('item_extras', ['id' => $extra->id, 'price' => 1.00]);
    }

    public function test_item_update_accepts_valid_and_zero_modifier_prices(): void
    {
        $this->actingAsAdmin();
        $category = ItemCategory::create(['name' => 'Valid Mod Cat', 'slug' => 'valid-mod-cat', 'status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande OK', 'status' => Status::ACTIVE]);
        $item = $this->makeItem($category, 'Valid Mod Item');
        $variation = ItemVariation::create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Boeuf',
            'price' => 1.00,
            'status' => Status::ACTIVE,
        ]);

        $resp = $this->withHeaders(['x-api-key' => $this->apiKey()])
            ->postJson("/api/admin/item/{$item->id}", $this->basePayload($item) + [
                'tax_id' => $this->taxeDeTest()->id,
                'variations' => json_encode([
                    ['id' => $variation->id, 'name' => 'Boeuf', 'price' => 2.50],
                ]),
                'extras' => json_encode([
                    // 0 est légitime (extra gratuit)
                    ['name' => 'Sauce offerte', 'price' => 0, 'status' => Status::ACTIVE],
                ]),
            ]);

        $resp->assertStatus(200);
        $this->assertDatabaseHas('item_variations', ['id' => $variation->id, 'price' => 2.50]);
        $this->assertDatabaseHas('item_extras', ['item_id' => $item->id, 'name' => 'Sauce offerte']);
    }
}
