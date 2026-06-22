<?php

namespace Tests\Feature\Abuse;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * VECTOR pos_delivery_charge_tamper — POS DELIVERY fee tamper abuse.
 *
 * Finding (P1, NF525 amount-tamper):
 *   PosOrderRequest::prepareForValidation() ONLY recomputes `delivery_charge`
 *   from DeliveryFeeService when `delivery_distance_km` is filled. A POS
 *   DELIVERY order submitted WITHOUT distance therefore TRUSTS the client-sent
 *   `delivery_charge` straight through to OrderService::posOrderStore →
 *   PricingRequest::forPos(... $this->order->delivery_charge) (OrderService:838)
 *   → it inflates the order total signed into the Z-report. Amounts must be
 *   backend-computed (NF525).
 *
 *   The web twin (OrderRequest:152-159) already closes this by requiring BOTH
 *   delivery_charge AND delivery_distance_km for delivery → the charge is
 *   ALWAYS recomputed from distance. But the POS UI legitimately supports a
 *   manual cashier-entered delivery fee WITHOUT distance (PosUITest), so the
 *   "make distance required" mirror would break that flow. Instead the fix
 *   NEUTRALIZES the tamper: a no-distance DELIVERY order has its delivery_charge
 *   forced to the server-trusted value (0.0 — DeliveryFeeService's own answer
 *   for a null distance) so an arbitrary client-typed charge cannot inflate the
 *   fiscal total. A real distance still triggers the existing recompute.
 *
 * :memory: caveat — these prove the request-guard LOGIC end-to-end through the
 * real /api/admin/pos store + PricingService SSOT; no concurrency is involved.
 */
class PosDeliveryChargeTamperAbuseTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;
    use SeedsOpenCashDrawerSession;

    private Branch $branch;
    private User $operator;
    private User $customer;
    private Item $item;
    private Address $deliveryAddress;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Branch with a FULL per-branch fee config so the with-distance path
        // yields a deterministic server fee (base 5 + 1/km beyond a 5km free
        // radius, min 5). Distance 5.01km → ceil(0.01)=1 chargeable km → 6.00.
        $this->branch = Branch::factory()->create([
            'latitude'             => '48.8566',
            'longitude'            => '2.3522',
            'delivery_fee_base'    => 5,
            'delivery_fee_per_km'  => 1,
            'delivery_fee_minimum' => 5,
            'delivery_fee_free_km' => 5,
        ]);

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password123'),
        ]);
        $this->operator->assignRole('POS Operator');
        $this->operator->givePermissionTo('pos');
        $this->seedOpenSessionFor($this->operator, $this->branch);

        $this->customer = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->customer->assignRole('Customer');

        $this->deliveryAddress = Address::create([
            'user_id'   => $this->customer->id,
            'label'     => 'Home',
            'address'   => '1 Rue du Test',
            'apartment' => '',
            'latitude'  => '48.8566',
            'longitude' => '2.3522',
        ]);

        $tax = Tax::factory()->create([
            'tax_rate' => 10,
            'type'     => TaxType::PERCENTAGE,
            'status'   => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create([
            'wizard_template' => 'simple',
            'has_menu'        => false,
            'status'          => Status::ACTIVE,
        ]);
        $this->item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id'           => $tax->id,
            'price'            => 10.00,
            'status'           => Status::ACTIVE,
        ]);
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function deliveryPayload(array $overrides = []): array
    {
        return array_merge([
            'token'              => null,
            'customer_id'        => $this->customer->id,
            'branch_id'          => $this->branch->id,
            'subtotal'           => 10.00,
            'discount'           => 0,
            'coupon_id'          => 0,
            'order_type'         => OrderType::DELIVERY,
            'address_id'         => $this->deliveryAddress->id,
            'delivery_time'      => '12:00 - 14:00',
            'is_advance_order'   => Ask::NO,
            'source'             => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 9999,
            'items'              => json_encode([[
                'item_id'         => $this->item->id,
                'item_price'      => $this->item->price,
                'quantity'        => 1,
                'total_price'     => 10.00,
                'item_variations' => [],
                'item_extras'     => [],
            ]]),
        ], $overrides);
    }

    private function postPosOrder(array $payload)
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));
    }

    /**
     * @test
     * CORE ABUSE — POS DELIVERY order submitted WITHOUT distance + a forged
     * inflated delivery_charge (500.50). The tamper MUST be neutralized: the
     * persisted (and therefore fiscally-signed) delivery_charge is the
     * server-trusted value (0.00), NOT the attacker's 500.50.
     */
    public function test_no_distance_delivery_charge_is_neutralized_not_trusted(): void
    {
        $response = $this->postPosOrder($this->deliveryPayload([
            'delivery_charge' => 500.50,
            // NO delivery_distance_km — this is the hole.
        ]));

        $response->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();

        // The forged 500.50 must NOT have flowed into the order / fiscal total.
        $this->assertNotEqualsWithDelta(
            500.50,
            (float) $order->delivery_charge,
            0.001,
            'Client-typed delivery_charge must NOT be trusted for a no-distance POS DELIVERY order.'
        );
        $this->assertEqualsWithDelta(
            0.00,
            (float) $order->delivery_charge,
            0.001,
            'A no-distance POS DELIVERY order must persist the server-trusted delivery_charge (0.00).'
        );
        // The forged fee must not be hidden inside the signed total either.
        $this->assertLessThan(
            500.0,
            (float) $order->total,
            'The forged delivery fee must not inflate the signed order total.'
        );
    }

    /**
     * @test
     * WITH-DISTANCE control — a bogus client delivery_charge (999) is IGNORED
     * and the server recomputes from distance (DeliveryFeeService). Mirrors
     * PosWalkInAndDeliveryFeeTest, but proven through the real store + persist.
     * Distance 5.01km on the seeded branch → 6.00.
     */
    public function test_with_distance_delivery_charge_is_recomputed_server_side(): void
    {
        $response = $this->postPosOrder($this->deliveryPayload([
            'delivery_charge'      => 999,
            'delivery_distance_km' => 5.01,
        ]));

        $response->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();

        $this->assertNotEqualsWithDelta(
            999.0,
            (float) $order->delivery_charge,
            0.001,
            'Client delivery_charge must be ignored when a distance is supplied.'
        );
        $this->assertEqualsWithDelta(
            6.00,
            (float) $order->delivery_charge,
            0.001,
            'Server must recompute the fee from distance via DeliveryFeeService.'
        );
    }

    /**
     * @test
     * CONTROL — a non-delivery (TAKEAWAY) order is unaffected by the guard:
     * no delivery_charge is persisted and the order is created normally.
     */
    public function test_takeaway_order_is_unaffected_by_delivery_guard(): void
    {
        $response = $this->postPosOrder($this->deliveryPayload([
            'order_type'    => OrderType::TAKEAWAY,
            'address_id'    => null,
            'delivery_time' => null,
            // A stray delivery_charge on a takeaway must simply be ignored.
            'delivery_charge' => 42.00,
        ]));

        $response->assertCreated();

        $order = Order::query()->latest('id')->firstOrFail();

        $this->assertSame(OrderType::TAKEAWAY, (int) $order->order_type);
        $this->assertEqualsWithDelta(
            0.00,
            (float) ($order->delivery_charge ?? 0),
            0.001,
            'A TAKEAWAY order must carry no delivery charge.'
        );
    }
}
