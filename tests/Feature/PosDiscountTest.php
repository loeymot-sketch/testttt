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
use App\Enums\TaxType;
use App\Enums\DiscountType;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;

/**
 * Tests discount application in POS orders (Sprint 15 - BUG-3 FIX)
 * Verifies that manual cashier discounts are properly applied
 */
class PosDiscountTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;
    use SeedsOpenCashDrawerSession;

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
        // [POS-9.1.1] owner-level permission so legacy tests with large discounts pass the gate;
        // the dedicated gate tests live in PosDiscountPermissionTest.
        $this->posOperator->givePermissionTo('pos-discount-unlimited');
        $this->posOperator->givePermissionTo('pos-discount-over-10-requires-manager');
        // [Sprint H6 TEST-DEBT-001 2026-05-17] Sprint 1B requires an OPEN cash session for CASH.
        $this->seedOpenSessionFor($this->posOperator, $this->branch);

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
        $this->actingAs($this->posOperator, 'sanctum');

        $subtotal = 10.00;
        $discount = 2.00; // 20% discount
        $expectedTotal = $subtotal + ($subtotal * 0.10) - $discount; // +tax - discount

        $orderData = [
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => $subtotal,
            'discount' => $discount, // Manual discount from cashier
            'discount_reason' => 'Geste commercial test',
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

        $orderData = $this->payloadWithPosQuote($this->posOperator, $orderData);

        $response = $this->withHeader('x-api-key', config('app.api_key'))->postJson('/api/admin/pos', $orderData);

        $response->assertStatus(201);

        // Verify order was created with correct discount
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->customer->id,
            'subtotal' => $subtotal,
            'discount' => $discount, // BUG-3 FIX: Manual discount should be applied
        ]);
    }

    /**
     * DISCOUNT-02: Manual discount > subtotal is rejected explicitly
     */
    public function test_manual_discount_is_rejected_when_greater_than_subtotal(): void
    {
        $this->actingAs($this->posOperator, 'sanctum');

        $subtotal = 10.00;
        $invalidDiscount = 15.00; // More than subtotal - should be rejected
        $expectedTotal = $subtotal + ($subtotal * 0.10); // +tax, no discount

        $orderData = [
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'subtotal' => $subtotal,
            'discount' => $invalidDiscount, // Invalid discount
            'discount_reason' => 'Geste commercial test',
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

        $response = $this
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos/quote', $orderData);

        $response->assertStatus(422);
        $this->assertEquals(0, \App\Models\Order::count());
    }

    /**
     * DISCOUNT-03: Coupon takes priority over manual discount
     */
    public function test_coupon_takes_priority_over_manual_discount(): void
    {
        $this->actingAs($this->posOperator, 'sanctum');

        // Create a coupon (10% off, max 5.00)
        $coupon = Coupon::factory()->create([
            'name' => 'Test Coupon 10%',
            'code' => 'TEST10',
            'discount_type' => DiscountType::PERCENTAGE,
            'discount' => 10.00,
            'minimum_order' => 0,
            'maximum_discount' => 5.00,
            'start_date' => null,
            'end_date' => null,
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
            'discount_reason' => 'Geste commercial test',
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

        $orderData = $this->payloadWithPosQuote($this->posOperator, $orderData);

        $response = $this->withHeader('x-api-key', config('app.api_key'))->postJson('/api/admin/pos', $orderData);

        $response->assertStatus(201);

        // Verify order was created with coupon discount, not manual discount
        $this->assertDatabaseHas('orders', [
            'user_id' => $this->customer->id,
            'subtotal' => $subtotal,
            'discount' => $couponDiscount, // BUG-3 FIX: Coupon discount applied
        ]);

        // Verify the discount is NOT the manual one
        $this->assertDatabaseMissing('orders', [
            'user_id' => $this->customer->id,
            'discount' => $manualDiscount,
        ]);
    }
}
