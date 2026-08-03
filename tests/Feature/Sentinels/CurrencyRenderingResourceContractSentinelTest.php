<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Resources\OrderDetailsResource;
use App\Http\Resources\OrderItemResource;
use App\Http\Resources\SimpleOrderResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [WT-D-R1-F4 2026-05-20] Sentinel — admin currency rendering contract.
 *
 * Root cause of WT-D-R1-03 (Wave T R1):
 *   The same order total was rendered three different ways across surfaces:
 *     - Tracker      → "19,00 €"  (Intl.NumberFormat fr-FR EUR)
 *     - Orders list  → "19.00"    ({{ total_amount_price }} = flatAmountFormat)
 *     - Detail page  → "19.00€"   ({{ total_currency_price }} = glued symbol)
 *
 *   Each surface had picked a different backend projection of `orders.total`.
 *   The strings were independently correct but the user-visible result was
 *   three layouts for one value. Heal: every admin surface renders via a
 *   shared `formatPrice()` helper (helpers/formatPrice.js) consuming the raw
 *   numeric `total` (added to SimpleOrderResource + OrderDetailsResource +
 *   OrderItemResource).
 *
 * What this sentinel asserts (the contract that locks the heal in):
 *
 *   1. SimpleOrderResource ships `total`, `subtotal`, `discount`,
 *      `delivery_charge` as **raw numerics** (float-typed in JSON, rounded
 *      to 2 decimals). Without these the admin orders list would silently
 *      regress to `formatPrice(undefined) → "0,00 €"` for every row.
 *
 *   2. OrderDetailsResource ships the same raw numerics PLUS `delivery_charge`
 *      (already had subtotal/discount/total_tax/total; F4 adds delivery_charge).
 *
 *   3. OrderItemResource ships raw `total_price` (was only shipping
 *      `total_currency_price` / `total_convert_price`). Without this the
 *      per-item line on the detail page would silently regress.
 *
 *   4. The numeric values must equal the model's underlying decimal — no
 *      hidden currency conversion / rounding drift sneaks into the
 *      presentation projection.
 *
 * The legacy `*_currency_price` / `*_amount_price` string fields stay shipped
 * for backward compat (exports, reports, legacy templates) — this sentinel
 * does NOT assert their absence. It only locks in the raw-numeric additions.
 */
class CurrencyRenderingResourceContractSentinelTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->user = User::factory()->create(['branch_id' => $this->branch->id]);
    }

    public function test_simple_order_resource_ships_raw_numeric_total_for_admin_format_price(): void
    {
        $order = Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'user_id'        => $this->user->id,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal'       => 19.00,
            'discount'       => 0.00,
            'delivery_charge' => 0.00,
            'total'          => 19.00,
        ]);

        $payload = (new SimpleOrderResource($order))->resolve(request());

        // Raw numerics — the WT-D-R1-F4 heal contract.
        $this->assertArrayHasKey('total', $payload,
            'SimpleOrderResource MUST ship raw numeric `total` for admin formatPrice() rendering. '.
            'Without this, PosOrderListComponent regresses to "0,00 €" for every row.');
        $this->assertIsNumeric($payload['total']);
        $this->assertSame(19.00, (float) $payload['total']);

        $this->assertArrayHasKey('subtotal', $payload);
        $this->assertSame(19.00, (float) $payload['subtotal']);

        $this->assertArrayHasKey('discount', $payload);
        $this->assertSame(0.00, (float) $payload['discount']);

        $this->assertArrayHasKey('delivery_charge', $payload);
        $this->assertSame(0.00, (float) $payload['delivery_charge']);

        // Legacy string fields stay shipped (backward compat).
        $this->assertArrayHasKey('total_amount_price', $payload);
        $this->assertArrayHasKey('total_currency_price', $payload);
    }

    public function test_order_details_resource_ships_raw_numeric_delivery_charge(): void
    {
        $order = Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'user_id'        => $this->user->id,
            'order_type'     => OrderType::DELIVERY,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal'       => 17.00,
            'discount'       => 0.00,
            'delivery_charge' => 2.00,
            'total'          => 19.00,
        ]);

        $payload = (new OrderDetailsResource($order))->resolve(request());

        // Raw numerics for PosOrderShowComponent formatPrice() rendering.
        foreach (['subtotal', 'discount', 'total_tax', 'total', 'delivery_charge'] as $key) {
            $this->assertArrayHasKey($key, $payload,
                "OrderDetailsResource MUST ship raw numeric `{$key}` for admin formatPrice() rendering.");
            $this->assertIsNumeric($payload[$key]);
        }

        $this->assertSame(17.00, (float) $payload['subtotal']);
        $this->assertSame(2.00, (float) $payload['delivery_charge']);
        $this->assertSame(19.00, (float) $payload['total']);
    }

    public function test_order_item_resource_ships_raw_numeric_total_price(): void
    {
        $order = Order::factory()->create([
            'branch_id'      => $this->branch->id,
            'user_id'        => $this->user->id,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'subtotal'       => 19.00,
            'discount'       => 0.00,
            'delivery_charge' => 0.00,
            'total'          => 19.00,
        ]);

        // Build a minimal Item via factory (OrderItem requires items FK).
        $catalogItem = Item::factory()->create();

        $item = OrderItem::create([
            'order_id'             => $order->id,
            'branch_id'            => $this->branch->id,
            'item_id'              => $catalogItem->id,
            'quantity'             => 1,
            'discount'             => 0,
            'price'                => 19.00,
            'item_variations'      => json_encode([]),
            'item_extras'          => json_encode([]),
            'item_variation_total' => 0,
            'item_extra_total'     => 0,
            'total_price'          => 19.00,
            'tax_name'             => 'TVA 10',
            'tax_rate'             => 10,
            'tax_type'             => 1,
            'tax_amount'           => 1.73,
        ]);

        $payload = (new OrderItemResource($item))->resolve(request());

        $this->assertArrayHasKey('total_price', $payload,
            'OrderItemResource MUST ship raw numeric `total_price` for admin formatPrice() rendering. '.
            'Without this, PosOrderShowComponent per-line item amounts regress to "0,00 €".');
        $this->assertIsNumeric($payload['total_price']);
        $this->assertSame(19.00, (float) $payload['total_price']);
    }
}
