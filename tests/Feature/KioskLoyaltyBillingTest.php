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
use App\Models\LoyaltyTransaction;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [HEAL dispute-r1 C-RED-02 2026-06-12] Kiosk loyalty redemption billing.
 *
 * Pre-fix the borne displayed « Réduction fidélité −1,65 € » but the order
 * was created FULL PRICE (order 4565: total 9.50, discount 0, points never
 * debited). Root cause: withKioskLoyaltyDiscount / applyKioskLoyaltyDiscount
 * required `discount > 0` from the request while buildKioskQuotePayload
 * (kioskCart.js) never sends the field — the guard ALWAYS short-circuited.
 *
 * Backend contract healed here: the redeem intent travels in the dedicated
 * `loyalty_redeem_discount` field (precedence over legacy `discount`, which
 * the kiosk order payload overwrites with quote.discount — ambiguous when a
 * promo stacks). Identify-only customers (loyalty_code WITHOUT redeem field)
 * must NEVER be auto-redeemed. Frontend wire-up (sending the field from
 * state.loyaltyDiscount) is the H2 counterpart.
 */
class KioskLoyaltyBillingTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_quote_applies_loyalty_redemption_from_dedicated_field(): void
    {
        [$kioskUser, $payload] = $this->kioskFixture();
        $this->makeLoyaltyCustomer('VICT1234', 150);

        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload + [
                'loyalty_code' => 'VICT1234',
                'loyalty_redeem_discount' => 1.00,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(1.0, (float) $quote['discount']);
        $this->assertSame(7.25, (float) $quote['total_ttc']);
    }

    public function test_kiosk_order_bills_loyalty_redemption_and_debits_points(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload] = $this->kioskFixture();
        $customer = $this->makeLoyaltyCustomer('VICT1234', 150);

        $payload['loyalty_code'] = 'VICT1234';
        $payload['loyalty_redeem_discount'] = 1.00;

        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        // Real frontend shape (buildKioskOrderPayload): discount is OVERWRITTEN
        // with quote.discount — the dedicated field must keep precedence.
        $response = $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'discount' => $quote['discount'],
                'total' => $quote['total_ttc'],
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());

        $order = FrontendOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(1.0, round((float) $order->discount, 2), 'loyalty discount must be billed');
        $this->assertSame(7.25, round((float) $order->total, 2));
        $this->assertSame(50, (int) $customer->fresh()->loyalty_points, '100 points (1€ @ rate 100) must be debited');
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $customer->id,
            'order_id' => $order->id,
            'type' => 'redeem',
            'points' => -100,
        ]);
    }

    public function test_identify_only_customer_is_never_auto_redeemed(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload] = $this->kioskFixture();
        $customer = $this->makeLoyaltyCustomer('VICT1234', 150);

        // loyalty_code present (accrual identification) but NO redeem intent.
        $payload['loyalty_code'] = 'VICT1234';

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
        $this->assertSame(150, (int) $customer->fresh()->loyalty_points, 'identify-only must not burn points');
        $order = FrontendOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(0.0, round((float) $order->discount, 2));
    }

    public function test_legacy_discount_field_still_drives_redemption(): void
    {
        [$kioskUser, $payload] = $this->kioskFixture();
        $this->makeLoyaltyCustomer('VICT1234', 150);

        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload + [
                'loyalty_code' => 'VICT1234',
                'discount' => 1.00,
            ])
            ->assertOk()
            ->json('data');

        $this->assertSame(1.0, (float) $quote['discount']);
    }

    public function test_promo_and_loyalty_stack_consistently_through_commit(): void
    {
        config(['app.api_key' => 'test-api-key']);
        [$kioskUser, $payload] = $this->kioskFixture();
        $customer = $this->makeLoyaltyCustomer('VICT1234', 150);
        $promo = KioskPromo::create([
            'branch_id' => $payload['branch_id'],
            'code' => 'BORNETEST5',
            'type' => 'amount',
            'value' => 2.00,
            'min_cart' => 0,
            'max_uses' => null,
            'uses_count' => 0,
            'active' => true,
        ]);

        $payload['loyalty_code'] = 'VICT1234';
        $payload['loyalty_redeem_discount'] = 1.00;
        $payload['kiosk_promo_code'] = 'BORNETEST5';

        $quote = $this->actingAs($kioskUser, 'sanctum')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $this->assertSame(3.0, (float) $quote['discount'], 'promo 2.00 + loyalty 1.00');
        $this->assertSame(5.25, (float) $quote['total_ttc']);

        // Frontend overwrite hazard: order payload discount = quote.discount
        // (COMBINED 3.00). The loyalty engine must redeem 1.00 (dedicated
        // field), not try to redeem 3.00 — otherwise quote/commit diverge.
        $response = $this->actingAs($kioskUser, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
                'discount' => $quote['discount'],
                'total' => $quote['total_ttc'],
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $order = FrontendOrder::query()->latest('id')->firstOrFail();
        $this->assertSame(3.0, round((float) $order->discount, 2));
        $this->assertSame(5.25, round((float) $order->total, 2));
        $this->assertSame(50, (int) $customer->fresh()->loyalty_points);
        $this->assertSame(1, (int) $promo->fresh()->uses_count);
    }

    private function makeLoyaltyCustomer(string $code, int $points): User
    {
        return User::factory()->create([
            'branch_id' => 0,
            'loyalty_code' => $code,
            'loyalty_points' => $points,
            'status' => 1,
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
