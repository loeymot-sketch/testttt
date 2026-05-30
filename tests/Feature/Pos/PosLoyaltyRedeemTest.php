<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 cashier-facing loyalty redeem
 * sentinel — TDD-first per LOCK plan §3 Option B (separate Vue overlay,
 * 0 frozen-zone touch).
 *
 * Endpoint contract:
 *   POST /api/admin/pos-order/{id}/redeem-loyalty
 *   Body: { "points": int, "loyalty_code"?: string }
 *   Auth: sanctum + permission:pos.redeem-loyalty
 *   Anti-fraud (LOCK §6): cashier permission + balance check + single
 *   redemption (UNIQUE user/order/type already in DB) + audit log +
 *   pre-payment-only guard + idempotency middleware (route-level).
 *
 * Sentinel cases:
 *   1. Happy path : cashier + customer balance >= points → 200 + decrement
 *      + ledger row written + order.discount/total recomputed.
 *   2. Insufficient balance → 422 + 0 mutation.
 *   3. Customer not found via loyalty_code → 404 + 0 mutation.
 *   4. Redeem-after-paid (payment_status = PAID) → 409 + 0 mutation.
 */
class PosLoyaltyRedeemTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashier;
    protected User $customer;
    protected Order $order;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // [LOCK §6.1] Cashier permission must exist for the FormRequest authz
        // gate. Auto-seed so tests don't depend on full PermissionTableSeeder
        // run order.
        Permission::firstOrCreate(['name' => 'pos.redeem-loyalty', 'guard_name' => 'sanctum']);

        // Default redemption rate : 100 pts = 1€.
        DB::table('settings')->insert([
            'key' => 'loyalty_points_for_1_euro_discount',
            'payload' => json_encode(100),
            'group' => 'loyalty_setup',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));
        // [GOAL-GOLIVE-VAT10 / F1-dormancy 2026-05-30] Loyalty redeem applies a
        // discount → gated OFF by default in V1 (F1 dormant). This suite tests
        // the redeem mechanics (preserved code) → enable the discretionary-
        // discount flag so the redeem path runs rather than the V1 gate.
        Config::set('pos.manual_discount_enabled', true);

        $this->branch = Branch::factory()->create();

        $this->cashier = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030405',
        ]);
        $this->cashier->assignRole('POS Operator');
        $this->cashier->givePermissionTo('pos.redeem-loyalty');

        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password' => Hash::make('password'),
            'phone' => '0102030406',
        ]);
        DB::table('users')->where('id', $this->customer->id)->update([
            'loyalty_code' => 'TESTCUST',
            'loyalty_points' => 500,
        ]);
        $this->customer->refresh();

        // Base order : 25€ subtotal, no discount, PENDING + UNPAID.
        $this->order = Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'user_id'            => $this->customer->id,
            'subtotal'           => 25.00,
            'discount'           => 0.00,
            'total_tax'          => 0.00,
            'delivery_charge'    => 0.00,
            'total'              => 25.00,
            'status'             => OrderStatus::PENDING,
            'payment_status'     => PaymentStatus::UNPAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'pos',
        ]);
    }

    /** Path 1 — Happy path : 100 pts → 1€ off, ledger row written, balance decremented. */
    public function test_happy_path_decrements_balance_and_writes_ledger(): void
    {
        $this->actingAs($this->cashier, 'sanctum');

        $payload = [
            'points'       => 100,
            'loyalty_code' => 'TESTCUST',
        ];

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-redeem-' . uniqid())
            ->postJson("/api/admin/pos-order/{$this->order->id}/redeem-loyalty", $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('status', true);

        // Customer balance decremented 500 → 400.
        $this->customer->refresh();
        $this->assertSame(400, (int) $this->customer->loyalty_points);

        // Ledger row appended with type=redeem, points=-100, order_id linked.
        $txn = LoyaltyTransaction::where('user_id', $this->customer->id)
            ->where('order_id', $this->order->id)
            ->where('type', 'redeem')
            ->first();
        $this->assertNotNull($txn, 'Redemption ledger row must be written');
        $this->assertSame(-100, (int) $txn->points);
        $this->assertSame(400, (int) $txn->balance_after);
        $this->assertSame('pos', (string) $txn->source_surface);

        // Order updated : discount +1€, total -1€, loyalty_customer_code set.
        $fresh = Order::withoutGlobalScopes()->findOrFail($this->order->id);
        $this->assertEqualsWithDelta(1.00, (float) $fresh->discount, 0.01);
        $this->assertEqualsWithDelta(24.00, (float) $fresh->total, 0.01);
        $this->assertSame('TESTCUST', (string) $fresh->loyalty_customer_code);
    }

    /** Path 2 — Insufficient balance : customer has 50 pts, asks for 200 → 422 + 0 mutation. */
    public function test_insufficient_balance_returns_422_and_rolls_back(): void
    {
        DB::table('users')->where('id', $this->customer->id)->update(['loyalty_points' => 50]);

        $this->actingAs($this->cashier, 'sanctum');

        $balanceBefore = (int) DB::table('users')->where('id', $this->customer->id)->value('loyalty_points');
        $ledgerBefore = LoyaltyTransaction::count();
        $orderTotalBefore = (float) Order::withoutGlobalScopes()->find($this->order->id)->total;

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-redeem-insuff-' . uniqid())
            ->postJson("/api/admin/pos-order/{$this->order->id}/redeem-loyalty", [
                'points'       => 200,
                'loyalty_code' => 'TESTCUST',
            ]);

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'INSUFFICIENT_BALANCE');

        $this->assertSame($balanceBefore, (int) DB::table('users')->where('id', $this->customer->id)->value('loyalty_points'));
        $this->assertSame($ledgerBefore, LoyaltyTransaction::count());
        $this->assertEqualsWithDelta(
            $orderTotalBefore,
            (float) Order::withoutGlobalScopes()->find($this->order->id)->total,
            0.01
        );
    }

    /** Path 3 — Customer not found via loyalty_code → 404 + 0 mutation. */
    public function test_customer_not_found_returns_404(): void
    {
        $this->actingAs($this->cashier, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-redeem-nf-' . uniqid())
            ->postJson("/api/admin/pos-order/{$this->order->id}/redeem-loyalty", [
                'points'       => 100,
                'loyalty_code' => 'NOTHERE0',
            ]);

        $response->assertStatus(404);
        $response->assertJsonPath('code', 'CUSTOMER_NOT_FOUND');

        $this->assertSame(0, LoyaltyTransaction::count());
    }

    /** Path 4 — Redeem on PAID order → 409 + 0 mutation. */
    public function test_redeem_after_paid_returns_409(): void
    {
        Order::withoutGlobalScopes()
            ->where('id', $this->order->id)
            ->update(['payment_status' => PaymentStatus::PAID]);

        $this->actingAs($this->cashier, 'sanctum');

        $balanceBefore = (int) DB::table('users')->where('id', $this->customer->id)->value('loyalty_points');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-redeem-paid-' . uniqid())
            ->postJson("/api/admin/pos-order/{$this->order->id}/redeem-loyalty", [
                'points'       => 100,
                'loyalty_code' => 'TESTCUST',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('code', 'ORDER_ALREADY_FINALIZED');

        $this->assertSame($balanceBefore, (int) DB::table('users')->where('id', $this->customer->id)->value('loyalty_points'));
        $this->assertSame(0, LoyaltyTransaction::where('type', 'redeem')->count());
    }

    /** Path 5 — Cashier without `pos.redeem-loyalty` permission → 403. */
    public function test_cashier_without_permission_returns_403(): void
    {
        $this->cashier->revokePermissionTo('pos.redeem-loyalty');
        $this->actingAs($this->cashier, 'sanctum');

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-redeem-noperm-' . uniqid())
            ->postJson("/api/admin/pos-order/{$this->order->id}/redeem-loyalty", [
                'points'       => 100,
                'loyalty_code' => 'TESTCUST',
            ]);

        $response->assertStatus(403);
        $this->assertSame(0, LoyaltyTransaction::where('type', 'redeem')->count());
    }

    /** Path 6 — Single redemption per order : second attempt → 409. */
    public function test_double_redemption_rejected(): void
    {
        $this->actingAs($this->cashier, 'sanctum');

        $first = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-redeem-1st-' . uniqid())
            ->postJson("/api/admin/pos-order/{$this->order->id}/redeem-loyalty", [
                'points'       => 100,
                'loyalty_code' => 'TESTCUST',
            ]);
        $first->assertStatus(200);

        $second = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-redeem-2nd-' . uniqid())
            ->postJson("/api/admin/pos-order/{$this->order->id}/redeem-loyalty", [
                'points'       => 100,
                'loyalty_code' => 'TESTCUST',
            ]);
        $second->assertStatus(409);
        $second->assertJsonPath('code', 'ALREADY_REDEEMED');

        // Only 1 redeem row (UNIQUE(user_id, order_id, type) enforced at DB level).
        $this->assertSame(
            1,
            LoyaltyTransaction::where('user_id', $this->customer->id)
                ->where('order_id', $this->order->id)
                ->where('type', 'redeem')
                ->count()
        );
    }

    /**
     * Path 7 — [HEAL-A.1 2026-05-19] Cross-branch cashier MUST get 403 on foreign order.
     *
     * Z6+Z8 cross-confirmed P0: Spatie permission `pos.redeem-loyalty` is global per-user,
     * NOT branch-bound. Without the post-fetch branch check (mirror of PosOrderController:113-121),
     * a cashier on Branch A could redeem against an order on Branch B because the controller
     * bypassed BranchScope via `Order::withoutGlobalScopes()->find()`.
     *
     * This sentinel proves the fix by:
     *  - Creating a foreign branch + foreign order (different branch_id from the cashier).
     *  - Acting as the cashier (same-branch as $this->branch, has permission).
     *  - POST to /api/admin/pos-order/{foreignOrder->id}/redeem-loyalty.
     *  - Asserts 403 + zero loyalty mutation (no ledger row, no order mutation).
     */
    public function test_cross_branch_cashier_gets_403_on_foreign_order(): void
    {
        // Foreign branch + foreign order (different branch_id).
        $otherBranch = Branch::factory()->create();
        $otherCustomer = User::factory()->create([
            'branch_id' => $otherBranch->id,
            'password'  => Hash::make('password'),
            'phone'     => '0809010203',
        ]);
        DB::table('users')->where('id', $otherCustomer->id)->update([
            'loyalty_code'   => 'OTHERCUST',
            'loyalty_points' => 500,
        ]);

        $foreignOrder = Order::factory()->create([
            'branch_id'          => $otherBranch->id,
            'user_id'            => $otherCustomer->id,
            'subtotal'           => 25.00,
            'discount'           => 0.00,
            'total_tax'          => 0.00,
            'delivery_charge'    => 0.00,
            'total'              => 25.00,
            'status'             => OrderStatus::PENDING,
            'payment_status'     => PaymentStatus::UNPAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'order_type'         => OrderType::TAKEAWAY,
            'source_surface'     => 'pos',
        ]);

        // Cashier is on $this->branch — DIFFERENT from $otherBranch — has the permission.
        $this->assertNotSame($this->cashier->branch_id, $foreignOrder->branch_id);
        $this->assertTrue((bool) $this->cashier->can('pos.redeem-loyalty'));

        $this->actingAs($this->cashier, 'sanctum');

        $balanceBefore = (int) DB::table('users')->where('id', $otherCustomer->id)->value('loyalty_points');
        $ledgerBefore = LoyaltyTransaction::count();
        $foreignOrderBefore = Order::withoutGlobalScopes()->findOrFail($foreignOrder->id);

        $response = $this->withHeader('x-api-key', config('app.api_key'))
            ->withHeader('X-Idempotency-Key', 'test-redeem-xbranch-' . uniqid())
            ->postJson("/api/admin/pos-order/{$foreignOrder->id}/redeem-loyalty", [
                'points'       => 100,
                'loyalty_code' => 'OTHERCUST',
            ]);

        // Must be 403 cross-branch denied (NOT 200, NOT 404, NOT 422).
        $response->assertStatus(403);

        // Zero loyalty mutation: balance unchanged, no ledger row written,
        // foreign order unchanged (no loyalty_customer_code, no discount).
        $this->assertSame(
            $balanceBefore,
            (int) DB::table('users')->where('id', $otherCustomer->id)->value('loyalty_points'),
            'Foreign customer balance must NOT be decremented on cross-branch attempt'
        );
        $this->assertSame(
            $ledgerBefore,
            LoyaltyTransaction::count(),
            'No ledger row may be written on cross-branch attempt'
        );

        $foreignOrderAfter = Order::withoutGlobalScopes()->findOrFail($foreignOrder->id);
        $this->assertNull(
            $foreignOrderAfter->loyalty_customer_code,
            'Foreign order loyalty_customer_code must remain null'
        );
        $this->assertEqualsWithDelta(
            (float) $foreignOrderBefore->discount,
            (float) $foreignOrderAfter->discount,
            0.01,
            'Foreign order discount must not change'
        );
        $this->assertEqualsWithDelta(
            (float) $foreignOrderBefore->total,
            (float) $foreignOrderAfter->total,
            0.01,
            'Foreign order total must not change'
        );
    }
}
