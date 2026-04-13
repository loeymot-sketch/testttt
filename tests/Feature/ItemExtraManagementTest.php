<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Item;
use App\Models\ItemExtra;
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
}
