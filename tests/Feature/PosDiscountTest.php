<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use App\Models\Coupon;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Tests discount application in POS orders (Sprint 15 - BUG-3 FIX)
 * Verifies that manual cashier discounts are properly applied
 */
class PosDiscountTest extends TestCase
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
            'tax_type' => 1, // PERCENTAGE
            'percentage' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        // Create Category
        $category = ItemCategory::factory()->create([
            'name' => 'Test Category',
            'wizard_template' => 'tacos',
            'has_menu' => true,
        ]);

        // Create Item (price 10.00)
        $this->item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
            'name' => 'Test Item',
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);
    }

    /**
     * DISCOUNT-01: Manual discount <= subtotal is applied correctly
     */
    public function test_manual_discount_is_applied_when_less_than_or_equal_to_subtotal(): void
    {
        $this->actingAs($this->posOperator);

        $subtotal = 10.00;
        $discount = 2.00; // 20% discount
        $expectedTotal = $subtotal + ($subtotal * 0.10) - $discount; // +tax - discount

        $orderData = [
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => $subtotal,
            'discount' => $discount, // Manual discount from cashier
            'coupon_id' => 0, // No coupon
            'total' => $expectedTotal,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => $expectedTotal,
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

        $response = $this->postJson('/api/pos', $orderData);

        $response->assertStatus(200);

        // Verify order was created with correct discount
        $this->assertDatabaseHas('orders', [
            'customer_id' => $this->customer->id,
            'subtotal' => $subtotal,
            'discount' => $discount, // BUG-3 FIX: Manual discount should be applied
            'total' => $expectedTotal,
        ]);
    }

    /**
     * DISCOUNT-02: Manual discount > subtotal is ignored (rejected)
     */
    public function test_manual_discount_is_ignored_when_greater_than_subtotal(): void
    {
        $this->actingAs($this->posOperator);

        $subtotal = 10.00;
        $invalidDiscount = 15.00; // More than subtotal - should be rejected
        $expectedDiscount = 0; // Should be ignored
        $expectedTotal = $subtotal + ($subtotal * 0.10); // +tax, no discount

        $orderData = [
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => $subtotal,
            'discount' => $invalidDiscount, // Invalid discount
            'coupon_id' => 0,
            'total' => $expectedTotal,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => $expectedTotal,
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

        $response = $this->postJson('/api/pos', $orderData);

        $response->assertStatus(200);

        // Verify order was created with discount = 0 (invalid discount rejected)
        $this->assertDatabaseHas('orders', [
            'customer_id' => $this->customer->id,
            'subtotal' => $subtotal,
            'discount' => $expectedDiscount, // BUG-3 FIX: Invalid discount should be 0
            'total' => $expectedTotal,
        ]);
    }

    /**
     * DISCOUNT-03: Coupon takes priority over manual discount
     */
    public function test_coupon_takes_priority_over_manual_discount(): void
    {
        $this->actingAs($this->posOperator);

        // Create a coupon (10% off, max 5.00)
        $coupon = Coupon::factory()->create([
            'name' => 'Test Coupon 10%',
            'code' => 'TEST10',
            'discount_type' => 1, // PERCENTAGE
            'discount' => 10.00,
            'maximum_discount' => 5.00,
            'status' => Status::ACTIVE,
        ]);

        $subtotal = 10.00;
        $manualDiscount = 2.00; // Should be ignored because coupon is used
        $couponDiscount = 1.00; // 10% of 10.00
        $expectedTotal = $subtotal + ($subtotal * 0.10) - $couponDiscount;

        $orderData = [
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => $subtotal,
            'discount' => $manualDiscount, // Manual discount - should be ignored
            'coupon_id' => $coupon->id, // Coupon takes priority
            'total' => $expectedTotal,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => $expectedTotal,
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

        $response = $this->postJson('/api/pos', $orderData);

        $response->assertStatus(200);

        // Verify order was created with coupon discount, not manual discount
        $this->assertDatabaseHas('orders', [
            'customer_id' => $this->customer->id,
            'subtotal' => $subtotal,
            'discount' => $couponDiscount, // BUG-3 FIX: Coupon discount applied
            'coupon_id' => $coupon->id,
            'total' => $expectedTotal,
        ]);

        // Verify the discount is NOT the manual one
        $this->assertDatabaseMissing('orders', [
            'customer_id' => $this->customer->id,
            'discount' => $manualDiscount,
            'coupon_id' => $coupon->id,
        ]);
    }
}
