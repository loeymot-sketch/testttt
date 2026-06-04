<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\TaxType;
use App\Enums\Ask;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * @FK-ID FK-018 | @source reports/audit/TRACEABILITY_MATRIX_CAISSE_V1_2026-04-25.md | @fix-mission CV1-M06-POS-REVENUE-GUARDS | @reason POS discount validation still uses client-supplied subtotal before backend pricing recalculation.
 */
class PosSubtotalForgerySentinelTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('pricing.use_ssot_service', false);
        Config::set('app.api_key', 'test-api-key');
        Config::set('broadcasting.default', 'log');
        Config::set('fiscal.audit_secret', str_repeat('a', 48));
        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] This sentinel forges a
        // discount to prove backend-subtotal is used; enable the discount flag
        // (V1 default OFF) so the forgery path runs rather than the V1 gate.
        Config::set('pos.manual_discount_enabled', true);
    }

    public function test_pos_discount_permission_uses_backend_subtotal_not_forged_client_subtotal(): void
    {
        $branch = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $cashier->assignRole('POS Operator');
        // [Sprint H6 TEST-DEBT-001 2026-05-17] Sprint 1B requires an OPEN cash session for CASH.
        $this->seedOpenSessionFor($cashier, $branch);

        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::FIXED]);
        $item = Item::factory()->create(['price' => 100.00, 'tax_id' => $tax->id]);

        $payload = [
                'order_type' => OrderType::POS,
                'subtotal' => 1.00,
                'discount' => 10.00,
                'discount_reason' => 'sentinel ten percent',
                'delivery_charge' => 0,
                'total' => 1.00,
                'source' => Source::POS,
                'customer_id' => $cashier->id,
                'branch_id' => $branch->id,
                'is_advance_order' => Ask::NO,
                'pos_payment_method' => PosPaymentMethod::CASH,
                'pos_received_amount' => 100.00,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'quantity' => 1,
                ]]),
        ];

        $quote = $this->actingAs($cashier, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $response = $this->actingAs($cashier, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', 'sentinel-psf-' . uniqid())
            ->postJson('/api/admin/pos', array_merge($payload, [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
                'pos_received_amount' => $quote['total_ttc'],
            ]));

        $response->assertStatus(201);

        $order = Order::withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertEqualsWithDelta(100.00, (float) $order->subtotal, 0.01);
        $this->assertEqualsWithDelta(10.00, (float) $order->discount, 0.01);
    }
}
