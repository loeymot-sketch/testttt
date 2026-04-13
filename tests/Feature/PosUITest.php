<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Address;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests POS UI related functionality (Sprint 16)
 * Verifies order types, company data, and payment flow
 */
class PosUITest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $posOperator;
    protected User $customer;
    protected Item $item;
    protected Tax $tax;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        // Create POS Operator
        $this->posOperator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'pos@test.com',
            'password' => Hash::make('password123'),
        ]);
        $this->posOperator->assignRole('POS Operator');

        // Create Customer
        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'email' => 'customer@test.com',
            'password' => Hash::make('password123'),
        ]);
        $this->customer->assignRole('Customer');

        // Create Tax
        $this->tax = Tax::factory()->create([
            'name' => 'TVA 10%',
            'code' => 'TVA10',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        // Create Category
        $category = ItemCategory::factory()->create([
            'name' => 'Test Category',
            'wizard_template' => 'simple',
            'has_menu' => false,
        ]);

        // Create Item
        $this->item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'name' => 'Test Item',
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);
    }

    /**
     * UI-01: Order created via POS has correct TAKEAWAY order_type
     */
    public function test_pos_order_creates_with_takeaway_order_type(): void
    {
        $this->actingAs($this->posOperator, 'sanctum');

        $subtotal = 10.00;
        $tax = $subtotal * 0.10; // 10%
        $total = $subtotal + $tax;

        $orderData = [
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => $subtotal,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => $total,
            'order_type' => OrderType::TAKEAWAY, // [BUG-A2 FIX] Should remain TAKEAWAY
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => $total,
            'items' => json_encode([
                [
                    'item_id' => $this->item->id,
                    'item_price' => $this->item->price,
                    'quantity' => 1,
                    'total_price' => $subtotal,
                    'item_variations' => [],
                    'item_extras' => [],
                ]
            ]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key'))->postJson('/api/admin/pos', $orderData);

        $response->assertStatus(201);

        // Verify order was created with TAKEAWAY type
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->customer->id,
            'order_type' => OrderType::TAKEAWAY,
            'subtotal' => $subtotal,
        ]);

        // Verify order does NOT have DINING_TABLE type
        $this->assertDatabaseMissing('orders', [
            'user_id' => $this->customer->id,
            'order_type' => OrderType::DINING_TABLE,
        ]);
    }

    /**
     * UI-02: Company data is accessible after loading (BUG-A1)
     */
    public function test_company_data_is_populated_in_pos_component(): void
    {
        $this->actingAs($this->posOperator, 'sanctum');

        // This test verifies that the company data endpoint works
        // The actual Vue component using `this.company` would fail if not populated
        $response = $this->withHeader('x-api-key', config('app.api_key'))->getJson('/api/admin/setting/company');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    'company_name',
                    'company_email',
                    'company_phone',
                    'company_address',
                    'company_country_code',
                ]
            ]);
    }

    /**
     * UI-03: Mobile cart total includes delivery charge (BUG-A3)
     */
    public function test_order_total_includes_delivery_charge(): void
    {
        $this->actingAs($this->posOperator, 'sanctum');

        $subtotal = 10.00;
        $deliveryCharge = 2.50;
        $tax = ($subtotal + $deliveryCharge) * 0.10;
        $total = $subtotal + $deliveryCharge + $tax;
        $address = Address::create([
            'user_id' => $this->customer->id,
            'label' => 'Home',
            'address' => '123 Rue Test',
            'apartment' => '',
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);

        $orderData = [
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => $subtotal,
            'discount' => 0,
            'delivery_charge' => $deliveryCharge, // [BUG-A3 FIX] Delivery charge included
            'address_id' => $address->id,
            'coupon_id' => 0,
            'total' => $total,
            'order_type' => OrderType::DELIVERY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => $total,
            'items' => json_encode([
                [
                    'item_id' => $this->item->id,
                    'item_price' => $this->item->price,
                    'quantity' => 1,
                    'total_price' => $subtotal,
                    'item_variations' => [],
                    'item_extras' => [],
                ]
            ]),
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key'))->postJson('/api/admin/pos', $orderData);

        $response->assertStatus(201);

        // Verify delivery charge is stored
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->customer->id,
            'delivery_charge' => $deliveryCharge,
        ]);
    }
}
