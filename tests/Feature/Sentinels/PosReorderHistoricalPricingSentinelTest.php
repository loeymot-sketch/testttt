<?php

namespace Tests\Feature\Sentinels;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

class PosReorderHistoricalPricingSentinelTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    public function test_reorder_items_returns_historical_snapshot_but_commit_uses_current_quote_ssot(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $operator->givePermissionTo('pos-orders');

        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create([
            'wizard_template' => 'simple',
            'has_menu' => false,
        ]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        $historicOrder = Order::factory()->create([
            'user_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => 1.00,
            'total_tax' => 0,
            'total' => 1.00,
            'order_type' => OrderType::POS,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PAID,
            'status' => OrderStatus::DELIVERED,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 1.00,
        ]);

        OrderItem::query()->create([
            'order_id' => $historicOrder->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'discount' => 0,
            'tax_name' => 'ZERO',
            'tax_rate' => 0,
            'tax_type' => TaxType::PERCENTAGE,
            'tax_amount' => 0,
            'price' => 1.00,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
            'composition_snapshot' => [
                'schema_version' => 1,
                'lines' => [],
                'extras' => [],
            ],
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 1.00,
        ]);

        $reorder = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->getJson("/api/admin/pos-order/reorder-items/{$historicOrder->id}")
            ->assertOk()
            ->json();

        $this->assertSame(1, (int) $reorder['cart_items'][0]['quantity']);
        $this->assertEquals(1.00, (float) $reorder['cart_items'][0]['unit_price']);

        $payload = [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => 1.00,
            'discount' => 0,
            'coupon_id' => 0,
            'total' => 1.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::POS,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
            'items' => json_encode([[
                'item_id' => $reorder['cart_items'][0]['item_id'],
                'item_price' => $reorder['cart_items'][0]['unit_price'],
                'price' => $reorder['cart_items'][0]['unit_price'],
                'quantity' => $reorder['cart_items'][0]['quantity'],
                'total_price' => $reorder['cart_items'][0]['total_price'],
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];

        $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($operator, $payload, 10.00))
            ->assertCreated();

        $newOrder = Order::withoutGlobalScopes()
            ->where('id', '!=', $historicOrder->id)
            ->latest('id')
            ->firstOrFail();
        $newLine = OrderItem::query()->where('order_id', $newOrder->id)->firstOrFail();

        $this->assertEqualsWithDelta(10.00, (float) $newOrder->subtotal, 0.01);
        $this->assertEqualsWithDelta(10.00, (float) $newOrder->total, 0.01);
        $this->assertEqualsWithDelta(10.00, (float) $newLine->price, 0.01);
    }
}
