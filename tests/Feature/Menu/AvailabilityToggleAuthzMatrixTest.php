<?php

namespace Tests\Feature\Menu;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AvailabilityToggleAuthzMatrixTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_toggle_requires_items_edit_permission(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/menu/availability/toggle', $this->payload($item, $branch))
            ->assertForbidden();
    }

    public function test_branch_user_can_toggle_own_branch_but_not_foreign_branch(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $item = Item::factory()->create();
        $user = User::factory()->create(['branch_id' => $branchA->id]);
        $user->givePermissionTo('items_edit');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/menu/availability/toggle', $this->payload($item, $branchA))
            ->assertOk();

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branchA->id,
            'is_available' => false,
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/admin/menu/availability/toggle', $this->payload($item, $branchB))
            ->assertForbidden();
    }

    public function test_null_branch_fanout_is_scoped_for_branch_user_and_global_for_admin(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $item = Item::factory()->create();
        $branchUser = User::factory()->create(['branch_id' => $branchA->id]);
        $branchUser->givePermissionTo('items_edit');
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $this->actingAs($branchUser, 'sanctum')
            ->postJson('/api/admin/menu/availability/toggle', $this->payload($item, null))
            ->assertOk();

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branchA->id,
            'is_available' => false,
        ]);
        $this->assertDatabaseMissing('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branchB->id,
        ]);

        ItemBranchAvailability::query()->delete();

        $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/menu/availability/toggle', $this->payload($item, null))
            ->assertOk();

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branchA->id,
            'is_available' => false,
        ]);
        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branchB->id,
            'is_available' => false,
        ]);
    }

    private function payload(Item $item, ?Branch $branch): array
    {
        return [
            'item_id' => $item->id,
            'branch_id' => $branch?->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
        ];
    }
}
