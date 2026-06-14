<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KdsUnreleasedOrderBumpTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_can_bump_unpaid_delivery_order_via_kds_change_status()
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        // Unpaid delivery order — should NOT be kitchen-visible per KitchenReleaseRule
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::DELIVERY,
            'source' => Source::WEB,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => 1,
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
        ]);

        $statusBefore = $order->status;

        $this->actingAs($chef, 'sanctum');

        // Try to bump ACCEPT → PREPARING via direct API
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/kds-order/change-status/' . $order->id, [
                'status' => OrderStatus::PREPARING,
                'expected_status' => OrderStatus::ACCEPT,
            ]);

        // Check if order status actually changed in DB
        $updatedOrder = Order::find($order->id);
        $statusAfter = $updatedOrder->status;

        echo "\n\n=== KDS UNRELEASED ORDER BUMP TEST ===\n";
        echo "HTTP Response Status: " . $response->status() . "\n";
        echo "Order Status Before: " . $statusBefore . "\n";
        echo "Order Status After: " . $statusAfter . "\n";
        echo "Bump Succeeded: " . ($statusAfter === OrderStatus::PREPARING ? 'YES - BUG' : 'NO - OK') . "\n";
        echo "Order Payment Status: " . $updatedOrder->payment_status . "\n";
        echo "Order Type: " . $updatedOrder->order_type . "\n";

        $this->assertEquals(OrderStatus::PREPARING, $statusAfter, 
            'BUG: Unpaid delivery order was bumped to PREPARING (should be blocked)');
    }
}
