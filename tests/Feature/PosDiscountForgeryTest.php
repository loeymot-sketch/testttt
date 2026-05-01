<?php

namespace Tests\Feature;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosDiscountForgeryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_discount_permission_uses_backend_subtotal_not_forged_client_subtotal(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->syncPermissions(['pos', 'pos-orders', 'pos-discount-up-to-10']);

        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'name' => 'Backend Subtotal Item',
            'price' => 100.00,
            'status' => Status::ACTIVE,
        ]);

        $payload = [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => 1000.00,
            'discount' => 50.00,
            'discount_reason' => 'forged subtotal',
            'coupon_id' => 0,
            'total' => 950.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 1000.00,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];

        $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos', $payload)
            ->assertStatus(422);

        $this->assertDatabaseMissing('orders', [
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'discount' => 50.00,
        ]);
    }
}
