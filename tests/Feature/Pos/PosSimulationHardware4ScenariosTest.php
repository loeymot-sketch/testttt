<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderPayment;
use App\Models\PaymentTerminal;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [2026-05-18] POS — 4 payment scenarios end-to-end with hardware simulation.
 *
 * Proves that with `POS_SIMULATION_HARDWARE=true`:
 *   S1. CASH overpay (montant reçu > total) creates an order (201).
 *   S2. CARD (TPE simulated via last-4-digits) creates an order (201).
 *   S3. Split equal (2 CASH tranches summing to total) creates an order (201).
 *   S4. Split mixed (1 CASH + 1 CARD) creates an order (201).
 *
 * All 4 paths must succeed WITHOUT seeding an OPEN CashDrawerSession — this
 * is the hardware bypass under test. Pricing/composition/fiscal-sequence/
 * audit-chain invariants stay enforced (the test uses a simple Item with
 * no wizard profile so composition validation is a no-op).
 */
class PosSimulationHardware4ScenariosTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $customer;
    protected User $operator;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Config::set('pos.simulation_hardware', true);
        Config::set('split_payment.enabled', true);
        Config::set('split_payment.max_tranches', 12);
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create();

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030400',
        ]);
        $this->customer->assignRole('Customer');

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030401',
        ]);
        $this->operator->assignRole('POS Operator');

        $tax = Tax::factory()->create([
            'name' => 'TVA 0%',
            'code' => 'TVA0',
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0.00,
            'status' => Status::ACTIVE,
        ]);

        $cat = ItemCategory::factory()->create([
            'name' => 'Boissons',
            'wizard_template' => 'simple',
            'has_menu' => false,
        ]);

        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'tax_id' => $tax->id,
            'name' => 'Coca-Cola 33cl',
            'price' => 1.50,
            'status' => Status::ACTIVE,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'token' => null,
            'customer_id' => $this->customer->id,
            'branch_id' => $this->branch->id,
            'discount' => 0,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => 0,
            'source' => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
            'items' => json_encode([
                [
                    'item_id' => $this->item->id,
                    'item_price' => 1.50,
                    'quantity' => 1,
                    'total_price' => 1.50,
                    'item_variations' => [],
                    'item_extras' => [],
                ],
            ]),
        ], $overrides);
    }

    public function test_s1_cash_overpay_creates_order_without_open_drawer_when_simulating(): void
    {
        $this->actingAs($this->operator, 'sanctum');
        // NO openSession — simulation bypass under test.

        $payload = $this->basePayload([
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 10.00,
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertNotNull($orderId, 'S1 cash overpay must create an order under simulation.');

        $order = Order::withoutGlobalScopes()->find($orderId);
        $this->assertSame(PosPaymentMethod::CASH, (int) $order->pos_payment_method);
    }

    public function test_s2_card_creates_order_without_open_drawer_when_simulating(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload([
            'pos_payment_method' => PosPaymentMethod::CARD,
            'pos_payment_note' => 1234,
            'pos_received_amount' => 0,
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertNotNull($orderId, 'S2 card must create an order under simulation.');

        $order = Order::withoutGlobalScopes()->find($orderId);
        $this->assertSame(PosPaymentMethod::CARD, (int) $order->pos_payment_method);
    }

    public function test_s3_split_equal_2_cash_tranches_succeeds_when_simulating(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        // 1.50 split equally = 0.75 + 0.75.
        $breakdown = [
            ['mode' => PosPaymentMethod::CASH, 'amount' => 0.75, 'tendered' => 0.75],
            ['mode' => PosPaymentMethod::CASH, 'amount' => 0.75, 'tendered' => 0.75],
        ];

        $payload = $this->basePayload([
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 1.50,
            'pos_payment_note' => 'multi-tender',
            'payment_breakdown' => $breakdown,
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertNotNull($orderId, 'S3 split-equal must create an order under simulation.');

        $payments = OrderPayment::where('order_id', $orderId)->get();
        $this->assertCount(2, $payments, 'S3 must persist 2 tranches.');
        foreach ($payments as $p) {
            $this->assertSame(PosPaymentMethod::CASH, (int) $p->mode);
            $this->assertEqualsWithDelta(0.75, (float) $p->amount, 0.01);
        }
    }

    public function test_s4_split_mixed_cash_plus_card_succeeds_when_simulating(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $terminal = PaymentTerminal::create([
            'branch_id'    => $this->branch->id,
            'name'         => 'TPE Simulation',
            'gateway_type' => PaymentTerminal::GATEWAY_MANUAL,
            'fee_percent'  => 0,
            'fee_fixed'    => 0,
            'status'       => PaymentTerminal::STATUS_ACTIVE,
        ]);

        $breakdown = [
            ['mode' => PosPaymentMethod::CASH, 'amount' => 0.75, 'tendered' => 0.75],
            ['mode' => PosPaymentMethod::CARD, 'amount' => 0.75, 'reference' => '1234', 'terminal_id' => $terminal->id],
        ];

        // Mixed split: dominant tender must be CARD because the CASH tranche
        // alone (0.75€) is below the 1.50€ total — OrderService's legacy
        // single-tender sufficiency check would otherwise 422. PosOrderRequest
        // requires `pos_payment_note` to be a 4-digit numeric string when
        // dominant is CARD.
        $payload = $this->basePayload([
            'pos_payment_method' => PosPaymentMethod::CARD,
            'pos_received_amount' => 0,
            'pos_payment_note' => '1234',
            'payment_breakdown' => $breakdown,
        ]);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertNotNull($orderId, 'S4 split-mixed must create an order under simulation.');

        $payments = OrderPayment::where('order_id', $orderId)->orderBy('id')->get();
        $this->assertCount(2, $payments, 'S4 must persist 2 tranches.');
        $modes = $payments->pluck('mode')->map(fn ($v) => (int) $v)->all();
        $this->assertContains(PosPaymentMethod::CASH, $modes, 'S4 must contain a CASH tranche.');
        $this->assertContains(PosPaymentMethod::CARD, $modes, 'S4 must contain a CARD tranche.');
    }

    /** Z — Order created via simulation surfaces on the chef's KDS endpoint. */
    public function test_z_pos_order_appears_on_kds_endpoint_for_chef(): void
    {
        // 1) POS operator creates a CASH order under simulation.
        $this->actingAs($this->operator, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $this->basePayload()));
        $response->assertStatus(201);
        $orderId = (int) $response->json('data.id');
        $this->assertGreaterThan(0, $orderId);

        // 2) A chef on the same branch hits the KDS index endpoint and sees the order.
        $chef = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030402',
        ]);
        $chef->assignRole('Chef');

        $this->actingAs($chef, 'sanctum');
        $kds = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/admin/kds-order');
        $kds->assertStatus(200);

        $payload = $kds->json('data') ?? $kds->json();
        $rows = is_array($payload) ? $payload : [];
        // Some KDS responses wrap rows in `data` -> array, others nest by status.
        $idsFound = collect(json_decode(json_encode($rows), true))
            ->flatten(2)
            ->filter(fn ($v) => is_numeric($v))
            ->map(fn ($v) => (int) $v)
            ->all();
        $this->assertContains(
            $orderId,
            $idsFound,
            'KDS endpoint must return the new POS order so the chef sees it on /kds.'
        );
    }

    public function test_simulation_off_still_requires_open_drawer_for_cash(): void
    {
        // Sanity check: when simulation is off, the original guard fires.
        Config::set('pos.simulation_hardware', false);

        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload();

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(422);
        $this->assertSame('CASH_NO_OPEN_SESSION', $response->json('code'));
    }
}
