<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class KioskLoyaltyDoubleRedeemRefusedTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $kioskUser;
    protected User $loyaltyCustomer;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        \Smartisan\Settings\Facades\Settings::group('pos')->set(['pos_dine_in_enabled' => true]); // [2026-07-27] garde V1 sur-place (47f3ad545) : OFF par défaut — ce test exerce un flux sur-place/table derrière son flag
        $this->seedMinimalSettings();
        $this->bypassKioskQuoteSealForLoyaltySentinel();

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);
        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 50,
        ]);

        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] These tests exercise the kiosk
        // loyalty-redeem MECHANICS (single decrement, pending-join, idempotent ledger) —
        // not the V1 on/off policy. Enable the discretionary-discount master flag so the
        // redeem path runs; the OFF default is locked by FrontendDiscountIntegrityTest
        // ::test_discretionary_discount_disabled_by_default_on_frontend_v1().
        config(['pos.manual_discount_enabled' => true]);

        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
        $this->kioskUser = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => Status::ACTIVE,
        ]);
        KioskMachine::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $this->kioskUser->id,
            'status' => Status::ACTIVE,
            'is_login' => Ask::NO,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Loyalty Kiosk',
            'slug' => 'loyalty-kiosk',
            'status' => Status::ACTIVE,
        ]);
        $this->item = Item::forceCreate([
            'name' => 'Loyalty Menu',
            'slug' => 'loyalty-menu',
            'price' => 20,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);
        $this->loyaltyCustomer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 1,
            'loyalty_code' => 'LOYALDOUBLE100',
            'loyalty_points' => 500,
        ]);
    }

    public function test_redeem_then_kiosk_order_joins_existing_transaction_without_second_decrement(): void
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $this->postJson('/api/frontend/loyalty/redeem', [
            'code' => $this->loyaltyCustomer->loyalty_code,
            'points' => 100,
        ])->assertOk();

        $response = $this->postJson('/api/frontend/order', $this->orderPayload([
            'discount' => 1.00,
            'loyalty_code' => $this->loyaltyCustomer->loyalty_code,
        ]));

        $response->assertCreated();
        $orderId = (int) $response->json('data.id');

        $this->assertSame(400, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertSame(1, LoyaltyTransaction::where('type', 'redeem')->count());
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $this->loyaltyCustomer->id,
            'order_id' => $orderId,
            'type' => 'redeem',
            'points' => -100,
        ]);
        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount' => 1.00,
        ]);
        $this->assertTrue((bool) $response->json('loyalty_applied'));
    }

    public function test_kiosk_order_direct_redeem_still_deducts_once_and_writes_ledger(): void
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $response = $this->postJson('/api/frontend/order', $this->orderPayload([
            'discount' => 1.00,
            'loyalty_code' => $this->loyaltyCustomer->loyalty_code,
        ]));

        $response->assertCreated();
        $orderId = (int) $response->json('data.id');

        $this->assertSame(400, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $this->loyaltyCustomer->id,
            'order_id' => $orderId,
            'type' => 'redeem',
            'points' => -100,
        ]);
    }

    public function test_pending_redeem_with_different_discount_amount_is_rejected(): void
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $this->postJson('/api/frontend/loyalty/redeem', [
            'code' => $this->loyaltyCustomer->loyalty_code,
            'points' => 100,
        ])->assertOk();

        $this->postJson('/api/frontend/order', $this->orderPayload([
            'discount' => 0.50,
            'loyalty_code' => $this->loyaltyCustomer->loyalty_code,
        ]))->assertStatus(422);

        $this->assertSame(400, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertSame(1, LoyaltyTransaction::where('type', 'redeem')->whereNull('order_id')->count());
        $this->assertSame(0, Order::where('loyalty_customer_code', $this->loyaltyCustomer->loyalty_code)->count());
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function orderPayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'subtotal' => 20.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => 20.00,
            'order_type' => OrderType::KIOSK,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'quote_token' => '00000000-0000-4000-8000-000000000001',
            'quote_signature' => str_repeat('a', 64),
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    /**
     * [GOAL-2026-05-29 SEC-P1 LOYALTY-IDOR] A plain guest token carries the
     * `kiosk:order` ability (GuestSignupController mints auth_token with it) but
     * has NO KioskMachine row. It must NOT be treated as a kiosk and MUST be
     * refused when redeeming a FOREIGN loyalty code — zero points debited.
     */
    public function test_guest_with_kiosk_ability_cannot_redeem_foreign_code(): void
    {
        $guest = User::factory()->create([
            'branch_id' => 0,
            'status' => Status::ACTIVE,
            // deliberately NO KioskMachine row for this user
        ]);
        Sanctum::actingAs($guest, ['kiosk:order']);

        $before = (int) $this->loyaltyCustomer->fresh()->loyalty_points;

        $this->postJson('/api/frontend/loyalty/redeem', [
            'code' => $this->loyaltyCustomer->loyalty_code, // FOREIGN code
            'points' => 100,
        ])->assertStatus(403);

        $this->assertSame(
            $before,
            (int) $this->loyaltyCustomer->fresh()->loyalty_points,
            'A guest (kiosk:order ability, no KioskMachine row) must NOT debit another user\'s loyalty points (IDOR).'
        );
    }

    /**
     * [DÉCOUPLAGE FIDÉLITÉ 2026-07-18] Le pre-redeem (POST /api/frontend/loyalty/
     * redeem) débite les points dans sa propre transaction ; le gate DÉDIÉ
     * fidélité (loyalty_enabled) le refuse à la source AVANT tout débit/ledger.
     * loyalty_enabled=false → 422, aucun point débité, aucun ledger.
     */
    public function test_pre_redeem_is_refused_when_loyalty_disabled(): void
    {
        config(['pos.loyalty_enabled' => false]);
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $before = (int) $this->loyaltyCustomer->fresh()->loyalty_points;

        $this->postJson('/api/frontend/loyalty/redeem', [
            'code' => $this->loyaltyCustomer->loyalty_code,
            'points' => 100,
        ])->assertStatus(422);

        // No points debited, no pending ledger written — refused at the source.
        $this->assertSame($before, (int) $this->loyaltyCustomer->fresh()->loyalty_points, 'Refused pre-redeem must not debit points.');
        $this->assertSame(0, LoyaltyTransaction::where('type', 'redeem')->count(), 'Refused pre-redeem must not write a ledger entry.');
    }

    /**
     * [DÉCOUPLAGE FIDÉLITÉ 2026-07-18] Couper les remises DISCRÉTIONNAIRES
     * (manual_discount_enabled=false) ne coupe PLUS le pre-redeem fidélité :
     * loyalty_enabled reste true → le débit s'applique (200). Preuve du découplage.
     */
    public function test_pre_redeem_survives_manual_discount_killswitch(): void
    {
        config(['pos.manual_discount_enabled' => false]);
        config(['pos.loyalty_enabled' => true]);
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $before = (int) $this->loyaltyCustomer->fresh()->loyalty_points;

        $this->postJson('/api/frontend/loyalty/redeem', [
            'code' => $this->loyaltyCustomer->loyalty_code,
            'points' => 100,
        ])->assertStatus(200);

        // La fidélité est active → le pre-redeem débite bien 100 points.
        $this->assertSame($before - 100, (int) $this->loyaltyCustomer->fresh()->loyalty_points, 'Le pre-redeem doit débiter les points quand la fidélité est active.');
        $this->assertSame(1, LoyaltyTransaction::where('type', 'redeem')->count(), 'Un ledger de redeem doit être écrit.');
    }

    private function bypassKioskQuoteSealForLoyaltySentinel(): void
    {
        $this->app->instance(\App\Services\Order\OrderQuoteService::class, new class {
            public function sealForCommit(): \App\Models\OrderQuote
            {
                return new \App\Models\OrderQuote();
            }
        });
    }
}
