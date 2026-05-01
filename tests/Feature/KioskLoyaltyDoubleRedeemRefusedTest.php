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
