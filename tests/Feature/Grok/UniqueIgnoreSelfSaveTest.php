<?php

namespace Tests\Feature\Grok;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemVariation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Enregistrer sans renommer ne doit pas 422 à cause d'un unique ignore cassé.
 */
class UniqueIgnoreSelfSaveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_attribute_save_without_rename_is_not_422(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $attribute = ItemAttribute::factory()->create([
            'name' => 'Viande 1',
            'status' => Status::ACTIVE,
            'min_select' => 1,
            'max_select' => 1,
        ]);

        $this->putJson('/api/admin/setting/item-attribute/'.$attribute->id, [
            'name' => 'Viande 1',
            'status' => Status::ACTIVE,
            'min_select' => 2,
            'max_select' => 4,
            'allow_repeat' => true,
        ])->assertOk();

        $this->assertSame(2, (int) $attribute->fresh()->min_select);
        $this->assertSame(4, (int) $attribute->fresh()->max_select);
    }

    public function test_second_identical_variation_on_same_item_is_422(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create(['name' => 'Viande', 'status' => Status::ACTIVE]);
        ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Poulet',
            'price' => 0,
            'status' => Status::ACTIVE,
        ]);

        $this->postJson('/api/admin/item/variation/'.$item->id, [
            'name' => 'Poulet',
            'item_attribute_id' => $attribute->id,
            'price' => 0,
            'status' => Status::ACTIVE,
        ])->assertStatus(422);
    }

    public function test_addon_create_without_role_is_422(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $drink = Item::factory()->create(['status' => Status::ACTIVE, 'name' => 'Sprite']);

        $this->postJson('/api/admin/item/addon/'.$item->id, [
            'addon_item_id' => $drink->id,
        ])->assertStatus(422)
            ->assertJsonValidationErrors(['role']);
    }

    public function test_second_identical_addon_on_same_item_is_422(): void
    {
        Sanctum::actingAs($this->admin(), ['*']);
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $drink = Item::factory()->create(['status' => Status::ACTIVE, 'name' => 'Coca']);
        ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $drink->id,
            'addon_item_variation' => json_encode([]),
        ]);

        $this->postJson('/api/admin/item/addon/'.$item->id, [
            'addon_item_id' => $drink->id,
            'role' => 'drink',
        ])->assertStatus(422);
    }

    private function admin(): \App\Models\User
    {
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        return $admin;
    }
}
