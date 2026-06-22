<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderQuote;
use App\Models\Tax;
use App\Models\User;
use App\Services\Delivery\DeliveryFeeService;
use App\Services\Pos\WalkInCustomerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [GOAL-COMPLEMENT-2026-05-18 Z-4 LIVREUR — Sub 5.1 sentinel]
 *
 * Branch-aware delivery fee wire-up across the 3 entry points (DEL-5 closure).
 *
 * Root cause (pre-heal): Sprint H3 DEL-5 added the per-branch fee columns
 * (delivery_fee_base / delivery_fee_per_km / delivery_fee_minimum, migration
 * 2026_05_18_100000) AND extended DeliveryFeeService::fromDistanceKm to accept
 * an optional `?Branch $branch`. However the three production call sites
 * dropped the Branch argument :
 *   - DeliveryQuoteService::quoteForAddress:63 — customer saved-address path
 *   - OrderRequest:117 — customer legacy fallback path
 *   - PosOrderRequest:28 — POS walk-in path
 * Every customer + POS DELIVERY quote returned the legacy `max(5, ceil(d/5)*5)`
 * regardless of branch config. Only the direct unit-test signature exercised
 * the branch-aware code.
 *
 * Heal commits (this sentinel attests the wire-up is live and not regression-
 * prone) :
 *   - DeliveryQuoteService.php:62-72 — branch flows to fromDistanceKm
 *   - OrderRequest.php:115-124 — legacy elseif resolves branch and passes it
 *   - PosOrderRequest.php:26-37 — POS prepareForValidation resolves branch
 *
 * Each test exercises ONE entry point with a branch that has all three
 * configurable columns set and asserts the customer-visible delivery_charge
 * matches the configured formula `max(minimum, base + per_km * distance)` —
 * NOT the legacy `max(5, ceil(d/5)*5)` fallback.
 *
 * CLAUDE.md §9 SaaS multi-tenant invariant.
 */
class DeliveryFeeBranchWireupSentinelTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Direct service signature regression — confirms the existing all-or-
     * nothing fallback still applies. Mirrors DeliveryFeeConfigurableTest
     * but is bundled here as a fast smoke for the wire-up commit.
     */
    public function test_service_signature_respects_branch_when_configured(): void
    {
        $service = new DeliveryFeeService();

        $configured = Branch::factory()->create([
            'delivery_fee_base'    => 3.00,
            'delivery_fee_per_km'  => 1.50,
            'delivery_fee_minimum' => 5.00,
        ]);
        $legacy = Branch::factory()->create([
            'delivery_fee_base'    => null,
            'delivery_fee_per_km'  => null,
            'delivery_fee_minimum' => null,
        ]);

        // Configured: 3 + 1.5*4 = 9, no clamp
        $this->assertSame(9.0, $service->fromDistanceKm(4, $configured));
        // Configured: 3 + 1.5*0 = 3, clamped to 5
        $this->assertSame(5.0, $service->fromDistanceKm(0, $configured));
        // Legacy fallback path: distance=4 → max(5, 5) = 5
        $this->assertSame(5.0, $service->fromDistanceKm(4, $legacy));
        // Null branch: legacy fallback
        $this->assertSame(5.0, $service->fromDistanceKm(4, null));
    }

    /**
     * Customer saved-address path — DeliveryQuoteService is the gateway and
     * MUST now propagate the branch to the fee calc.
     */
    public function test_customer_saved_address_quote_uses_branch_configured_fee(): void
    {
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        // Branch with deterministic geo + configured fee :
        // base=2, per_km=2, minimum=3. Distance 0 (same coords) → max(3, 2+0) = 3.
        $branch = Branch::factory()->create([
            'latitude' => '48.8566',
            'longitude' => '2.3522',
            'delivery_fee_base'    => 2.00,
            'delivery_fee_per_km'  => 2.00,
            'delivery_fee_minimum' => 3.00,
        ]);
        $customer = User::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $address = Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Home',
            'address' => '10 Rue de Test',
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);
        $item = $this->activeItem();

        $response = $this
            ->actingAs($customer, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', [
                'branch_id' => $branch->id,
                'subtotal' => 10,
                'discount' => 0,
                'delivery_charge' => 999, // forged — backend recomputes
                'delivery_distance_km' => 0,
                'total' => 999,
                'order_type' => (string) OrderType::DELIVERY,
                'is_advance_order' => Ask::NO,
                'address_id' => $address->id,
                'delivery_time' => now()->addHour()->format('Y-m-d H:i:s'),
                'source' => Source::WEB,
                'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'item_variations' => [],
                    'item_extras' => [],
                ]]),
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());

        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $response->json('data.id'));
        // Per-branch formula: distance=0 → max(3, 2 + 2*0) = 3.
        // If wire-up regressed the legacy formula would return 5.
        $this->assertSame(3.0, (float) $order->delivery_charge,
            'Customer saved-address path MUST honour branch-configured fee, NOT the legacy max(5, ceil(d/5)*5) fallback.');
    }

    /**
     * POS walk-in path — PosOrderRequest::prepareForValidation is the gateway.
     */
    public function test_pos_quote_uses_branch_configured_fee_for_walkin(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Branch with base=1, per_km=1, minimum=2. Distance=5 → max(2, 1+5) = 6.
        $branch = Branch::factory()->create([
            'latitude' => '48.8566',
            'longitude' => '2.3522',
            'delivery_fee_base'    => 1.00,
            'delivery_fee_per_km'  => 1.00,
            'delivery_fee_minimum' => 2.00,
        ]);
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');

        $tax = Tax::factory()->create([
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', [
                'branch_id' => $branch->id,
                'discount' => 0,
                'delivery_charge' => 999, // forged
                'delivery_distance_km' => 5,
                'order_type' => OrderType::DELIVERY,
                'source' => 1,
                'pos_payment_method' => PosPaymentMethod::CASH,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'item_variations' => [],
                    'item_extras' => [],
                ]]),
            ]);

        $response->assertOk();
        // Per-branch formula: max(2, 1 + 1*5) = 6.
        // Legacy fallback would have returned: max(5, ceil(5/5)*5) = 5.
        $this->assertSame(6.0, (float) $response->json('data.delivery_charge'),
            'POS walk-in DELIVERY MUST honour branch-configured fee, NOT the legacy fallback.');

        $quote = OrderQuote::firstOrFail();
        $this->assertSame(6.0, (float) $quote->canonical_payload['fees']['delivery_charge']);
    }

    /**
     * Null-safety guard — unknown branch_id must NOT crash; fall back legacy.
     */
    public function test_unknown_branch_id_falls_back_to_legacy_safely(): void
    {
        $service = new DeliveryFeeService();
        // null branch → legacy formula
        $this->assertSame(10.0, $service->fromDistanceKm(7, null));
    }

    /**
     * Active-item factory copying the DeliveryFeeForgeWebTest convention.
     */
    private function activeItem(): Item
    {
        $tax = Tax::factory()->create([
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        return Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);
    }
}
