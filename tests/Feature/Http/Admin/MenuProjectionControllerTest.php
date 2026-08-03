<?php

namespace Tests\Feature\Http\Admin;

use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * HTTP contract test for the read-only admin menu projection endpoint
 * — V1 section 5.
 *
 * @see app/Http/Controllers/Admin/MenuProjectionController.php
 */
class MenuProjectionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    private function bootstrap(): array
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        $branch = Branch::factory()->create();
        $admin = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id]);
        $admin->assignRole('Admin');

        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create([
            'status' => Status::ACTIVE,
            'name' => 'Tacos',
            'kiosk_label' => 'Nos Tacos',
            'channels' => ['pos', 'kiosk'],
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'name' => 'Tacos M',
            'channels' => ['pos', 'kiosk'],
            'kiosk_emoji' => '🌯',
        ]);

        return [$admin, $branch, $item];
    }

    public function test_endpoint_requires_authentication(): void
    {
        $response = $this->withHeader('x-api-key', $this->apiKey())
            ->getJson('/api/admin/menu-projection?channel=kiosk&branch_id=1');
        $response->assertStatus(401);
    }

    public function test_endpoint_rejects_missing_channel(): void
    {
        [$admin, $branch] = $this->bootstrap();
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/menu-projection?branch_id={$branch->id}");
        $response->assertStatus(422)->assertJsonValidationErrors(['channel']);
    }

    public function test_endpoint_rejects_unsupported_channel(): void
    {
        [$admin, $branch] = $this->bootstrap();
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/menu-projection?channel=mobile&branch_id={$branch->id}");
        $response->assertStatus(422)->assertJsonValidationErrors(['channel']);
    }

    public function test_kiosk_projection_returns_label_override_and_emoji(): void
    {
        [$admin, $branch] = $this->bootstrap();
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/menu-projection?channel=kiosk&branch_id={$branch->id}");

        $response->assertOk()
            ->assertJsonStructure([
                'categories' => [['id', 'slug', 'name', 'sort', 'items']],
                'snapshot_version',
                'branch_id',
                'channel',
            ])
            ->assertJsonPath('channel', 'kiosk')
            ->assertJsonPath('branch_id', $branch->id)
            ->assertJsonPath('categories.0.name', 'Nos Tacos')
            ->assertJsonPath('categories.0.items.0.emoji', '🌯');
    }

    public function test_pos_projection_returns_legacy_name_and_no_emoji(): void
    {
        [$admin, $branch] = $this->bootstrap();
        $response = $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/menu-projection?channel=pos&branch_id={$branch->id}");

        $response->assertOk()
            ->assertJsonPath('channel', 'pos')
            ->assertJsonPath('categories.0.name', 'Tacos')
            ->assertJsonMissingPath('categories.0.items.0.emoji');
    }

    public function test_branch_user_cannot_read_foreign_branch_projection(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Role::firstOrCreate(['name' => 'Branch Admin', 'guard_name' => 'sanctum']);

        $ownBranch = Branch::factory()->create();
        $foreignBranch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $ownBranch->id]);
        $user->assignRole('Branch Admin');

        $this->actingAs($user, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/menu-projection?channel=pos&branch_id={$foreignBranch->id}")
            ->assertForbidden();
    }

    public function test_branch_user_can_read_own_branch_projection(): void
    {
        [$admin, $branch] = $this->bootstrap();
        Role::firstOrCreate(['name' => 'Branch Admin', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'catalog.compose', 'guard_name' => 'sanctum']);
        $admin->syncRoles(['Branch Admin']);
        $admin->givePermissionTo('catalog.compose');

        $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/menu-projection?channel=pos&branch_id={$branch->id}")
            ->assertOk();
    }

    public function test_branch_user_without_catalog_permission_cannot_read_own_projection(): void
    {
        [$admin, $branch] = $this->bootstrap();
        Role::firstOrCreate(['name' => 'Branch Admin', 'guard_name' => 'sanctum']);
        $admin->syncRoles(['Branch Admin']);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/menu-projection?channel=pos&branch_id={$branch->id}")
            ->assertForbidden();
    }

    public function test_pos_operator_cannot_read_menu_projection(): void
    {
        [$admin, $branch] = $this->bootstrap();
        $admin->syncRoles(['POS Operator']);

        $this->actingAs($admin, 'sanctum')
            ->withHeader('x-api-key', $this->apiKey())
            ->getJson("/api/admin/menu-projection?channel=pos&branch_id={$branch->id}")
            ->assertForbidden();
    }
}
