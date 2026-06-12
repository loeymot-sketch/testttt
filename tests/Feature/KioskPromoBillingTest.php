<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\KioskPromo;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [HEAL dispute-r1 C-RED-01 / E-ADV-1 2026-06-12] Kiosk promo billing.
 *
 * Pre-fix the borne DISPLAYED the promo (PricingPreviewService applies
 * kiosk_promo_code) but the quote/order pipeline silently ignored it:
 * OrderQuoteService::calculatePricing(kiosk) never read the code (it only
 * entered the canonical signature as metadata), so the order was created at
 * FULL PRICE — the customer saw 0,00 € on the payment screen and was charged
 * 3,00 € at the counter (orders 4566/4518/4531, uses_count never consumed).
 *
 * The discount must traverse quote → order → persisted totals WITHOUT
 * touching the frozen PricingService — promo is applied as an order-level
 * discount on top of the SSOT PricingResult, exactly like kiosk loyalty.
 */
class KioskPromoBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_quote_applies_valid_promo_discount(): void
    {
        [$kioskUser, $payload] = $this->kioskFixture();
        $this->makePromo($payload['branch_id'], 'BORNETEST5', 'amount', 2.00);

        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload + ['kiosk_promo_code' => 'BORNETEST5'])
            ->assertOk()
            ->json('data');

        $this->assertSame(2.0, (float) $quote['discount']);
        $this->assertSame(6.25, (float) $quote['total_ttc']);
    }

    public function test_kiosk_order_bills_promo_discount_and_consumes_promo(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload] = $this->kioskFixture();
        $promo = $this->makePromo($payload['branch_id'], 'BORNETEST5', 'amount', 2.00);

        $payload['kiosk_promo_code'] = 'BORNETEST5';

        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $response = $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());

        $order = FrontendOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(2.0, round((float) $order->discount, 2), 'promo discount must be billed on the order');
        $this->assertSame(6.25, round((float) $order->total, 2), 'order total must match the discounted quote');
        $this->assertSame(1, (int) $promo->fresh()->uses_count, 'promo must be consumed at order creation');
    }

    public function test_kiosk_order_with_invalid_promo_code_charges_full_price(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload] = $this->kioskFixture();

        $payload['kiosk_promo_code'] = 'DOESNOTEXIST';

        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $this->assertSame(0.0, (float) $quote['discount']);
        $this->assertSame(8.25, (float) $quote['total_ttc']);

        $response = $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $order = FrontendOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(8.25, round((float) $order->total, 2));
        $this->assertSame(0.0, round((float) $order->discount, 2));
    }

    public function test_kiosk_promo_added_after_quote_is_rejected_as_intent_mismatch(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload] = $this->kioskFixture();
        $this->makePromo($payload['branch_id'], 'BORNETEST5', 'amount', 2.00);

        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        // Tampering: the commit suddenly carries a promo code the sealed quote
        // never saw → binding intent must reject (anti-tamper preserved).
        $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $payload + [
                'kiosk_promo_code' => 'BORNETEST5',
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'total' => $quote['total_ttc'],
            ])
            ->assertStatus(409);
    }

    public function test_kiosk_promo_respects_max_uses_at_billing_time(): void
    {
        [$kioskUser, $payload] = $this->kioskFixture();
        $this->makePromo($payload['branch_id'], 'BORNEMAX1', 'percent', 100.0, maxUses: 1, usesCount: 1);

        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload + ['kiosk_promo_code' => 'BORNEMAX1'])
            ->assertOk()
            ->json('data');

        $this->assertSame(0.0, (float) $quote['discount'], 'exhausted promo must not discount');
        $this->assertSame(8.25, (float) $quote['total_ttc']);
    }

    private function makePromo(
        int $branchId,
        string $code,
        string $type,
        float $value,
        ?int $maxUses = null,
        int $usesCount = 0
    ): KioskPromo {
        return KioskPromo::create([
            'branch_id' => $branchId,
            'code' => $code,
            'type' => $type,
            'value' => $value,
            'min_cart' => 0,
            'valid_from' => null,
            'valid_to' => null,
            'max_uses' => $maxUses,
            'uses_count' => $usesCount,
            'active' => true,
        ]);
    }

    /**
     * @return array{0: User, 1: array<string, mixed>}
     */
    private function kioskFixture(): array
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $kioskUser = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
        ]);

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 8.25,
            'status' => Status::ACTIVE,
        ]);

        return [$kioskUser, [
            'branch_id' => $branch->id,
            'order_type' => OrderType::KIOSK,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CARD,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ]];
    }
}
