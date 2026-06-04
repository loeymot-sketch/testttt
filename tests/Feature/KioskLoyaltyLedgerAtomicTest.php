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
use RuntimeException;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class KioskLoyaltyLedgerAtomicTest extends TestCase
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

        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] These tests exercise the kiosk
        // loyalty-redeem MECHANICS (point decrement, ledger atomicity, idempotency) —
        // not the V1 on/off policy. Enable the discretionary-discount master flag so
        // the redeem path runs; the OFF default is locked by FrontendDiscountIntegrityTest
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
            'name' => 'Loyalty Atomic',
            'slug' => 'loyalty-atomic',
            'status' => Status::ACTIVE,
        ]);
        $this->item = Item::forceCreate([
            'name' => 'Atomic Menu',
            'slug' => 'atomic-menu',
            'price' => 20,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);
        $this->loyaltyCustomer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 1,
            'loyalty_code' => 'LOYALATOMIC100',
            'loyalty_points' => 500,
        ]);
    }

    public function test_ledger_failure_rolls_back_points_and_order(): void
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        LoyaltyTransaction::creating(function (): void {
            throw new RuntimeException('ledger write failed');
        });

        try {
            $response = $this->postJson('/api/frontend/order', $this->orderPayload());
        } finally {
            LoyaltyTransaction::flushEventListeners();
        }

        $response->assertStatus(422);
        $this->assertSame(500, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertSame(0, LoyaltyTransaction::count());
        $this->assertSame(0, Order::where('loyalty_customer_code', $this->loyaltyCustomer->loyalty_code)->count());
    }

    public function test_nominal_redeem_decrements_points_and_writes_one_ledger_entry(): void
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $response = $this->postJson('/api/frontend/order', $this->orderPayload());

        $response->assertCreated();
        $orderId = (int) $response->json('data.id');

        $this->assertSame(400, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertSame(1, LoyaltyTransaction::where('type', 'redeem')->count());
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $this->loyaltyCustomer->id,
            'order_id' => $orderId,
            'type' => 'redeem',
            'points' => -100,
            'balance_after' => 400,
        ]);
    }

    public function test_duplicate_ledger_insert_recovers_existing_entry_without_second_entry(): void
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        $insertedDuplicate = false;
        LoyaltyTransaction::creating(function (LoyaltyTransaction $transaction) use (&$insertedDuplicate): void {
            if ($insertedDuplicate) {
                return;
            }

            $insertedDuplicate = true;
            LoyaltyTransaction::withoutEvents(function () use ($transaction): void {
                LoyaltyTransaction::create($transaction->getAttributes());
            });
        });

        try {
            $response = $this->postJson('/api/frontend/order', $this->orderPayload());
        } finally {
            LoyaltyTransaction::flushEventListeners();
        }

        $response->assertCreated();
        $this->assertSame(400, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertSame(1, LoyaltyTransaction::where('type', 'redeem')->count());
    }

    /**
     * @return array<string, mixed>
     */
    private function orderPayload(): array
    {
        return [
            'branch_id' => $this->branch->id,
            'subtotal' => 20.00,
            'discount' => 1.00,
            'delivery_charge' => 0,
            'total' => 19.00,
            'order_type' => OrderType::KIOSK,
            'is_advance_order' => Ask::NO,
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'loyalty_code' => $this->loyaltyCustomer->loyalty_code,
            'quote_token' => '00000000-0000-4000-8000-000000000002',
            'quote_signature' => str_repeat('b', 64),
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];
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
