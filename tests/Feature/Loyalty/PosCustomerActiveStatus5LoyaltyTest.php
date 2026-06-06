<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Events\OrderStatusChanged;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Models\Branch;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [SUPERVISOR-AUDIT 2026-06-06] Owner V1 loyalty = phone-keyed, managed at the
 * caisse, no card, not on receipt, optional. The caisse enrolls/accrues via the
 * balance-lookup auto-mint path (POS dropdown → frontend/loyalty/balance?code=phone
 * → check() lazily mints loyalty_code when absent).
 *
 * CONFIRMED P1 (adversarially verified): check()/balance() gated the lazy-mint on
 * `status == 1`, but the POS add-customer form persists status = Status::ACTIVE (5).
 * 5 != 1 → 404 → mint never fires → null loyalty_customer_code → accrual skipped:
 * the owner's caisse-keyed loyalty was functionally DEAD for POS-created customers.
 * Sibling sites (scan()::isCustomerActive :884-888, PosRedemptionService :114-128)
 * already accept 1 OR 5; only check()/balance() was never aligned. Tests stayed
 * green because every prior LoyaltyApiTest seeds status=1.
 *
 * This test asserts the CONSEQUENCE (an `earn` ledger row), not just the predicate.
 * Points-per-euro RATE is owner-configurable (G1, default 10) → we assert points>0
 * and an earn row exists, NOT a specific number (no coupling to the ratio gate).
 */
class PosCustomerActiveStatus5LoyaltyTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $operator;
    protected User $customer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        $this->branch = Branch::factory()->create();

        // Authenticated session for the auth:sanctum loyalty.auth group (the cashier).
        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password'),
            'phone'     => '0102030405',
        ]);
        $this->operator->assignRole('POS Operator');

        // A POS-created walk-in customer EXACTLY as the caisse persists them:
        // status = Status::ACTIVE (5), a phone, and NO loyalty_code yet.
        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('password'),
            'phone'     => '0601020304',
            'status'    => Status::ACTIVE, // 5 — the value the POS add-customer form submits
        ]);
        DB::table('users')->where('id', $this->customer->id)->update([
            'loyalty_code'   => null,
            'loyalty_points' => 0,
            'status'         => (int) Status::ACTIVE, // force-persist 5 (factory may default elsewhere)
        ]);
        $this->customer->refresh();
        $this->assertSame((int) Status::ACTIVE, (int) $this->customer->status, 'precondition: customer is status=5');
        $this->assertNull($this->customer->loyalty_code, 'precondition: no loyalty_code yet');
    }

    /**
     * THE FIX (gate): a status=5 POS customer looked up by phone must mint a
     * loyalty_code and return 200 — was 404 + no mint before the isCustomerActive() fix.
     */
    public function test_status5_pos_customer_balance_lookup_mints_loyalty_code(): void
    {
        $this->actingAs($this->operator, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/frontend/loyalty/balance?code=' . urlencode($this->customer->phone));

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        $this->customer->refresh();
        $this->assertNotNull(
            $this->customer->loyalty_code,
            'A status=5 (ACTIVE) caisse customer must get a loyalty_code minted on balance lookup'
        );
        $response->assertJsonPath('data.loyalty_code', $this->customer->loyalty_code);
    }

    /**
     * THE CONSEQUENCE: once minted, a POS order carrying loyalty_customer_code
     * accrues — an immutable `earn` loyalty_transactions row is written on DELIVERED.
     */
    public function test_status5_pos_customer_accrues_earn_row_on_delivery(): void
    {
        // Mint the code via the same caisse path.
        $this->actingAs($this->operator, 'sanctum');
        $this->withHeader('x-api-key', config('app.api_key'))
            ->getJson('/api/frontend/loyalty/balance?code=' . urlencode($this->customer->phone))
            ->assertStatus(200);
        $this->customer->refresh();
        $code = $this->customer->loyalty_code;
        $this->assertNotNull($code);

        // A POS order attached to that loyalty_customer_code, not yet awarded.
        $order = Order::factory()->create([
            'branch_id'              => $this->branch->id,
            'user_id'                => $this->customer->id,
            'subtotal'               => 20.00,
            'discount'               => 0.00,
            'total_tax'              => 0.00,
            'delivery_charge'        => 0.00,
            'total'                  => 20.00,
            'status'                 => OrderStatus::PENDING,
            'payment_status'         => PaymentStatus::PAID,
            'payment_method'         => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method'     => PosPaymentMethod::CASH,
            'order_type'             => OrderType::TAKEAWAY,
            'source_surface'         => 'pos',
            'loyalty_customer_code'  => $code,
            'loyalty_points_awarded' => null,
        ]);

        // Drive the accrual listener directly (isolates from broadcast/outbox).
        (new AwardLoyaltyPointsOnDelivery())->handle(
            new OrderStatusChanged($order, OrderStatus::PENDING, OrderStatus::DELIVERED)
        );

        $earn = LoyaltyTransaction::where('user_id', $this->customer->id)
            ->where('order_id', $order->id)
            ->where('type', 'earn')
            ->first();

        $this->assertNotNull($earn, 'An earn ledger row must be written for the status=5 caisse customer');
        $this->assertGreaterThan(0, (int) $earn->points, 'Points accrued must be > 0');

        $this->customer->refresh();
        $this->assertGreaterThan(0, (int) $this->customer->loyalty_points, 'Customer balance must increase');
    }
}
