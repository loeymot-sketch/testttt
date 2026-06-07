<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * [CENTRAL-02 2026-06-07] Real-path end-to-end proof of the caisse loyalty accrual.
 *
 * WHY THIS EXISTS: the sibling PosCustomerActiveStatus5LoyaltyTest verifies accrual
 * only with STUBBED wiring — it hand-builds the Order with loyalty_customer_code
 * already set and calls the listener directly. That proves the listener works, but
 * NOT the two things that actually break in production:
 *   (1) the customer_id -> loyalty_customer_code DERIVATION inside
 *       OrderService::store (app/Services/OrderService.php:1090-1097), and
 *   (2) the real DELIVERED -> OrderStatusChanged dispatch -> AwardLoyaltyPointsOnDelivery
 *       chain driven through the real change-status endpoint.
 *
 * This test drives BOTH through the real HTTP surfaces:
 *   (a) mints a loyalty_code for a status=5 caisse customer via the REAL
 *       /api/frontend/loyalty/balance lookup (no hand-set code),
 *   (b) creates the order via the REAL /api/admin/pos endpoint passing ONLY
 *       customer_id (NO loyalty_customer_code in the payload) — forcing the
 *       server-side derivation,
 *   (c) asserts order.loyalty_customer_code === customer.loyalty_code (derivation proof),
 *   (d) walks the order PENDING -> ACCEPT -> DELIVERED via the REAL
 *       /api/admin/pos-order/change-status endpoint (fires OrderStatusChanged),
 *   (e) asserts an immutable `earn` loyalty_transactions row + a balance increase.
 *
 * Anti-coupling: points-per-euro is owner-configurable (G1, default 10). We assert
 * points > 0 and an earn row exists, NOT a specific number.
 *
 * Anti-stub sanity: a second test confirms accrual is DERIVATION-DEPENDENT — a
 * customer with NO loyalty_code yields NO earn row through the identical real path.
 */
class PosLoyaltyAccrualRealPathTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $operator;
    protected User $customer;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create();

        // Cashier (authenticated session for both loyalty.auth + POS endpoints).
        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password'),
            'phone'     => '0102030410',
        ]);
        $this->operator->assignRole('POS Operator');

        // POS-created walk-in customer EXACTLY as the caisse persists them:
        // status = Status::ACTIVE (5), a phone, and NO loyalty_code yet.
        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password'),
            'phone'     => '0601020310',
            'status'    => Status::ACTIVE,
        ]);
        \Illuminate\Support\Facades\DB::table('users')->where('id', $this->customer->id)->update([
            'loyalty_code'   => null,
            'loyalty_points' => 0,
            'status'         => (int) Status::ACTIVE,
        ]);
        $this->customer->refresh();

        $tax = Tax::factory()->create([
            'name'     => 'TVA 0%',
            'code'     => 'TVA0',
            'type'     => TaxType::PERCENTAGE,
            'tax_rate' => 0.00,
            'status'   => Status::ACTIVE,
        ]);

        $cat = ItemCategory::factory()->create([
            'name'            => 'Cat',
            'wizard_template' => 'tacos',
            'has_menu'        => true,
        ]);

        $this->item = Item::factory()->create([
            'item_category_id' => $cat->id,
            'tax_id'           => $tax->id,
            'name'             => 'Item',
            'price'            => 20.00,
            'status'           => Status::ACTIVE,
        ]);
    }

    private function openSessionForOperator(float $opening = 100.00): void
    {
        app(CashDrawerService::class)->openSession($this->branch->id, $this->operator->id, $opening);
    }

    /** Mint a loyalty_code for the status=5 customer via the REAL caisse lookup path. */
    private function mintLoyaltyCodeViaRealBalanceLookup(): string
    {
        $this->actingAs($this->operator, 'sanctum');
        $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/frontend/loyalty/balance?code=' . urlencode($this->customer->phone))
            ->assertStatus(200);

        $this->customer->refresh();
        $this->assertNotNull($this->customer->loyalty_code, 'precondition: balance lookup must mint a loyalty_code');

        return (string) $this->customer->loyalty_code;
    }

    /** Create a POS order via the REAL endpoint, passing ONLY customer_id. */
    private function createPosOrderForCustomer(int $customerId): array
    {
        $payload = [
            'token'              => null,
            'customer_id'        => $customerId,
            'branch_id'          => $this->branch->id,
            'discount'           => 0,
            'order_type'         => OrderType::TAKEAWAY,
            'is_advance_order'   => 0,
            'source'             => 1,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'pos_received_amount'=> 20,
            // NOTE: NO 'loyalty_customer_code' — the server MUST derive it from customer_id.
            'items' => json_encode([
                [
                    'item_id'         => $this->item->id,
                    'item_price'      => $this->item->price,
                    'quantity'        => 1,
                    'total_price'     => 20.00,
                    'item_variations' => [],
                    'item_extras'     => [],
                ],
            ]),
        ];

        $response = $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($this->operator, $payload));

        $response->assertStatus(201);
        $orderId = $response->json('data.id');
        $this->assertNotNull($orderId, 'POS order must be created');

        return [$orderId, $response];
    }

    /** Transition through the REAL change-status endpoint with a fresh idempotency key. */
    private function changeStatus(int $orderId, int $status): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->operator, 'sanctum')
            ->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'central02-' . $orderId . '-' . $status . '-' . uniqid())
            ->postJson('/api/admin/pos-order/change-status/' . $orderId, [
                'status' => $status,
            ]);
    }

    /**
     * Walk an order to DELIVERED via the REAL change-status endpoint, honoring the
     * OrderStateMachine legal edges. A paid POS CASH order is persisted at
     * PREPARING (7); for a `pos`-permission user PREPARING -> DELIVERED is a single
     * legal edge. We branch on the actual persisted status so the test is robust to
     * the POS auto-advance rather than assuming PENDING.
     */
    private function driveToDelivered(int $orderId): void
    {
        $status = (int) Order::withoutGlobalScopes()->findOrFail($orderId)->status;

        if ($status === OrderStatus::PENDING) {
            $this->changeStatus($orderId, OrderStatus::ACCEPT)->assertStatus(200);
            $status = OrderStatus::ACCEPT;
        }

        // From ACCEPT or PREPARING a `pos` user may jump straight to DELIVERED.
        $this->changeStatus($orderId, OrderStatus::DELIVERED)->assertStatus(200);

        $this->assertSame(
            OrderStatus::DELIVERED,
            (int) Order::withoutGlobalScopes()->findOrFail($orderId)->status,
            'Order must reach DELIVERED through the real change-status endpoint.'
        );
    }

    /**
     * THE REAL-PATH PROOF: derivation + dispatch + accrual end-to-end.
     */
    public function test_real_pos_path_derives_loyalty_code_and_accrues_earn_row_on_delivery(): void
    {
        $code = $this->mintLoyaltyCodeViaRealBalanceLookup();
        $this->openSessionForOperator(100.00);

        [$orderId] = $this->createPosOrderForCustomer($this->customer->id);

        // (c) DERIVATION PROOF (load-bearing) — the server populated
        // loyalty_customer_code from customer_id alone (we never put it in the
        // payload). THIS assertion is what proves the OrderService::store
        // derivation; the earn row below does NOT prove it on its own because
        // AwardLoyaltyPointsOnDelivery has a user_id fallback
        // (app/Listeners/AwardLoyaltyPointsOnDelivery.php:69-74) that would still
        // resolve the customer via order->user_id even if the derivation were
        // broken. The earn row proves the dispatch->accrual chain; this line
        // proves the derivation.
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->assertSame(
            $code,
            (string) $order->loyalty_customer_code,
            'OrderService::store must DERIVE loyalty_customer_code from customer_id (not hand-set).'
        );
        $this->assertNull($order->loyalty_points_awarded, 'precondition: not yet awarded');

        // (d) Drive DELIVERED through the REAL change-status endpoint. A paid POS
        // CASH order is persisted at PREPARING (7); OrderStateMachine allows
        // PREPARING -> DELIVERED directly for a `pos`-permission user
        // (app/Domain/Order/OrderStateMachine.php:55). changeStatus dispatches the
        // real OrderStatusChanged (NOT faked); the listener accrues exactly-once
        // via the loyalty_points_awarded sentinel.
        $this->driveToDelivered($orderId);

        // (e) THE CONSEQUENCE — an immutable `earn` ledger row exists for this order.
        $earn = LoyaltyTransaction::where('user_id', $this->customer->id)
            ->where('order_id', $orderId)
            ->where('type', 'earn')
            ->first();

        $this->assertNotNull(
            $earn,
            'Real path: an `earn` loyalty_transactions row must be written once the order reaches DELIVERED.'
        );
        $this->assertGreaterThan(0, (int) $earn->points, 'Points accrued must be > 0 (rate is owner-configurable).');
        $this->assertSame($code, (string) $earn->loyalty_code, 'earn row must carry the customer loyalty_code.');

        // Balance increased on the customer record.
        $this->customer->refresh();
        $this->assertGreaterThan(0, (int) $this->customer->loyalty_points, 'Customer balance must increase.');

        // Order sentinel finalized with the awarded amount (no -1, no null).
        $order->refresh();
        $this->assertGreaterThan(0, (int) $order->loyalty_points_awarded, 'order.loyalty_points_awarded must be finalized.');
    }

    /**
     * ANTI-STUB SANITY: accrual is DERIVATION-DEPENDENT. A customer with NO
     * loyalty_code, driven through the IDENTICAL real path, yields NO earn row —
     * proving the earn row above came from the real derivation, not magic.
     */
    public function test_real_pos_path_without_loyalty_code_writes_no_earn_row(): void
    {
        // NO mint — customer has no loyalty_code.
        $this->assertNull($this->customer->loyalty_code, 'precondition: no loyalty_code');

        $this->openSessionForOperator(100.00);
        [$orderId] = $this->createPosOrderForCustomer($this->customer->id);

        $order = Order::withoutGlobalScopes()->findOrFail($orderId);
        $this->assertNull(
            $order->loyalty_customer_code,
            'No loyalty_code on the customer => store derives a NULL loyalty_customer_code.'
        );

        $this->driveToDelivered($orderId);

        $earn = LoyaltyTransaction::where('order_id', $orderId)->where('type', 'earn')->first();
        $this->assertNull($earn, 'No loyalty_code => no earn row (accrual is derivation-dependent, not a stub).');
    }
}
