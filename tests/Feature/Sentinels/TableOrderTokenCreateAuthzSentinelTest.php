<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [abuse-heal 2026-06-20 W6 PHANTOM-GATE-TABLEORDER-01] Behavioral sentinel.
 *
 * Pre-heal: TableOrderController's permission:table-orders ->only() named the phantom method
 * 'selectDeliveryBoy', so the real write handler tokenCreate ran UNGATED — a POS Operator without
 * table-orders could overwrite an order's token (live HTTP 200). Post-heal the gate names tokenCreate.
 */
class TableOrderTokenCreateAuthzSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_pos_operator_without_table_orders_cannot_overwrite_order_token(): void
    {
        $branch = Branch::factory()->create();

        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator'); // does NOT hold `table-orders`
        $this->assertFalse($operator->can('table-orders'));

        $order = Order::factory()->create([
            'branch_id'  => $branch->id,
            'order_type' => OrderType::DINING_TABLE,
            'token'      => null,
        ]);

        $this->actingAs($operator, 'sanctum')
            ->postJson("/api/admin/table-order/token-create/{$order->id}", ['token' => 'ABUSE-TOKEN'])
            ->assertStatus(403);

        $this->assertNull(
            $order->fresh()->token,
            'A non-table-orders operator must NOT be able to overwrite the order token.'
        );
    }
}
