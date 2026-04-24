<?php

namespace Tests\Feature\Admin;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
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

    public function test_staff_can_reactivate_item_availability(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_edit');
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
        ]);

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'rupture',
            'unavailable_since' => now(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => Carbon::today()->toDateString(),
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/menu/availability/toggle', [
                'item_id' => $item->id,
                'branch_id' => $branch->id,
                'is_available' => true,
            ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'item_id' => $item->id,
                'branch_id' => $branch->id,
                'is_available' => true,
                'unavailable_reason' => null,
            ]);

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 1,
            'unavailable_reason' => null,
        ]);

        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event) use ($item, $branch) {
            return $event->itemId === (int) $item->id
                && $event->branchId === (int) $branch->id
                && $event->isAvailable === true
                && $event->reason === null;
        });
    }

    public function test_idempotent_toggle_does_not_dispatch_event_when_state_unchanged(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_edit');
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
        ]);

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'rupture',
            'unavailable_since' => now(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => Carbon::today()->toDateString(),
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/menu/availability/toggle', [
                'item_id' => $item->id,
                'branch_id' => $branch->id,
                'is_available' => false,
                'unavailable_reason' => 'rupture',
            ]);

        $response->assertOk();

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 0,
            'unavailable_reason' => 'rupture',
        ]);

        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    public function test_admin_global_can_toggle_with_branch_id_null_fan_out(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_edit');
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/menu/availability/toggle', [
                'item_id' => $item->id,
                'branch_id' => null,
                'is_available' => false,
                'unavailable_reason' => 'rupture',
            ]);

        $response->assertOk()
            ->assertJson([
                'ok' => true,
                'item_id' => $item->id,
                'branch_id' => null,
                'is_available' => false,
                'unavailable_reason' => 'rupture',
            ]);

        foreach ([$branchA, $branchB] as $branch) {
            $this->assertDatabaseHas('item_branch_availability', [
                'item_id' => $item->id,
                'branch_id' => $branch->id,
                'is_available' => 0,
                'unavailable_reason' => 'rupture',
            ]);
        }

        Event::assertDispatchedTimes(ItemAvailabilityChanged::class, 2);
        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event) use ($item, $branchA) {
            return $event->itemId === (int) $item->id
                && $event->branchId === (int) $branchA->id
                && $event->isAvailable === false
                && $event->reason === 'rupture';
        });
        Event::assertDispatched(ItemAvailabilityChanged::class, function (ItemAvailabilityChanged $event) use ($item, $branchB) {
            return $event->itemId === (int) $item->id
                && $event->branchId === (int) $branchB->id
                && $event->isAvailable === false
                && $event->reason === 'rupture';
        });
    }

    public function test_branch_staff_cannot_toggle_other_branch_item_403(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();

        $staff = User::factory()->create(['branch_id' => $branchA->id]);
        $staff->givePermissionTo('items_edit');
        Sanctum::actingAs($staff, ['*']);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key', env('MIX_API_KEY', 'test-api-key')))
            ->postJson('/api/admin/menu/availability/toggle', [
                'item_id' => $item->id,
                'branch_id' => $branchB->id,
                'is_available' => false,
                'unavailable_reason' => 'rupture',
            ]);

        $response->assertForbidden()
            ->assertJson(['message' => 'Branch scope denied.']);

        $this->assertDatabaseMissing('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branchB->id,
        ]);

        Event::assertNotDispatched(ItemAvailabilityChanged::class);
    }

    public function test_toggle_persists_domain_event_outbox_with_correct_channel(): void
    {
        Bus::fake();

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $admin->givePermissionTo('items_edit');
        Sanctum::actingAs($admin, ['*']);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
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

        $response->assertOk();

        $this->assertDatabaseHas('domain_events', [
            'event_type' => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_id' => $item->id,
            'branch_id' => $branch->id,
            'broadcast_as' => 'ItemAvailabilityChanged',
        ]);

        $domainEvent = DomainEvent::query()
            ->where('aggregate_id', $item->id)
            ->where('branch_id', $branch->id)
            ->latest('id')
            ->first();
        $this->assertNotNull($domainEvent);

        $channels = json_decode($domainEvent->channel, true);
        $this->assertSame(['private-branch.' . $branch->id], $channels);

        $payload = is_array($domainEvent->payload) ? $domainEvent->payload : json_decode($domainEvent->payload, true);
        $this->assertSame((int) $item->id, (int) $payload['item_id']);
        $this->assertSame((int) $branch->id, (int) $payload['branch_id']);
        $this->assertFalse((bool) $payload['is_available']);
        $this->assertSame('rupture', $payload['reason']);

        Bus::assertDispatched(DispatchDomainEventsJob::class);
    }

    /**
     * [F-04bis] Sentinel: a GLOBAL ItemAvailabilityChanged event (admin edits item
     * status / price / variations — branchId == null) must persist a payload that
     * INCLUDES `is_available`, `branch_id` and `reason` keys (with null values for
     * global emission). Before this fix, those keys were OMITTED from the global
     * payload, which made frontend handlers (POS / Kiosk) wrongly coerce
     * `payload.is_available === true` to false → cart pruned on plain price edits.
     *
     * @see app/Listeners/PersistItemAvailabilityChangedToOutbox.php
     * @see resources/js/components/admin/pos/PosComponent.vue _onItemAvailabilityChanged
     */
    public function test_global_item_availability_changed_payload_includes_full_contract_keys(): void
    {
        Bus::fake();

        Branch::factory()->create(['status' => Status::ACTIVE]);
        Branch::factory()->create(['status' => Status::ACTIVE]);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $tax = Tax::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'status' => Status::ACTIVE,
            'price' => 9.5,
        ]);

        event(ItemAvailabilityChanged::fromItem($item, 'price'));

        $domainEvent = DomainEvent::query()
            ->where('aggregate_id', $item->id)
            ->whereNull('branch_id')
            ->latest('id')
            ->first();
        $this->assertNotNull(
            $domainEvent,
            'Global ItemAvailabilityChanged must persist a domain_events row with branch_id=null.'
        );

        $payload = is_array($domainEvent->payload)
            ? $domainEvent->payload
            : json_decode($domainEvent->payload, true);

        $this->assertArrayHasKey('item_id', $payload);
        $this->assertArrayHasKey('is_available', $payload, '[F-04bis] is_available MUST be present in global payload.');
        $this->assertArrayHasKey('branch_id', $payload, '[F-04bis] branch_id MUST be present in global payload.');
        $this->assertArrayHasKey('reason', $payload, '[F-04bis] reason MUST be present in global payload.');
        $this->assertSame((int) $item->id, (int) $payload['item_id']);
        $this->assertNull($payload['is_available'], '[F-04bis] is_available is null in global emission (no flip semantics).');
        $this->assertNull($payload['branch_id'], '[F-04bis] branch_id is null in global emission (fan-out to all active branches).');
        $this->assertNull($payload['reason'], '[F-04bis] reason is null in global emission.');
        $this->assertSame('price', $payload['type']);
    }
}
