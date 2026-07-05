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
}
