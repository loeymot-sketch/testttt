<?php

namespace Tests\Feature\Admin;

use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class AvailabilityControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        Permission::firstOrCreate(['name' => 'items_edit', 'guard_name' => 'sanctum']);
    }

    public function test_staff_can_toggle_item_availability(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_edit');
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::factory()->create([
            'status' => Status::ACTIVE,
        ]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/menu/availability/toggle', [
                'item_id' => $item->id,
                'branch_id' => $branch->id,
                'is_available' => false,
                'unavailable_reason' => 'rupture',
            ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'item_id' => $item->id,
                'branch_id' => $branch->id,
                'is_available' => false,
                'unavailable_reason' => 'rupture',
            ]);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 0,
            'unavailable_reason' => 'rupture',
        ]);

        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event) use ($item, $branch) {
            return $event->itemId === (int) $item->id
                && $event->branchId === (int) $branch->id
                && $event->isAvailable === false
                && $event->reason === 'rupture';
        });
    }
}
