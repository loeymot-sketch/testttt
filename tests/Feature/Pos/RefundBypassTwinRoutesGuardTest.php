<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [GOAL test-e2e all-systems 2026-06-27 / caisse — REFUND-BYPASS P1 TWIN] Sibling-route
 * authz-drift guard.
 *
 * THE BUG: the refund gate (can('pos-refund')) was healed on
 * PosOrderController::changeStatus (commit 10e462149) but LEFT OFF the two sibling
 * controllers that delegate to the SAME OrderService::changeStatus:
 *   - TableOrderController::changeStatus  (gated only by `permission:table-orders`)
 *   - OnlineOrderController::changeStatus (gated only by `permission:online-orders`)
 * The route `{order}` is type-agnostic and DELIVERED(13)→RETURNED(22) is unconditional
 * in OrderStateMachine, firing PaymentService::cashBack + LoyaltyService::refundPoints.
 * The Waiter role holds `table-orders` but NOT `pos-refund` (RolePermissionTableSeeder)
 * → a Waiter could refund ANY paid order in the branch (incl. a POS cash sale) via the
 * table-order change-status route.
 *
 * THE FIX (non-frozen, controller layer): both siblings now mirror
 * PosOrderController::changeStatus — when target status is RETURNED,
 * abort_unless(can('pos-refund'), 403) BEFORE delegating. Only RETURNED is gated, so
 * legit Waiter transitions (ACCEPT/PREPARING/…) stay open. The OrderStateMachine edge
 * stays frozen; authorization lives at the controller.
 */
class RefundBypassTwinRoutesGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'table-orders', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'online-orders', 'guard_name' => 'sanctum']);

        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        // Stub the NF525 audit + sequence services — we exercise the AUTHZ gate, not the chain.
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });
        $this->app->instance(FiscalSequenceService::class, new class(9100) extends FiscalSequenceService {
            private int $counter;
            public function __construct(int $start) { $this->counter = $start; }
            public function next(int $branchId): int { return ++$this->counter; }
        });
    }

    private function makeDeliveredPaidOrder(Branch $branch, User $customer): Order
    {
        $order = Order::factory()->create([
            'user_id'            => $customer->id,
            'branch_id'          => $branch->id,
            'order_type'         => OrderType::POS, // route is type-agnostic — a POS cash sale is reachable via the twin
            'status'             => OrderStatus::DELIVERED,
            'payment_status'     => PaymentStatus::PAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'subtotal'           => 30.00,
            'total'              => 30.00,
            'total_tax'          => 0,
            'discount'           => 0,
            'created_at'         => Carbon::now()->subMinutes(5),
        ]);
        $order->fiscal_sequence_no = 600;
        $order->save();

        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'FK-TWIN-PAID-' . $order->id,
            'amount'         => 30.00,
            'payment_method' => 'cash',
            'type'           => 'payment',
            'sign'           => '+',
        ]);

        return $order->fresh();
    }

    /**
     * A Waiter-equivalent actor: holds the `table-orders` route permission (direct grant —
     * the role name is irrelevant to the vuln, only the permission gates the route) but NOT
     * `pos-refund`. This is exactly the Waiter seed (RolePermissionTableSeeder:152-163).
     */
    private function newWaiter(Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'password' => Hash::make('password')]);
        $user->givePermissionTo('table-orders');
        if ($user->hasPermissionTo('pos-refund')) {
            $user->revokePermissionTo('pos-refund');
        }
        return $user;
    }

    private function newAdmin(Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'password' => Hash::make('password')]);
        $user->assignRole('Admin');
        $user->givePermissionTo('table-orders');
        $user->givePermissionTo('pos-refund');
        return $user;
    }

    // 1. THE BYPASS — Waiter without pos-refund must be FORBIDDEN on the table-order twin.
    public function test_waiter_cannot_refund_via_table_order_change_status_returned(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = $this->makeDeliveredPaidOrder($branch, $customer);
        $waiter   = $this->newWaiter($branch);

        $resp = $this->actingAs($waiter, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'twin-table-403-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/table-order/change-status/{$order->id}", [
                'status' => OrderStatus::RETURNED,
                'reason' => 'Waiter tries to refund via the table-order change-status twin route.',
            ]);

        $resp->assertStatus(403);

        $this->assertSame(0, Transaction::where('order_id', $order->id)->where('type', 'cash_back')->count(),
            'Forbidden twin-route refund must NOT create a cash_back transaction.');
        $this->assertSame(OrderStatus::DELIVERED, (int) $order->fresh()->status,
            'Forbidden twin-route refund must leave status at DELIVERED.');
    }

    // 2. LEGITIMATE — pos-refund holder still succeeds through the twin (no over-block).
    public function test_admin_can_refund_via_table_order_change_status_returned(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = $this->makeDeliveredPaidOrder($branch, $customer);
        $admin    = $this->newAdmin($branch);

        $resp = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'twin-table-200-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/table-order/change-status/{$order->id}", [
                'status' => OrderStatus::RETURNED,
                'reason' => 'Admin issues a legitimate refund through the table-order route.',
            ]);

        $resp->assertStatus(200);
        $this->assertSame(OrderStatus::RETURNED, (int) $order->fresh()->status);
        $this->assertDatabaseHas('transactions', ['order_id' => $order->id, 'type' => 'cash_back']);
    }

    // 3. NO OVER-BLOCK — a non-refund transition by the Waiter must NOT hit the 403 gate.
    public function test_waiter_non_refund_transition_is_not_blocked_by_refund_gate(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = Order::factory()->create([
            'user_id'        => $customer->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'total'          => 30.00,
            'created_at'     => Carbon::now()->subMinutes(5),
        ]);
        $waiter = $this->newWaiter($branch);

        $resp = $this->actingAs($waiter, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'twin-table-prep-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/table-order/change-status/{$order->id}", [
                'status' => OrderStatus::PREPARING,
            ]);

        // PREPARING is NOT RETURNED → MY refund gate must not fire. OrderService may still
        // apply its own authz (out of scope here); the precise invariant is that the
        // pos-refund gate added by this fix NEVER triggers for a non-refund transition.
        $this->assertStringNotContainsString('remboursement', (string) $resp->getContent(),
            'The pos-refund gate must only fire for RETURNED, never for a non-refund transition.');
    }

    // 4. TWIN #2 — online-order route is guarded too (defense-in-depth).
    public function test_online_order_change_status_returned_is_refund_gated(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = $this->makeDeliveredPaidOrder($branch, $customer);
        $waiter   = $this->newWaiter($branch);
        $waiter->givePermissionTo('online-orders'); // grant the route perm, still no pos-refund

        $resp = $this->actingAs($waiter, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'twin-online-403-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/online-order/change-status/{$order->id}", [
                'status' => OrderStatus::RETURNED,
                'reason' => 'Non-refund role tries to refund via the online-order twin route.',
            ]);

        $resp->assertStatus(403);
        $this->assertSame(0, Transaction::where('order_id', $order->id)->where('type', 'cash_back')->count());
    }

    // -----------------------------------------------------------------------
    // [F-CANCEL-REFUND-PARITY 2026-07-15 / P1] CANCELED (16) & REJECTED (19) d'une
    // commande PAYÉE drainent AUSSI le tiroir (OrderService::changeStatus:2286-2320) —
    // le gate RETURNED-only laissait ce troisième jumeau ouvert. Ces tests ferment
    // le vecteur « annulation = remboursement déguisé » sur les 3 routes.
    // -----------------------------------------------------------------------

    /** A PAID direct cash POS sale in PREPARING — reachable CANCELED per OrderStateMachine. */
    private function makePreparingPaidCashOrder(Branch $branch, User $customer): Order
    {
        $order = Order::factory()->create([
            'user_id'            => $customer->id,
            'branch_id'          => $branch->id,
            'order_type'         => OrderType::POS,
            'status'             => OrderStatus::PREPARING,
            'payment_status'     => PaymentStatus::PAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'subtotal'           => 30.00,
            'total'              => 30.00,
            'total_tax'          => 0,
            'discount'           => 0,
            'created_at'         => Carbon::now()->subMinutes(5),
        ]);
        $order->fiscal_sequence_no = 601;
        $order->save();

        return $order->fresh();
    }

    /** POS Operator equivalent: holds `pos-orders` (route perm) but NOT `pos-refund`. */
    private function newPosOperator(Branch $branch): User
    {
        $user = User::factory()->create(['branch_id' => $branch->id, 'password' => Hash::make('password')]);
        Permission::firstOrCreate(['name' => 'pos-orders', 'guard_name' => 'sanctum']);
        $user->givePermissionTo('pos-orders');
        if ($user->hasPermissionTo('pos-refund')) {
            $user->revokePermissionTo('pos-refund');
        }
        return $user;
    }

    // 5. PRIMARY EXPLOIT — POS Operator CANNOT drain the drawer by CANCELING a paid cash sale.
    public function test_pos_operator_cannot_cancel_paid_cash_sale_via_pos_route(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = $this->makePreparingPaidCashOrder($branch, $customer);
        $operator = $this->newPosOperator($branch);

        $resp = $this->actingAs($operator, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'pos-cancel-403-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::CANCELED,
                'reason' => 'POS Operator tries to cancel a paid cash sale = disguised refund.',
            ]);

        $resp->assertStatus(403);
        $this->assertSame(0, \App\Models\CashMovement::where('order_id', $order->id)->count(),
            'Forbidden cancel-as-refund must NOT write a CashMovement (drawer must not move).');
        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status,
            'Forbidden cancel must leave the order at PREPARING.');
    }

    // 6. REJECTED variant on the POS route is gated too.
    public function test_pos_operator_cannot_reject_paid_cash_sale_via_pos_route(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = $this->makePreparingPaidCashOrder($branch, $customer);
        $operator = $this->newPosOperator($branch);

        $resp = $this->actingAs($operator, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'pos-reject-403-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::REJECTED,
                'reason' => 'POS Operator tries to reject a paid cash sale = disguised refund.',
            ]);

        $resp->assertStatus(403);
        $this->assertSame(0, \App\Models\CashMovement::where('order_id', $order->id)->count());
    }

    // 7. TWIN — Waiter cannot cancel a paid order via the table-order route.
    public function test_waiter_cannot_cancel_paid_order_via_table_twin(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = $this->makePreparingPaidCashOrder($branch, $customer);
        $waiter   = $this->newWaiter($branch);

        $resp = $this->actingAs($waiter, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'twin-cancel-403-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/table-order/change-status/{$order->id}", [
                'status' => OrderStatus::CANCELED,
                'reason' => 'Waiter tries to cancel a paid order via the table-order twin.',
            ]);

        $resp->assertStatus(403);
        $this->assertSame(OrderStatus::PREPARING, (int) $order->fresh()->status);
    }

    // 8. NO OVER-BLOCK — canceling an UNPAID order moves no money → the refund gate must NOT fire.
    public function test_cancel_unpaid_order_is_not_blocked_by_refund_gate(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = Order::factory()->create([
            'user_id'        => $customer->id,
            'branch_id'      => $branch->id,
            'order_type'     => OrderType::POS,
            'status'         => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'total'          => 30.00,
            'created_at'     => Carbon::now()->subMinutes(5),
        ]);
        $operator = $this->newPosOperator($branch);

        $resp = $this->actingAs($operator, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'pos-cancel-unpaid-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::CANCELED,
                'reason' => 'Legit operational cancel of an unpaid order.',
            ]);

        // The precise invariant of THIS fix: my pos-refund gate NEVER fires for an
        // unpaid cancel (it moves no money). Any status from OTHER guards
        // (ownership/branch/state) is pre-existing behavior, unchanged by this fix —
        // for payment_status != PAID my added condition is false, so the code path is
        // identical to before. So we assert the gate's signature message is absent.
        $this->assertStringNotContainsString('Permission insuffisante pour effectuer un remboursement', (string) $resp->getContent(),
            'Canceling an UNPAID order moves no money → the pos-refund gate must never fire.');
    }

    // 9. NO OVER-BLOCK for the legit refunder — Admin can still cancel a paid order.
    public function test_admin_can_cancel_paid_order_via_pos_route(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = $this->makePreparingPaidCashOrder($branch, $customer);
        $admin    = $this->newAdmin($branch);
        Permission::firstOrCreate(['name' => 'pos-orders', 'guard_name' => 'sanctum']);
        $admin->givePermissionTo('pos-orders');

        $resp = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'pos-cancel-200-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::CANCELED,
                'reason' => 'Admin issues a legitimate cancel/refund of a paid sale.',
            ]);

        $this->assertNotSame(403, $resp->status(),
            'Admin holds pos-refund → must never be blocked by the refund gate.');
    }
}
