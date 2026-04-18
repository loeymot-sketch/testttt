<?php

namespace Tests\Feature\Cache;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CacheInvalidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Permission::firstOrCreate(['name' => 'items_create', 'guard_name' => 'sanctum']);
    }

    public function test_create_purges_menu_cache(): void
    {
        $branch = Branch::factory()->create();
        Cache::put("kiosk.menu.branch.{$branch->id}", ['stale' => true], 60);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_create');
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::factory()->create([
            'status' => Status::ACTIVE,
        ]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/item', [
                'name' => 'Item cache purge',
                'item_category_id' => $category->id,
                'tax_id' => $tax->id,
                'item_type' => 1,
                'price' => 9.90,
                'is_featured' => 1,
                'status' => Status::ACTIVE,
                'order' => 1,
            ]);

        $response->assertSuccessful();
        $this->assertFalse(Cache::has("kiosk.menu.branch.{$branch->id}"));
    }
}
