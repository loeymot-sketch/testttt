<?php

namespace Tests\Feature\Pos;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * [POS-1 REGRESSION 2026-07-10] A POS DELIVERY order whose subtotal reaches the owner
 * free-delivery threshold (≥30€) was HARD-BLOCKED with HTTP 409 "Order total does not match
 * sealed quote total": the free-delivery rule lived only in the order-create path
 * (OrderService:860-878), not the quote path (OrderQuoteService) — so quote total (with delivery)
 * ≠ order total (without) and sealForCommit rejected the whole transaction. Fix: apply the same
 * rule in OrderQuoteService::calculatePricing so quote == order. This locks it.
 */
class PosFreeDeliveryQuoteSealTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    /** @test */
    public function pos_delivery_over_free_threshold_quotes_free_and_commits(): void
    {
        config(['app.api_key' => 'test-api-key']);
        config(['pricing.tax_inclusive_prices' => true]);
        config(['pricing.use_ssot_service' => true]);
        Settings::group('delivery')->set('free_delivery_above', 30);

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $this->seedOpenSessionFor($operator, $branch);
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');
        $address = \App\Models\Address::create([
            'user_id' => $customer->id, 'label' => 'Home', 'address' => '1 rue de Test',
            'apartment' => '2', 'latitude' => '50.45', 'longitude' => '2.65',
        ]);

        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        // 4 x 8.00 = 32.00 >= 30 => free delivery applies.
        $item = Item::factory()->create([
            'item_category_id' => $category->id, 'tax_id' => $tax->id,
            'price' => 8.00, 'status' => Status::ACTIVE,
        ]);

        $payload = [
            'token' => null, 'customer_id' => $customer->id, 'branch_id' => $branch->id,
            'subtotal' => 0, 'discount' => 0, 'coupon_id' => 0, 'total' => 0,
            'order_type' => OrderType::DELIVERY, 'address_id' => $address->id,
            'delivery_charge' => 4.00, 'is_advance_order' => Ask::NO, 'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH, 'pos_received_amount' => 0,
            'items' => json_encode([[
                'item_id' => $item->id, 'quantity' => 4, 'item_variations' => [], 'item_extras' => [],
            ]]),
        ];

        $quote = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        // The quote must now REMOVE the delivery charge (free ≥30€) → total = 32, delivery = 0.
        $this->assertEqualsWithDelta(32.00, (float) $quote['total_ttc'], 0.001, 'quote total should exclude free delivery');
        $this->assertEqualsWithDelta(0.0, (float) $quote['delivery_charge'], 0.001, 'quote delivery_charge should be 0');

        // The commit must now SUCCEED (no 409) with the matching sealed total.
        $response = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', array_merge($payload, [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
                'pos_received_amount' => $quote['total_ttc'],
            ]));

        $this->assertNotSame(409, $response->status(), 'commit must not 409 on free-delivery order');
        $this->assertGreaterThan(0, Order::count(), 'order must be created');
        $o = Order::first();
        $this->assertEqualsWithDelta(0.0, (float) $o->delivery_charge, 0.001, 'order delivery free');
        $this->assertEqualsWithDelta(32.00, (float) $o->total, 0.001, 'order total excludes delivery');
    }
}
