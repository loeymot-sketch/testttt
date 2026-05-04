<?php

namespace Tests\Feature\Ingredients;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\User;
use App\Services\Ingredients\IngredientService;
use Database\Seeders\IngredientPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientControllerToggleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        $this->seed(IngredientPermissionSeeder::class);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('Admin');
    }

    public function test_admin_can_list_ingredients(): void
    {
        ItemAttribute::factory()->create(['name' => 'Sauce']);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/ingredients')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    '*' => [
                        'global_id',
                        'type',
                        'id',
                        'name',
                        'is_available',
                        'unavailable_reason',
                        'used_by_count',
                    ],
                ],
            ]);
    }

    public function test_admin_can_toggle_ingredient_availability(): void
    {
        $extra = ItemExtra::query()->create([
            'item_id' => Item::factory()->create()->id,
            'name' => 'Cheddar',
            'price' => 1.50,
            'status' => Status::ACTIVE,
            'is_available' => true,
        ]);
        $globalId = IngredientService::globalId(IngredientService::TYPE_EXTRA, $extra->id);

        $this->actingAs($this->admin, 'sanctum')
            ->putJson("/api/admin/ingredients/{$globalId}/availability", [
                'is_available' => false,
                'reason' => 'rupture',
            ])
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.is_available', false)
            ->assertJsonPath('data.unavailable_reason', 'rupture');

        $this->assertDatabaseHas('item_extras', [
            'id' => $extra->id,
            'is_available' => false,
            'unavailable_reason' => 'rupture',
        ]);
    }

    public function test_toggle_invalid_global_id_returns_422(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->patchJson('/api/admin/ingredients/unknown:999/availability', [
                'is_available' => false,
            ])
            ->assertStatus(422);
    }

    public function test_toggle_addon_returns_422(): void
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $addonItem = Item::factory()->create(['status' => Status::ACTIVE]);
        $addon = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => $addonItem->id,
            'role' => 'drink',
        ]);
        $globalId = IngredientService::globalId(IngredientService::TYPE_ADDON, $addon->id);

        $this->actingAs($this->admin, 'sanctum')
            ->patchJson("/api/admin/ingredients/{$globalId}/availability", [
                'is_available' => false,
            ])
            ->assertStatus(422);
    }

    public function test_non_admin_cannot_toggle(): void
    {
        $user = User::factory()->create();
        $user->assignRole('POS Operator');
        $attribute = ItemAttribute::factory()->create();
        $globalId = IngredientService::globalId(IngredientService::TYPE_ATTRIBUTE, $attribute->id);

        $this->actingAs($user, 'sanctum')
            ->patchJson("/api/admin/ingredients/{$globalId}/availability", [
                'is_available' => false,
            ])
            ->assertForbidden();
    }
}
