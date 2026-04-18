<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderCoupon;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class FrontendDiscountIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $orderUser;
    protected User $loyaltyCustomer;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        config(['app.api_key' => '123456']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 100,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'Discount Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '20 rue remise',
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Menus',
            'slug' => 'menus',
            'status' => 5,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'Discount Menu',
            'slug' => 'discount-menu',
            'price' => 20.00,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);

        $this->orderUser = User::forceCreate([
            'name' => 'Frontend Order User',
            'email' => 'frontend-discount@test.local',
            'username' => 'frontend_discount',
            'phone' => '0600000010',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => 1,
        ]);

        $this->loyaltyCustomer = User::forceCreate([
            'name' => 'Loyalty Customer',
            'email' => 'loyalty-customer@test.local',
            'username' => 'loyalty_customer',
            'phone' => '0600000011',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => 1,
            'loyalty_code' => 'LOYAL100',
            'loyalty_points' => 500,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'subtotal' => 20.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => 20.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'coupon_id' => null,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    public function test_invalid_coupon_id_rejects_frontend_order(): void
    {
        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => 999999,
            ]));

        $response->assertStatus(422);
        $this->assertSame(0, OrderCoupon::count());
    }

    public function test_valid_coupon_is_applied_through_centralized_validation(): void
    {
        $coupon = Coupon::forceCreate([
            'name' => 'FRONT10',
            'description' => '10 percent',
            'code' => 'FRONT10',
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 5,
            'limit_per_user' => 2,
        ]);

        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => $coupon->id,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount' => 2.00,
        ]);

        $this->assertDatabaseHas('order_coupons', [
            'order_id' => $orderId,
            'coupon_id' => $coupon->id,
            'discount' => 2.00,
        ]);
    }

    public function test_coupon_takes_priority_over_loyalty_discount_on_frontend_order(): void
    {
        $coupon = Coupon::forceCreate([
            'name' => 'FIXED5',
            'description' => '5 euros',
            'code' => 'FIXED5',
            'discount' => 5,
            'discount_type' => DiscountType::FIXED,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 5,
            'limit_per_user' => 1,
        ]);

        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => $coupon->id,
                'loyalty_code' => $this->loyaltyCustomer->loyalty_code,
                'discount' => 3.00,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount' => 5.00,
        ]);

        $this->assertSame(500, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertFalse((bool) $response->json('loyalty_applied'));
    }

    public function test_loyalty_below_minimum_points_is_not_applied_server_side(): void
    {
        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'loyalty_code' => $this->loyaltyCustomer->loyalty_code,
                'discount' => 0.50,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount' => 0.00,
        ]);
        $this->assertSame(500, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertFalse((bool) $response->json('loyalty_applied'));
    }
}
