<?php

namespace Tests\Feature;

use App\Enums\Ask;
use App\Enums\EventType;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\LoyaltyBalanceChanged;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\KioskMachine;
use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * REFUTER #2 repro for finding F3-02 (loyalty-validation-2026-06-12).
 *
 * Claim under test: FrontendOrderService::applyKioskLoyaltyDiscount fallback
 * branch (no pendingRedeem, :899 inline decrement + :906 ledger) never
 * dispatches LoyaltyBalanceChanged -> no domain_events outbox row -> L2 live
 * balance push misses this mutation.
 *
 * Setup mirrors tests/Feature/KioskLoyaltyLedgerAtomicTest.php (the canonical
 * suite test for this exact path). NOT part of the repo suite (lives in
 * reports/, run by explicit path).
 */
class F302RefuterRepro2Test extends TestCase
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
            'name' => 'F302 Repro',
            'slug' => 'f302-repro',
            'status' => Status::ACTIVE,
        ]);
        $this->item = Item::forceCreate([
            'name' => 'F302 Menu',
            'slug' => 'f302-menu',
            'price' => 20,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
        ]);
        $this->loyaltyCustomer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => 1,
            'loyalty_code' => 'F302REPRO100',
            'loyalty_points' => 500,
        ]);
    }

    /**
     * Finding repro: fallback inline decrement happens (points 500->400,
     * 1 redeem ledger row with the fallback description) but ZERO
     * loyalty.balance_changed outbox row is persisted.
     */
    public function test_fallback_kiosk_redeem_mutates_points_without_balance_changed_outbox_row(): void
    {
        Bus::fake();
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);

        // Precondition: NO pending redeem -> forces the :899 fallback branch.
        $this->assertSame(0, LoyaltyTransaction::count());
        $this->assertSame(0, DomainEvent::query()
            ->where('event_type', EventType::LOYALTY_BALANCE_CHANGED)->count());

        $response = $this->postJson('/api/frontend/order', $this->orderPayload());
        $response->assertCreated();

        // The mutation really happened via the fallback branch:
        $this->assertSame(400, (int) $this->loyaltyCustomer->fresh()->loyalty_points);
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $this->loyaltyCustomer->id,
            'type' => 'redeem',
            'points' => -100,
            'balance_after' => 400,
            'description' => 'Reduction fidelite appliquee sur commande kiosk',
        ]);

        // FINDING ASSERTION: no LoyaltyBalanceChanged outbox row was produced.
        $rows = DomainEvent::query()
            ->where('event_type', EventType::LOYALTY_BALANCE_CHANGED)->count();
        $this->assertSame(
            0,
            $rows,
            'F3-02 REFUTED if this fails: an outbox row WAS produced by the fallback path.'
        );
    }

    /**
     * Positive control: proves the detection method has no false negative.
     * A LoyaltyBalanceChanged dispatched INSIDE a DB transaction (same shape
     * as the order TX at FrontendOrderService:485) does persist an outbox row
     * under this exact test harness.
     */
    public function test_positive_control_dispatch_inside_transaction_persists_outbox_row(): void
    {
        Bus::fake();

        DB::transaction(function (): void {
            LoyaltyBalanceChanged::dispatch(
                $this->loyaltyCustomer->id,
                (int) $this->branch->id,
                400,
                -100,
                'redeem'
            );
        });

        $this->assertSame(1, DomainEvent::query()
            ->where('event_type', EventType::LOYALTY_BALANCE_CHANGED)->count());
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
