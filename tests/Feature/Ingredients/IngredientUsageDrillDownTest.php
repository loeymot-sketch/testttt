<?php

namespace Tests\Feature\Ingredients;

use App\Enums\Status;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemWizardProfile;
use App\Models\ItemWizardStep;
use App\Models\User;
use App\Services\Ingredients\IngredientService;
use Database\Seeders\IngredientPermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class IngredientUsageDrillDownTest extends TestCase
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

    public function test_usage_attribute_reports_category_then_item_legacy_sorted(): void
    {
        $attribute = ItemAttribute::factory()->create(['name' => 'Bœuf']);

        $category = ItemCategory::factory()->create(['name' => 'Burgers']);
        $profileCategory = ItemWizardProfile::factory()->forCategory($category)->create();
        ItemWizardStep::factory()->create([
            'profile_id' => $profileCategory->id,
            'step_key' => 'viande_choice',
            'label' => 'Choix viande',
            'source_type' => 'item_attribute',
            'source_item_attribute_id' => $attribute->id,
            'source_ref' => (string) $attribute->id,
        ]);

        $item = Item::factory()->create(['name' => 'AAA Legacy Burger', 'status' => Status::ACTIVE]);
        $profileItem = ItemWizardProfile::factory()->create([
            'item_id' => $item->id,
            'item_category_id' => null,
        ]);
        ItemWizardStep::factory()->create([
            'profile_id' => $profileItem->id,
            'step_key' => 'viande',
            'label' => 'Viande',
            'source_type' => 'item_attribute',
            'source_item_attribute_id' => $attribute->id,
            'source_ref' => (string) $attribute->id,
        ]);

        $globalId = IngredientService::globalId(IngredientService::TYPE_ATTRIBUTE, (int) $attribute->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertOk()
            ->assertJsonPath('data.global_id', $globalId)
            ->assertJsonPath('data.used_by_count', 2);

        $usedBy = $response->json('data.used_by');
        self::assertIsArray($usedBy);
        self::assertCount(2, $usedBy);
        self::assertSame('category', $usedBy[0]['owner_type']);
        self::assertSame('Burgers', $usedBy[0]['owner_name']);
        self::assertSame("/admin/categories/{$category->id}/composer", $usedBy[0]['admin_url']);
        self::assertSame('item', $usedBy[1]['owner_type']);
        self::assertSame('AAA Legacy Burger', $usedBy[1]['owner_name']);
        self::assertSame("/admin/items/{$item->id}/composer", $usedBy[1]['admin_url']);
    }

    public function test_usage_unused_attribute_returns_empty_used_by(): void
    {
        $attribute = ItemAttribute::factory()->create(['name' => 'Lonely Spice']);
        $globalId = IngredientService::globalId(IngredientService::TYPE_ATTRIBUTE, (int) $attribute->id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertOk()
            ->assertJsonPath('data.used_by', [])
            ->assertJsonPath('data.used_by_count', 0);
    }

    public function test_usage_extra_two_categories_sorted_alphabetically(): void
    {
        $itemBase = Item::factory()->create(['status' => Status::ACTIVE]);

        $extra = ItemExtra::query()->create([
            'item_id' => $itemBase->id,
            'name' => 'Cheese topping',
            'price' => 1.25,
            'status' => Status::ACTIVE,
            'group_label' => 'extras_fromage',
            'is_available' => true,
        ]);

        $catZ = ItemCategory::factory()->create(['name' => 'Z Meals']);
        $catA = ItemCategory::factory()->create(['name' => 'Appetizers']);

        foreach ([$catA, $catZ] as $cat) {
            $profile = ItemWizardProfile::factory()->forCategory($cat)->create();
            ItemWizardStep::factory()->create([
                'profile_id' => $profile->id,
                'step_key' => 'supp',
                'label' => 'Suppléments',
                'source_type' => 'extra_group',
                'source_ref' => 'extras_fromage',
                'source_item_attribute_id' => null,
            ]);
        }

        $globalId = IngredientService::globalId(IngredientService::TYPE_EXTRA, (int) $extra->id);

        $response = $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertOk()
            ->assertJsonPath('data.used_by_count', 2);

        $usedBy = $response->json('data.used_by');
        self::assertSame('category', $usedBy[0]['owner_type']);
        self::assertSame('Appetizers', $usedBy[0]['owner_name']);
        self::assertSame('category', $usedBy[1]['owner_type']);
        self::assertSame('Z Meals', $usedBy[1]['owner_name']);
    }

    public function test_usage_addon_maps_owner_item(): void
    {
        $ownerItem = Item::factory()->create(['name' => 'Menu Formule', 'status' => Status::ACTIVE]);
        $addonOffering = Item::factory()->create(['name' => 'Cola', 'status' => Status::ACTIVE]);

        $addon = ItemAddon::query()->create([
            'item_id' => $ownerItem->id,
            'addon_item_id' => $addonOffering->id,
            'role' => 'drink',
        ]);

        ItemWizardProfile::factory()->create([
            'item_id' => $ownerItem->id,
            'item_category_id' => null,
        ]);

        $globalId = IngredientService::globalId(IngredientService::TYPE_ADDON, $addon->id);

        $this->actingAs($this->admin, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertOk()
            ->assertJsonPath('data.used_by_count', 1)
            ->assertJsonPath('data.used_by.0.owner_type', 'item')
            ->assertJsonPath('data.used_by.0.owner_id', $ownerItem->id)
            ->assertJsonPath('data.used_by.0.owner_name', 'Menu Formule')
            ->assertJsonPath('data.used_by.0.step_key', 'addon')
            ->assertJsonPath('data.used_by.0.step_label', 'Addon')
            ->assertJsonPath('data.used_by.0.admin_url', "/admin/items/{$ownerItem->id}/composer");
    }

    public function test_usage_invalid_global_id_returns_404(): void
    {
        // Route pattern [a-z]+:[0-9]+ must match so we hit the controller; unknown type => parseGlobalId null.
        // Paths that fail the route regex fall through to Frontend RootController (200), not Symfony 404.
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/ingredients/notatype:42/usage')
            ->assertNotFound()
            ->assertJsonPath('message', 'Ingredient not found');
    }

    public function test_usage_well_formed_but_missing_attribute_returns_404(): void
    {
        $this->actingAs($this->admin, 'sanctum')
            ->getJson('/api/admin/ingredients/attribute:99999999/usage')
            ->assertNotFound()
            ->assertJsonPath('message', 'Ingredient not found');
    }

    public function test_usage_requires_ingredients_manage_permission(): void
    {
        $attribute = ItemAttribute::factory()->create();
        $globalId = IngredientService::globalId(IngredientService::TYPE_ATTRIBUTE, (int) $attribute->id);

        $user = User::factory()->create();
        $user->assignRole('POS Operator');

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/admin/ingredients/{$globalId}/usage")
            ->assertForbidden();
    }
}
