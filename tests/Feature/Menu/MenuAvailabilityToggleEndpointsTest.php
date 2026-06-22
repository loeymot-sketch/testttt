<?php

namespace Tests\Feature\Menu;

use App\Enums\Status;
use App\Events\ItemExtraAvailabilityChanged;
use App\Events\ItemVariationAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\StockLevel;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [F-016a-BIS] HTTP coverage for the new admin endpoints :
 *  - POST /api/admin/menu/availability/extra/toggle
 *  - POST /api/admin/menu/availability/variation/toggle
 *  - GET  /api/admin/menu/availability/branch/{branch}
 *
 * Verifies authz (sanctum + items_edit + branch scope), validation
 * (required_if reason + whitelist), and persistence + event dispatch.
 */
class MenuAvailabilityToggleEndpointsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Permission::firstOrCreate(['name' => 'items_edit', 'guard_name' => 'sanctum']);
    }

    public function test_extra_toggle_returns_ok_and_persists_manual_reason(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class]);
        [$user, $branch, $extra] = $this->makeAdminAndExtra();

        $response = $this->actingAs($user, 'sanctum')->postJson(
            '/api/admin/menu/availability/extra/toggle',
            [
                'extra_id' => $extra->id,
                'branch_id' => $branch->id,
                'is_available' => false,
                'reason' => 'seasonal',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('extra_id', (int) $extra->id)
            ->assertJsonPath('branch_id', (int) $branch->id)
            ->assertJsonPath('is_available', false)
            ->assertJsonPath('reason', 'seasonal');

        $this->assertDatabaseHas('stock_levels', [
            'branch_id' => $branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'manual_unavailable_reason' => 'seasonal',
        ]);

        Event::assertDispatched(ItemExtraAvailabilityChanged::class);
    }

    public function test_variation_toggle_returns_ok_and_persists_manual_reason(): void
    {
        Event::fake([ItemVariationAvailabilityChanged::class]);
        [$user, $branch, $variation] = $this->makeAdminAndVariation();

        $response = $this->actingAs($user, 'sanctum')->postJson(
            '/api/admin/menu/availability/variation/toggle',
            [
                'variation_id' => $variation->id,
                'branch_id' => $branch->id,
                'is_available' => false,
                'reason' => 'recipe_change',
            ]
        );

        $response->assertOk()
            ->assertJsonPath('variation_id', (int) $variation->id)
            ->assertJsonPath('reason', 'recipe_change');

        $this->assertDatabaseHas('stock_levels', [
            'branch_id' => $branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variation->id,
            'manual_unavailable_reason' => 'recipe_change',
        ]);
    }

    public function test_extra_toggle_requires_authentication(): void
    {
        [, $branch, $extra] = $this->makeAdminAndExtra();

        $this->postJson('/api/admin/menu/availability/extra/toggle', [
            'extra_id' => $extra->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'reason' => 'seasonal',
        ])->assertStatus(401);
    }

    public function test_extra_toggle_requires_items_edit_permission(): void
    {
        $branch = Branch::factory()->create();
        [, , $extra] = $this->makeAdminAndExtra();

        $userWithoutPermission = User::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($userWithoutPermission, 'sanctum')
            ->postJson('/api/admin/menu/availability/extra/toggle', [
                'extra_id' => $extra->id,
                'branch_id' => $branch->id,
                'is_available' => false,
                'reason' => 'seasonal',
            ])
            ->assertForbidden();
    }

    public function test_extra_toggle_rejects_cross_branch_request(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class]);
        $branchOwn = Branch::factory()->create();
        $branchForeign = Branch::factory()->create();
        [, , $extra] = $this->makeAdminAndExtra();

        $branchUser = User::factory()->create(['branch_id' => $branchOwn->id]);
        $branchUser->givePermissionTo('items_edit');

        $this->actingAs($branchUser, 'sanctum')
            ->postJson('/api/admin/menu/availability/extra/toggle', [
                'extra_id' => $extra->id,
                'branch_id' => $branchForeign->id,
                'is_available' => false,
                'reason' => 'seasonal',
            ])
            ->assertForbidden();

        Event::assertNotDispatched(ItemExtraAvailabilityChanged::class);
    }

    public function test_extra_toggle_validation_rejects_missing_reason_when_unavailable(): void
    {
        [$user, $branch, $extra] = $this->makeAdminAndExtra();

        $this->actingAs($user, 'sanctum')->postJson(
            '/api/admin/menu/availability/extra/toggle',
            [
                'extra_id' => $extra->id,
                'branch_id' => $branch->id,
                'is_available' => false,
                // reason missing
            ]
        )->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_extra_toggle_validation_rejects_unknown_reason(): void
    {
        [$user, $branch, $extra] = $this->makeAdminAndExtra();

        $this->actingAs($user, 'sanctum')->postJson(
            '/api/admin/menu/availability/extra/toggle',
            [
                'extra_id' => $extra->id,
                'branch_id' => $branch->id,
                'is_available' => false,
                'reason' => 'free_form_garbage',
            ]
        )->assertStatus(422)->assertJsonValidationErrors(['reason']);
    }

    public function test_branch_availability_show_returns_aggregate(): void
    {
        Event::fake([ItemExtraAvailabilityChanged::class, ItemVariationAvailabilityChanged::class]);
        [$user, $branch, $extra] = $this->makeAdminAndExtra();
        $variation = $this->makeVariation();

        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemExtra::class,
            'stockable_id' => $extra->id,
            'on_hand' => 0,
            'reserved' => 0,
            'manual_unavailable_reason' => 'seasonal',
            'manual_unavailable_since' => now(),
        ]);
        StockLevel::query()->create([
            'branch_id' => $branch->id,
            'stockable_type' => ItemVariation::class,
            'stockable_id' => $variation->id,
            'on_hand' => 0,
            'reserved' => 0,
            'manual_unavailable_reason' => 'quality_issue',
            'manual_unavailable_since' => now(),
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/admin/menu/availability/branch/' . $branch->id);

        $response->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonPath('data.branch_id', (int) $branch->id)
            ->assertJsonPath('data.extras.0.extra_id', (int) $extra->id)
            ->assertJsonPath('data.extras.0.reason', 'seasonal')
            ->assertJsonPath('data.variations.0.variation_id', (int) $variation->id)
            ->assertJsonPath('data.variations.0.reason', 'quality_issue');
    }

    /**
     * @return array{0: User, 1: Branch, 2: ItemExtra}
     */
    private function makeAdminAndExtra(): array
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_edit');

        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 0.80,
            'status' => Status::ACTIVE,
            'group_label' => 'sauces',
            'is_available' => true,
        ]);

        return [$admin, $branch, $extra];
    }

    /**
     * @return array{0: User, 1: Branch, 2: ItemVariation}
     */
    private function makeAdminAndVariation(): array
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_edit');

        return [$admin, $branch, $this->makeVariation()];
    }

    private function makeVariation(): ItemVariation
    {
        $item = Item::factory()->create(['status' => Status::ACTIVE]);
        $attribute = ItemAttribute::factory()->create([
            'is_available' => true,
        ]);

        return ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'Maxi',
            'price' => 1.50,
            'status' => Status::ACTIVE,
        ]);
    }
}
