<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\DiscountType;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Coupon;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderCoupon;
use App\Enums\Status;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class FrontendDiscountIntegrityTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $orderUser;
    protected User $loyaltyCustomer;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        config(['app.api_key' => '123456']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        Settings::group('loyalty_setup')->set([
            'loyalty_points_for_1_euro_discount' => 100,
            'loyalty_min_redeem_points' => 100,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'Discount Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '20 rue remise',
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Menus',
            'slug' => 'menus',
            'status' => 5,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'Discount Menu',
            'slug' => 'discount-menu',
            'price' => 20.00,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);

        $this->orderUser = User::forceCreate([
            'name' => 'Frontend Order User',
            'email' => 'frontend-discount@test.local',
            'username' => 'frontend_discount',
            'phone' => '0600000010',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            // [Sprint H1 Z6-06 2026-05-17] Was `1`; canonical user-status
            // ACTIVE = 5 (App\Enums\Status). EnsureUserStatusActive rejects
            // any non-ACTIVE user — pre-existing test-fixture data bug.
            'status' => Status::ACTIVE,
        ]);

        $this->loyaltyCustomer = User::forceCreate([
            'name' => 'Loyalty Customer',
            'email' => 'loyalty-customer@test.local',
            'username' => 'loyalty_customer',
            'phone' => '0600000011',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            // [Sprint H1 Z6-06 2026-05-17] Was `1`; see comment above.
            'status' => Status::ACTIVE,
            'loyalty_code' => 'LOYAL100',
            'loyalty_points' => 500,
        ]);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'subtotal' => 20.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => 20.00,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'coupon_id' => null,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }

    public function test_invalid_coupon_id_rejects_frontend_order(): void
    {
        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => 999999,
            ]));

        $response->assertStatus(422);
        $this->assertSame(0, OrderCoupon::count());
    }

    public function test_valid_coupon_is_applied_through_centralized_validation(): void
    {
        // [GOAL-GOLIVE-VAT10] Subject = discount CALC/anti-forgery correctness, not
        // the V1 on/off policy → enable the discretionary-discount master flag so the
        // gated path runs (mirrors PosDiscountTest:46). Production default stays OFF;
        // the OFF behaviour is locked by test_discretionary_discount_disabled_..._v1().
        config(['pos.manual_discount_enabled' => true]);

        $coupon = Coupon::forceCreate([
            'name' => 'FRONT10',
            'description' => '10 percent',
            'code' => 'FRONT10',
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 5,
            'limit_per_user' => 2,
        ]);

        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => $coupon->id,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount' => 2.00,
        ]);

        $this->assertDatabaseHas('order_coupons', [
            'order_id' => $orderId,
            'coupon_id' => $coupon->id,
            'discount' => 2.00,
        ]);
    }

    public function test_forged_frontend_totals_do_not_change_server_coupon_discount(): void
    {
        // [GOAL-GOLIVE-VAT10] Subject = anti-forgery (server ignores client totals),
        // not the V1 on/off policy → enable the master flag so the discount path runs.
        config(['pos.manual_discount_enabled' => true]);

        $coupon = Coupon::forceCreate([
            'name' => 'FRONT10_FORGED',
            'description' => '10 percent',
            'code' => 'FRONT10-FORGED',
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 100,
            'limit_per_user' => 2,
        ]);

        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => $coupon->id,
                'subtotal' => 999.00,
                'total' => 999.00,
                'discount' => 900.00,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'subtotal' => 20.00,
            'discount' => 2.00,
            'total' => 18.00,
        ]);

        $this->assertDatabaseHas('order_coupons', [
            'order_id' => $orderId,
            'coupon_id' => $coupon->id,
            'discount' => 2.00,
        ]);
    }

    public function test_coupon_takes_priority_over_loyalty_discount_on_frontend_order(): void
    {
        // [GOAL-GOLIVE-VAT10] Subject = coupon-over-loyalty priority, not the V1
        // on/off policy → enable the master flag so both discount paths are live.
        config(['pos.manual_discount_enabled' => true]);

        $coupon = Coupon::forceCreate([
            'name' => 'FIXED5',
            'description' => '5 euros',
            'code' => 'FIXED5',
            'discount' => 5,
            'discount_type' => DiscountType::FIXED,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 5,
            'limit_per_user' => 1,
        ]);

        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => $coupon->id,
                'loyalty_code' => $this->loyaltyCustomer->loyalty_code,
                'discount' => 3.00,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount' => 5.00,
        ]);

        $this->assertSame(500, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertFalse((bool) $response->json('loyalty_applied'));
    }

    public function test_loyalty_below_minimum_points_is_not_applied_server_side(): void
    {
        $response = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'loyalty_code' => $this->loyaltyCustomer->loyalty_code,
                'discount' => 0.50,
            ]));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');

        $this->assertDatabaseHas('orders', [
            'id' => $orderId,
            'discount' => 0.00,
        ]);
        $this->assertSame(500, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertFalse((bool) $response->json('loyalty_applied'));
    }

    /**
     * [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Sentinel — discretionary
     * discounts (coupon + kiosk/web loyalty redeem) are OFF by default in V1 on
     * the customer-facing FrontendOrderService path. At a non-zero VAT rate the
     * discount→HT/TVA split in the frozen PricingService/ZReportService is wrong
     * (TVA computed on the PRE-discount base) → a discounted order would sign a
     * fiscally-incorrect NF525 Z (the F1 defect). Loyalty AUTO-accrues, so the
     * loyalty sub-path is reachable with zero admin action. This locks the gate
     * (FrontendOrderService::assertDiscretionaryDiscountAllowed): flag OFF → any
     * non-zero discount is refused (422) for BOTH the coupon and loyalty
     * sub-paths, and the whole order transaction rolls back (no order, no coupon
     * link, no loyalty deduction/ledger — so a refused redeem never burns points).
     */
    public function test_discretionary_discount_disabled_by_default_on_frontend_v1(): void
    {
        // Production V1 default — the flag is OFF unless a test/lock-plan enables it.
        $this->assertNotSame(true, config('pos.manual_discount_enabled'));

        // --- Sub-path A: coupon ---
        $coupon = Coupon::forceCreate([
            'name' => 'GATE10',
            'description' => '10 percent',
            'code' => 'GATE10',
            'discount' => 10,
            'discount_type' => DiscountType::PERCENTAGE,
            'start_date' => now()->subDay(),
            'end_date' => now()->addDay(),
            'minimum_order' => 10,
            'maximum_discount' => 5,
            'limit_per_user' => 2,
        ]);

        $couponResponse = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'coupon_id' => $coupon->id,
            ]));

        // The gate throws a ValidationException, but myOrderStore's catch(Exception)
        // re-wraps it as a generic 422 (the field-error structure is flattened) — so
        // we assert the status + the transaction-rollback effects, matching the
        // canonical ManualDiscountDisabledV1SentinelTest convention (status-only).
        $couponResponse->assertStatus(422);
        $this->assertSame(0, OrderCoupon::count(), 'Refused coupon order must not persist a coupon link.');
        $this->assertSame(0, \App\Models\FrontendOrder::count(), 'Refused coupon order must roll back entirely.');

        // --- Sub-path B: kiosk/web loyalty redeem (auto-accrues → reachable with zero admin action) ---
        // The FrontendOrderService kiosk-loyalty lookup matches status=1 (legacy
        // active), so use a status=1 redeemer holding enough points. 5.00 EUR ==
        // 500 pts (rate 100) >= min 100, balance 500 → the redeem WOULD apply a
        // 5.00 € discount, which the gate must refuse (422) + roll the whole tx back.
        $redeemer = User::forceCreate([
            'name' => 'Loyalty Redeemer',
            'email' => 'loyalty-redeemer@test.local',
            'username' => 'loyalty_redeemer',
            'phone' => '0600000012',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => 1,
            'loyalty_code' => 'LEGACY500',
            'loyalty_points' => 500,
        ]);

        $loyaltyResponse = $this
            ->actingAs($this->orderUser, 'sanctum')
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $this->basePayload([
                'loyalty_code' => $redeemer->loyalty_code,
                'discount' => 5.00,
            ]));

        $loyaltyResponse->assertStatus(422);
        // Transaction rollback proof: no points burned, no ledger, no order.
        $this->assertSame(500, (int) $redeemer->fresh()->loyalty_points, 'Refused loyalty redeem must not burn points.');
        $this->assertSame(0, \App\Models\LoyaltyTransaction::where('type', 'redeem')->count(), 'Refused loyalty redeem must not write a ledger entry.');
        $this->assertSame(0, \App\Models\FrontendOrder::count(), 'Refused loyalty order must roll back entirely.');
    }
}
