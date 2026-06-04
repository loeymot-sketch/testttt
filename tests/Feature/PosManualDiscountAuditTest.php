<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderDiscountLog;
use App\Models\Tax;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

class PosManualDiscountAuditTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_manual_pos_discount_writes_actor_reason_and_backend_subtotal_audit(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config([
            'app.api_key' => 'test-api-key',
            'broadcasting.default' => 'log',
            'fiscal.audit_secret' => str_repeat('a', 48),
            'pricing.use_ssot_service' => true,
            // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] manual-discount audit
            // suite — enable the flag (V1 default OFF).
            'pos.manual_discount_enabled' => true,
        ]);

        $branch = Branch::factory()->create();
        $operator = User::factory()->create([
            'branch_id' => $branch->id,
            'password' => Hash::make('password'),
        ]);
        $operator->syncPermissions(['pos', 'pos-orders', 'pos-discount-up-to-10']);
        // [Sprint H6 TEST-DEBT-001 2026-05-17] Sprint 1B requires an OPEN cash session for CASH.
        $this->seedOpenSessionFor($operator, $branch);

        $customer = User::factory()->create(['branch_id' => $branch->id]);
        $customer->assignRole('Customer');

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 100.00,
            'status' => Status::ACTIVE,
        ]);

        $payload = [
            'token' => null,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'subtotal' => 1.00,
            'discount' => 5.00,
            'discount_reason' => 'Geste fidélité caisse',
            'coupon_id' => 0,
            'total' => 1.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::POS,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount' => 1.00,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];

        $quote = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', $payload)
            ->assertOk()
            ->json('data');

        $response = $this->actingAs($operator, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos', array_merge($payload, [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
                'pos_received_amount' => $quote['total_ttc'],
            ]));

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $orderId = (int) $response->json('data.id');

        $audit = OrderDiscountLog::query()->forOrder($orderId)->first();

        $this->assertNotNull($audit, 'Expected a typed order discount audit entry.');
        $this->assertSame((int) $branch->id, (int) $audit->branch_id);
        $this->assertSame((int) $operator->id, (int) $audit->user_id);
        $this->assertSame(OrderDiscountLog::ACTION, $audit->action);
        $this->assertSame('order', $audit->resource);

        $payload = $audit->payload;
        $this->assertSame((int) $operator->id, (int) $payload['actor_id']);
        $this->assertSame('Geste fidélité caisse', $payload['discount_reason']);
        $this->assertSame('manual_cashier', $payload['discount_type']);
        $this->assertEqualsWithDelta(5.00, (float) $payload['discount_amount'], 0.01);
        $this->assertEqualsWithDelta(100.00, (float) $payload['backend_subtotal'], 0.01);
        $this->assertEqualsWithDelta(95.00, (float) $payload['total_after'], 0.01);

        $this->assertNull(
            app(AuditLogService::class)->verifyChain($branch->id),
            'Audit chain must stay intact after manual POS discount write.'
        );
    }
}
