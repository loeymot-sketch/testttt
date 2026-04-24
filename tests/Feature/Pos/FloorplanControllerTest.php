<?php

namespace Tests\Feature\Pos;

use App\Events\OrderTableChanged;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class FloorplanControllerTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $operator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->operator->assignRole('POS Operator');
    }

    public function test_state_returns_only_tables_from_the_current_branch(): void
    {
        DiningTable::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'A1',
        ]);
        DiningTable::factory()->create([
            'branch_id' => $this->branch->id,
            'name' => 'A2',
        ]);
        DiningTable::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
            'name' => 'B1',
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->getJson('/api/admin/pos/floorplan/state')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissing(['name' => 'B1']);
    }

    public function test_assign_on_a_free_table_marks_it_occupied_and_creates_an_audit_log(): void
    {
        $table = DiningTable::factory()->create(['branch_id' => $this->branch->id]);
        $order = $this->makeOrder($table->id);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/floorplan/{$table->id}/assign", ['order_id' => $order->id])
            ->assertOk()
            ->assertJsonPath('data.occupancy_status', 'occupied')
            ->assertJsonPath('data.occupied_order_id', $order->id);

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
        ]);
        $this->assertDatabaseHas('dining_table_audit_logs', [
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'action' => 'occupy',
            'target_table_id' => $table->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_assign_on_a_table_occupied_by_another_order_returns_409(): void
    {
        $table = DiningTable::factory()->create([
            'branch_id' => $this->branch->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => 111,
            'occupied_at' => now(),
        ]);

        $order = $this->makeOrder(null);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/floorplan/{$table->id}/assign", ['order_id' => $order->id])
            ->assertStatus(409);
    }

    public function test_assign_on_a_table_already_occupied_by_the_same_order_is_idempotent(): void
    {
        $order = $this->makeOrder(null);
        $table = DiningTable::factory()->create([
            'branch_id' => $this->branch->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
            'occupied_at' => now()->subMinutes(5),
        ]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/floorplan/{$table->id}/assign", ['order_id' => $order->id])
            ->assertOk()
            ->assertJsonPath('data.occupied_order_id', $order->id);

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
        ]);
    }

    public function test_release_marks_the_table_free_without_modifying_the_order_table_reference(): void
    {
        $order = $this->makeOrder(null);
        $table = DiningTable::factory()->create([
            'branch_id' => $this->branch->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
            'occupied_at' => now(),
        ]);
        $order->update(['dining_table_id' => $table->id]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/floorplan/{$table->id}/release")
            ->assertOk()
            ->assertJsonPath('data.occupancy_status', 'free')
            ->assertJsonPath('data.occupied_order_id', null);

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'occupancy_status' => 'free',
            'occupied_order_id' => null,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'dining_table_id' => $table->id,
        ]);
        $this->assertDatabaseHas('dining_table_audit_logs', [
            'action' => 'release',
            'source_table_id' => $table->id,
            'order_id' => $order->id,
        ]);
    }

    public function test_transfer_moves_the_order_to_the_target_table_and_logs_the_action(): void
    {
        $order = $this->makeOrder(null);
        $source = DiningTable::factory()->create([
            'branch_id' => $this->branch->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
            'occupied_at' => now()->subMinutes(12),
        ]);
        $target = DiningTable::factory()->create(['branch_id' => $this->branch->id]);
        $order->update(['dining_table_id' => $source->id]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/admin/pos/floorplan/transfer', [
                'source_table_id' => $source->id,
                'target_table_id' => $target->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $target->id)
            ->assertJsonPath('data.occupancy_status', 'occupied')
            ->assertJsonPath('data.occupied_order_id', $order->id);

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'dining_table_id' => $target->id,
        ]);
        $this->assertDatabaseHas('dining_table_audit_logs', [
            'action' => 'transfer',
            'source_table_id' => $source->id,
            'target_table_id' => $target->id,
            'order_id' => $order->id,
        ]);
    }

    /**
     * [F-02] After a transfer the floor-plan service must dispatch
     * OrderTableChanged so the KDS can refresh table_name + flash the card.
     */
    public function test_transfer_dispatches_order_table_changed_event(): void
    {
        Event::fake([OrderTableChanged::class]);

        $order = $this->makeOrder(null);
        $source = DiningTable::factory()->create([
            'branch_id' => $this->branch->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
            'occupied_at' => now()->subMinutes(3),
        ]);
        $target = DiningTable::factory()->create(['branch_id' => $this->branch->id]);
        $order->update(['dining_table_id' => $source->id]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/admin/pos/floorplan/transfer', [
                'source_table_id' => $source->id,
                'target_table_id' => $target->id,
            ])
            ->assertOk();

        Event::assertDispatched(OrderTableChanged::class, function (OrderTableChanged $event) use ($order, $source, $target) {
            return (int) $event->order->id === (int) $order->id
                && (int) $event->previousTableId === (int) $source->id
                && (int) $event->newTableId === (int) $target->id
                && $event->action === 'transfer';
        });
    }

    /**
     * [F-02] An assignation (occupy) on a brand-new table must also dispatch
     * OrderTableChanged with previous_table_id = null and action = 'occupy'.
     */
    public function test_assign_dispatches_order_table_changed_event(): void
    {
        Event::fake([OrderTableChanged::class]);

        $table = DiningTable::factory()->create(['branch_id' => $this->branch->id]);
        $order = $this->makeOrder(null);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/floorplan/{$table->id}/assign", ['order_id' => $order->id])
            ->assertOk();

        Event::assertDispatched(OrderTableChanged::class, function (OrderTableChanged $event) use ($order, $table) {
            return (int) $event->order->id === (int) $order->id
                && $event->previousTableId === null
                && (int) $event->newTableId === (int) $table->id
                && $event->action === 'occupy';
        });
    }

    /**
     * [F-02] An idempotent re-assignation of the SAME table to the same order
     * must NOT re-dispatch OrderTableChanged (avoid noisy KDS flashes on
     * retried POST / network double-tap).
     */
    public function test_assign_idempotent_does_not_re_dispatch_event(): void
    {
        $table = DiningTable::factory()->create(['branch_id' => $this->branch->id]);
        $order = $this->makeOrder($table->id);
        $table->update([
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
            'occupied_at' => now(),
        ]);

        Event::fake([OrderTableChanged::class]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/floorplan/{$table->id}/assign", ['order_id' => $order->id])
            ->assertOk();

        Event::assertNotDispatched(OrderTableChanged::class);
    }

    public function test_transfer_within_the_same_branch_frees_the_source_and_occupies_the_target(): void
    {
        $order = $this->makeOrder(null);
        $source = DiningTable::factory()->create([
            'branch_id' => $this->branch->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
            'occupied_at' => now()->subMinutes(8),
        ]);
        $target = DiningTable::factory()->create(['branch_id' => $this->branch->id]);
        $order->update(['dining_table_id' => $source->id]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/admin/pos/floorplan/transfer', [
                'source_table_id' => $source->id,
                'target_table_id' => $target->id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('dining_tables', [
            'id' => $source->id,
            'occupancy_status' => 'free',
            'occupied_order_id' => null,
        ]);
        $this->assertDatabaseHas('dining_tables', [
            'id' => $target->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
        ]);
    }

    public function test_transfer_cross_branch_returns_404(): void
    {
        $order = $this->makeOrder(null);
        $source = DiningTable::factory()->create([
            'branch_id' => $this->branch->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
            'occupied_at' => now(),
        ]);
        $target = DiningTable::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
        ]);
        $order->update(['dining_table_id' => $source->id]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson('/api/admin/pos/floorplan/transfer', [
                'source_table_id' => $source->id,
                'target_table_id' => $target->id,
            ])
            ->assertNotFound();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'dining_table_id' => $source->id,
        ]);
        $this->assertDatabaseHas('dining_tables', [
            'id' => $source->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
        ]);
    }

    /**
     * [V14 C-β / SENTINEL FINDING C-β-T19-3 P1]
     * occupy() must sync orders.dining_table_id at assignation so KDS,
     * receipts and reporting stay coherent with the floor-plan.
     */
    public function test_assign_syncs_orders_dining_table_id_on_occupation(): void
    {
        $table = DiningTable::factory()->create(['branch_id' => $this->branch->id]);
        $order = $this->makeOrder(null);

        $this->assertNull($order->dining_table_id);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/floorplan/{$table->id}/assign", ['order_id' => $order->id])
            ->assertOk();

        $this->assertDatabaseHas('orders', [
            'id' => $order->id,
            'dining_table_id' => $table->id,
        ]);
    }

    /**
     * [V14 C-β / SENTINEL FINDING C-β-T19-2 P1]
     * occupy() must reject a phantom order_id (no row exists) with 422.
     * Without this guard the floor-plan can be polluted with lying state.
     */
    public function test_assign_with_non_existent_order_id_returns_422(): void
    {
        $table = DiningTable::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/floorplan/{$table->id}/assign", ['order_id' => 999999])
            ->assertStatus(422);

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'occupancy_status' => 'free',
            'occupied_order_id' => null,
        ]);
    }

    /**
     * [V14 C-β / SENTINEL FINDING C-β-T19-2 P1]
     * occupy() must reject a cross-branch order_id with 422 (multi-tenant
     * isolation : impossible to attach a foreign-branch order to a local
     * table).
     */
    public function test_assign_with_cross_branch_order_id_returns_422(): void
    {
        $otherBranch = Branch::factory()->create();
        $foreignOrder = Order::factory()->create([
            'branch_id' => $otherBranch->id,
            'user_id' => $this->operator->id,
            'dining_table_id' => null,
        ]);
        $table = DiningTable::factory()->create(['branch_id' => $this->branch->id]);

        $this->actingAs($this->operator, 'sanctum')
            ->postJson("/api/admin/pos/floorplan/{$table->id}/assign", ['order_id' => $foreignOrder->id])
            ->assertStatus(422);

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'occupancy_status' => 'free',
            'occupied_order_id' => null,
        ]);
    }

    private function makeOrder(?int $tableId = null): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->operator->id,
            'dining_table_id' => $tableId,
        ]);
    }
}
