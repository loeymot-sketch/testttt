<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [GOAL-CAISSE-UNIFIED delta-(B) 2026-05-30] POS walk-in CREATE path.
 *
 * Proves OrderService::posOrderStore honours the deferred-routing signal:
 * - DEFAULT (flag OFF): a POS walk-in is created PAID with a fiscal seq
 *   allocated at creation — the legacy inline-paid flow is untouched.
 * - DEFERRED (defer_to_counter=true OR pos.walkin_route_to_counter ON): the
 *   order is created PENDING_COUNTER + COUNTER_DEFERRED + CASH_ON_DELIVERY
 *   and NO fiscal seq is burned at creation (allocated later at collection).
 *
 * Mirrors the PosSimulationHardware4ScenariosTest harness (simulation_hardware
 * ON so no open drawer is required; TVA 0% simple item, no wizard profile).
 */
class PosWalkinDeferredCreateTest extends TestCase
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
            'name' => 'TVA 0%', 'code' => 'TVA0', 'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0.00, 'status' => Status::ACTIVE,
        ]);
        $cat = ItemCategory::factory()->create([
            'name' => 'Boissons', 'wizard_template' => 'simple', 'has_menu' => false,
        ]);
        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id, 'tax_id' => $tax->id,
            'name' => 'Coca-Cola 33cl', 'price' => 1.50, 'status' => Status::ACTIVE,
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
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'item_price' => 1.50,
                'quantity' => 1,
                'total_price' => 1.50,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    public function test_default_flow_creates_paid_pos_order_with_fiscal_seq(): void
    {
        Config::set('pos.walkin_route_to_counter', false);
        $this->actingAs($this->operator, 'sanctum');

        // [prod-finale 2026-06-17] /api/admin/pos requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $this->basePayload()));

        $response->assertStatus(201);
        $order = Order::withoutGlobalScopes()->find($response->json('data.id'));
        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status);
        $this->assertNotNull($order->fiscal_sequence_no, 'inline-paid POS keeps fiscal-at-creation');
    }

    public function test_defer_to_counter_flag_creates_pending_counter_without_fiscal_seq(): void
    {
        Config::set('pos.walkin_route_to_counter', false); // prove the PER-REQUEST opt-in
        $this->actingAs($this->operator, 'sanctum');

        $payload = $this->basePayload(['defer_to_counter' => true]);
        // [prod-finale 2026-06-17] /api/admin/pos requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $order = Order::withoutGlobalScopes()->find($response->json('data.id'));

        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $order->payment_status);
        $this->assertSame(PosPaymentMethod::COUNTER_DEFERRED, (int) $order->pos_payment_method);
        $this->assertSame(PaymentGateway::CASH_ON_DELIVERY, (int) $order->payment_method);
        $this->assertSame('pos', (string) $order->source_surface);
        $this->assertNull($order->fiscal_sequence_no, 'deferred POS order must NOT burn a fiscal seq at creation');
    }

    public function test_global_flag_routes_all_walkin_to_counter(): void
    {
        Config::set('pos.walkin_route_to_counter', true);
        $this->actingAs($this->operator, 'sanctum');

        // [prod-finale 2026-06-17] /api/admin/pos requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $this->basePayload()));

        $response->assertStatus(201);
        $order = Order::withoutGlobalScopes()->find($response->json('data.id'));
        $this->assertSame(PaymentStatus::PENDING_COUNTER, (int) $order->payment_status);
        $this->assertNull($order->fiscal_sequence_no);
    }
}
