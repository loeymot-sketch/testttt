<?php

namespace Tests\Feature\Catalog;

use App\Models\Item;
use App\Models\ItemAddon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AddonRolePersistenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_addon_role_enum_persists_without_price_duplication(): void
    {
        $this->assertTrue(Schema::hasColumn('item_addons', 'role'));

        $base = Item::factory()->create();
        $addon = Item::factory()->create();

        $row = ItemAddon::query()->create([
            'item_id' => $base->id,
            'addon_item_id' => $addon->id,
            'addon_item_variation' => [],
            'role' => 'drink',
        ]);

        $this->assertSame('drink', $row->fresh()->role);
        $this->assertArrayNotHasKey('price', $row->fresh()->getAttributes());
    }

    public function test_addon_role_rejects_unknown_values_portably(): void
    {
        $base = Item::factory()->create();
        $addon = Item::factory()->create();

        $this->expectException(\InvalidArgumentException::class);

        ItemAddon::query()->create([
            'item_id' => $base->id,
            'addon_item_id' => $addon->id,
            'addon_item_variation' => [],
            'role' => 'invalid_role',
        ]);
    }

    public function test_admin_addon_api_persists_and_returns_role(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Permission::firstOrCreate(['name' => 'items_edit', 'guard_name' => 'sanctum']);

        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_edit');
        Sanctum::actingAs($admin, ['*']);

        $base = Item::factory()->create();
        $addon = Item::factory()->create();

        $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->postJson("/api/admin/item/addon/{$base->id}", [
                'addon_item_id' => $addon->id,
                'addon_item_variation' => null,
                'role' => 'drink',
            ])
            ->assertCreated()
            ->assertJsonPath('data.role', 'drink');

        $this->assertDatabaseHas('item_addons', [
            'item_id' => $base->id,
            'addon_item_id' => $addon->id,
            'role' => 'drink',
        ]);
    }

    public function test_admin_addon_api_rejects_unknown_role(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Permission::firstOrCreate(['name' => 'items_edit', 'guard_name' => 'sanctum']);

        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_edit');
        Sanctum::actingAs($admin, ['*']);

        $base = Item::factory()->create();
        $addon = Item::factory()->create();

        $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')])
            ->postJson("/api/admin/item/addon/{$base->id}", [
                'addon_item_id' => $addon->id,
                'addon_item_variation' => null,
                'role' => 'not_a_role',
            ])
            ->assertUnprocessable();

        $this->assertDatabaseMissing('item_addons', [
            'item_id' => $base->id,
            'addon_item_id' => $addon->id,
        ]);
    }
}
