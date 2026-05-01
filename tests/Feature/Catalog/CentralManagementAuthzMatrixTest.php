<?php

namespace Tests\Feature\Catalog;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAddon;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class CentralManagementAuthzMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        foreach (['items_show', 'items_edit', 'settings'] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'sanctum']);
        }
    }

    public function test_items_show_can_read_modifiers_but_cannot_mutate_them(): void
    {
        $reader = $this->userWithPermissions(['items_show']);
        $item = Item::factory()->create();
        $attribute = ItemAttribute::query()->create(['name' => 'Sauce']);
        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Blanche',
            'price' => 0,
            'status' => 5,
        ]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 1,
            'status' => 5,
        ]);
        $addon = ItemAddon::query()->create([
            'item_id' => $item->id,
            'addon_item_id' => Item::factory()->create()->id,
            'addon_item_variation' => [],
            'role' => 'drink',
        ]);

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->getJson("/api/admin/item/variation/{$item->id}")
            ->assertSuccessful();

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->postJson("/api/admin/item/variation/{$item->id}", [])
            ->assertForbidden();

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->patchJson("/api/admin/item/variation/{$item->id}/{$variation->id}", [])
            ->assertForbidden();

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->deleteJson("/api/admin/item/variation/{$item->id}/{$variation->id}")
            ->assertForbidden();

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->postJson("/api/admin/item/extra/{$item->id}", [])
            ->assertForbidden();

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->patchJson("/api/admin/item/extra/{$item->id}/{$extra->id}", [])
            ->assertForbidden();

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->deleteJson("/api/admin/item/extra/{$item->id}/{$extra->id}")
            ->assertForbidden();

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->postJson("/api/admin/item/addon/{$item->id}", [])
            ->assertForbidden();

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->deleteJson("/api/admin/item/addon/{$item->id}/{$addon->id}")
            ->assertForbidden();
    }

    public function test_category_management_operations_require_settings_permission(): void
    {
        $reader = $this->userWithPermissions(['items_show']);
        $category = ItemCategory::factory()->create();

        foreach ([
            ['post', '/api/admin/setting/item-category'],
            ['patch', '/api/admin/setting/item-category/'.$category->id],
            ['delete', '/api/admin/setting/item-category/'.$category->id],
            ['post', '/api/admin/setting/item-category/sort/category'],
            ['get', '/api/admin/setting/item-category/export'],
            ['get', '/api/admin/setting/item-category/download-sample'],
            ['post', '/api/admin/setting/item-category/import/file'],
        ] as [$method, $uri]) {
            $response = $this->actingAs($reader, 'sanctum')
                ->withApiKey()
                ->json(strtoupper($method), $uri);

            $this->assertSame(403, $response->getStatusCode(), "{$method} {$uri}");
        }
    }

    public function test_catalog_item_reads_require_items_show_and_branch_overlay_is_scoped(): void
    {
        $item = Item::factory()->create(['barcode' => 'AUTHZ-123']);
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $noPermission = $this->userWithPermissions([]);
        $reader = $this->userWithPermissions(['items_show'], ['branch_id' => $branchA->id]);

        foreach ([
            ['get', '/api/admin/item'],
            ['get', '/api/admin/item/show/'.$item->id],
            ['get', '/api/admin/item/details/'.$item->id],
            ['get', '/api/admin/item/lookup-barcode/AUTHZ-123'],
            ['get', '/api/admin/item/download-sample'],
        ] as [$method, $uri]) {
            $response = $this->actingAs($noPermission, 'sanctum')
                ->withApiKey()
                ->json(strtoupper($method), $uri);

            $this->assertSame(403, $response->getStatusCode(), "{$method} {$uri}");
        }

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->getJson('/api/admin/item?branch_id='.$branchA->id)
            ->assertSuccessful();

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->getJson('/api/admin/item?branch_id='.$branchB->id)
            ->assertForbidden();

        $this->actingAs($reader, 'sanctum')
            ->withApiKey()
            ->getJson('/api/admin/item/details/'.$item->id.'?branch_id='.$branchB->id)
            ->assertForbidden();
    }

    private function userWithPermissions(array $permissions, array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->givePermissionTo($permissions);

        return $user;
    }

    private function withApiKey(): self
    {
        return $this->withHeaders(['x-api-key' => env('MIX_API_KEY', 'test-api-key')]);
    }
}
