<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Order;
use App\Models\User;
use App\Services\DiningTableService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DiningTableReleaseAfterPosOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_releases_table_when_occupied_by_same_paid_dine_in_order(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');

        $table = DiningTable::factory()->create([
            'branch_id' => $branch->id,
        ]);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $operator->id,
            'order_type' => OrderType::DINING_TABLE,
            'payment_status' => PaymentStatus::PAID,
            'dining_table_id' => $table->id,
        ]);

        $table->update([
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $order->id,
            'occupied_at' => now(),
        ]);

        $this->actingAs($operator);
        app(DiningTableService::class)->tryReleaseTableAfterPosOrderPaid($order->fresh());

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'occupancy_status' => 'free',
            'occupied_order_id' => null,
        ]);
    }

    public function test_does_not_release_when_table_held_by_another_order(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $table = DiningTable::factory()->create(['branch_id' => $branch->id]);
        $other = Order::factory()->create(['branch_id' => $branch->id, 'user_id' => $operator->id]);
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $operator->id,
            'order_type' => OrderType::DINING_TABLE,
            'payment_status' => PaymentStatus::PAID,
            'dining_table_id' => $table->id,
        ]);
        $table->update([
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $other->id,
            'occupied_at' => now(),
        ]);

        $this->actingAs($operator);
        app(DiningTableService::class)->tryReleaseTableAfterPosOrderPaid($order->fresh());

        $this->assertDatabaseHas('dining_tables', [
            'id' => $table->id,
            'occupancy_status' => 'occupied',
            'occupied_order_id' => $other->id,
        ]);
    }
}
