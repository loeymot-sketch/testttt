<?php

namespace Tests\Feature\Menu;

use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * Sentinel — CV1-V1-CLOSEOUT-001 T-DEEP-AVAIL-API-01.
 *
 * Endpoint admin POST /api/admin/menu/availability/max-daily-qty.
 * Délègue à AvailabilityService::setMaxDailyQty (M2 V2 task 2.5).
 *
 * Source: reports/audit/CV1_AUDIT_AXE2_DATA_SYNC_2026-05-02.md §3 + Axe 3 cas 5.
 *
 * Auth pattern aligné sur AvailabilityToggleAuthzMatrixTest:
 *  - middleware: items_edit (Spatie permission, guard=sanctum)
 *  - acting guard: 'sanctum'
 *  - branch_id=0 = scope global (Admin), sinon scope = own branch.
 */
class SetMaxDailyQtyEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_endpoint_lowers_max_daily_qty_and_triggers_auto_86(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => true,
            'max_daily_qty' => 50,
            'daily_consumed_qty' => 40,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/menu/availability/max-daily-qty', [
                'item_id' => $item->id,
                'branch_id' => $branch->id,
                'max_daily_qty' => 30,
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('data.is_available', false);
        $response->assertJsonPath('data.max_daily_qty', 30);
        $response->assertJsonPath('data.unavailable_reason', 'out_of_stock');

        Event::assertDispatched(
            ItemAvailabilityChanged::class,
            fn (ItemAvailabilityChanged $e): bool =>
                $e->itemId === (int) $item->id
                && $e->branchId === (int) $branch->id
                && $e->isAvailable === false
                && $e->reason === 'out_of_stock'
        );
    }

    public function test_endpoint_validates_required_fields(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/menu/availability/max-daily-qty', []);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['item_id', 'branch_id']);
    }

    public function test_endpoint_accepts_null_for_unlimited_and_restores_availability(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $branch = Branch::factory()->create();
        $item = Item::factory()->create();
        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
            'max_daily_qty' => 10,
            'daily_consumed_qty' => 15,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/menu/availability/max-daily-qty', [
                'item_id' => $item->id,
                'branch_id' => $branch->id,
                'max_daily_qty' => null,
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.is_available', true);
        $response->assertJsonPath('data.max_daily_qty', null);
        $response->assertJsonPath('data.unavailable_reason', null);

        Event::assertDispatched(
            ItemAvailabilityChanged::class,
            fn (ItemAvailabilityChanged $e): bool =>
                $e->itemId === (int) $item->id
                && $e->branchId === (int) $branch->id
                && $e->isAvailable === true
        );
    }

    public function test_endpoint_rejects_unknown_item(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');

        $branch = Branch::factory()->create();

        $response = $this->actingAs($admin, 'sanctum')
            ->postJson('/api/admin/menu/availability/max-daily-qty', [
                'item_id' => 999_999,
                'branch_id' => $branch->id,
                'max_daily_qty' => 50,
            ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['item_id']);
    }

    public function test_endpoint_requires_authentication(): void
    {
        $branch = Branch::factory()->create();
        $item = Item::factory()->create();

        $response = $this->postJson('/api/admin/menu/availability/max-daily-qty', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'max_daily_qty' => 50,
        ]);

        $this->assertContains($response->status(), [401, 403, 419], "Unexpected status: {$response->status()}");
    }
}
