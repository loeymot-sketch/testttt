<?php

namespace Tests\Feature;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class KdsTransitionWhitelistTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_kds_request_rejects_non_kitchen_status_target(): void
    {
        [$chef, $order] = $this->chefAndOrder(OrderStatus::PREPARING);

        $this->actingAs($chef, 'sanctum')
            ->postJson('/api/admin/kds-order/change-status/'.$order->id, [
                'status' => OrderStatus::CANCELED,
                'expected_status' => OrderStatus::PREPARING,
            ])
            ->assertStatus(422);

        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status);
    }

    private function chefAndOrder(int $status): array
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'order_type' => OrderType::POS,
            'payment_status' => PaymentStatus::PAID,
            'status' => $status,
        ]);

        return [$chef, $order];
    }
}
