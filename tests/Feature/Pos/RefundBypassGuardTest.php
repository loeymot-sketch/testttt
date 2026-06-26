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
 * [GOAL test-e2e all-systems 2026-06-26 / caisse — REFUND-BYPASS P1] Twin-route
 * authz-drift guard.
 *
 * THE BUG (triple-confirmed, proven live): the dedicated NF525 refund endpoint
 * `POST /api/admin/pos-order/{order}/refund-with-counter-entry` IS guarded
 * (PosOrderController::refundWithCounterEntry:58-62 abort_unless can('pos-refund')).
 * But its SIBLING route `POST /api/admin/pos-order/change-status/{order}` — gated
 * only by `permission:pos-orders` (routes/api.php:983) which a POS Operator HAS —
 * delegates straight to OrderService::changeStatus. The DELIVERED(13)→RETURNED(22)
 * edge is UNCONDITIONAL in OrderStateMachine::allows (line 76-77), so a plain POS
 * Operator (role 7: has `pos`+`pos-orders`, NOT `pos-refund`) can REFUND a
 * DELIVERED+PAID order through change-status: it fires PaymentService::cashBack()
 * (money returned to drawer/customer + balance credit + stock release) +
 * LoyaltyService::refundPoints(). That is the exact mass-refund vector the
 * dedicated endpoint's gate (PROPOSAL_POS_REFUND_UI_2026-05-25 §8 risk #1) was
 * added to close — re-opened via the un-guarded twin route.
 *
 * THE FIX (non-frozen, controller layer): PosOrderController::changeStatus
 * mirrors the dedicated endpoint's gate — when the target status is RETURNED,
 * abort_unless(can('pos-refund'), 403) BEFORE delegating. The state machine edge
 * stays inconditional (frozen + owner-locked LOCK_ORDERSTATEMACHINE_PREZ_REFUND
 * — the sentinel OrderStateMachinePreZRefundLockSentinelTest keeps
 * allows(DELIVERED,RETURNED,null)===true); authorization lives at the controller.
 *
 * Sentinel cases:
 *   1. POS Operator (no pos-refund) + DELIVERED+PAID → change-status RETURNED
 *      → 403 + 0 cash_back Transaction rows + status UNCHANGED (stays 13).
 *   2. Admin (has pos-refund) + same order → 200 (legit refund passes; cash_back fires).
 *   3. Branch Manager (has pos-refund) + same order → 200 (legit refund passes).
 *   4. NON-refund transitions by the Operator stay OK (no over-blocking):
 *      ACCEPT→PREPARING and the `pos`-shortcut ACCEPT→DELIVERED still succeed.
 */
class RefundBypassGuardTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'pos-orders', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'pos', 'guard_name' => 'sanctum']);

        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        // Stub the NF525 audit + sequence services — we exercise the AUTHZ gate,
        // not the fiscal chain. Mirrors PosRefundUiPermissionSentinelTest.
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}
            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });

        $sequenceCounter = 9000;
        $this->app->instance(FiscalSequenceService::class, new class($sequenceCounter) extends FiscalSequenceService {
            private int $counter;
            public function __construct(int $start)
            {
                $this->counter = $start;
            }
            public function next(int $branchId): int
            {
                return ++$this->counter;
            }
        });
    }

    /**
     * A DELIVERED + PAID POS order WITH a prior `payment` Transaction, so that a
     * RETURNED transition would actually fire cashBack (proving real money would
     * move). created_at = now → pre-Z (no closed Z window), so the parent is
     * mutable and change-status→RETURNED reaches cashBack.
     */
    private function makeDeliveredPaidOrder(Branch $branch, User $customer): Order
    {
        $order = Order::factory()->create([
            'user_id'            => $customer->id,
            'branch_id'          => $branch->id,
            'order_type'         => OrderType::POS,
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
        $order->fiscal_sequence_no = 500;
        $order->save();

        // cashBack() requires a prior `payment` Transaction (else early-return at
        // PaymentService:132) AND Order->transaction must be truthy for
        // changeStatus to call cashBack (OrderService:2052).
        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'FK-BYPASS-PAID-' . $order->id,
            'amount'         => 30.00,
            'payment_method' => 'cash',
            'type'           => 'payment',
            'sign'           => '+',
        ]);

        return $order->fresh();
    }

    /** Plain POS Operator: holds `pos` + `pos-orders` but NOT `pos-refund`. */
    private function newPosOperator(Branch $branch): User
    {
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'password'  => Hash::make('password'),
        ]);
        $user->assignRole('POS Operator');
        $user->givePermissionTo('pos-orders');
        $user->givePermissionTo('pos');
        // Make intent crystal-clear: this actor must NOT hold pos-refund.
        if ($user->hasPermissionTo('pos-refund')) {
            $user->revokePermissionTo('pos-refund');
        }
        return $user;
    }

    private function newAdmin(Branch $branch): User
    {
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'password'  => Hash::make('password'),
        ]);
        $user->assignRole('Admin');
        $user->givePermissionTo('pos-orders');
        $user->givePermissionTo('pos-refund');
        $user->givePermissionTo('pos');
        return $user;
    }

    private function newBranchManager(Branch $branch): User
    {
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'password'  => Hash::make('password'),
        ]);
        $user->assignRole('Branch Manager');
        $user->givePermissionTo('pos-orders');
        $user->givePermissionTo('pos-refund');
        return $user;
    }

    // ─────────────────────────────────────────────────────────────────────
    // 1. THE BYPASS — POS Operator without pos-refund must be FORBIDDEN.
    // ─────────────────────────────────────────────────────────────────────

    public function test_pos_operator_cannot_refund_via_change_status_returned(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = $this->makeDeliveredPaidOrder($branch, $customer);

        $operator = $this->newPosOperator($branch);

        $resp = $this->actingAs($operator, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'bypass-403-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::RETURNED,
                'reason' => 'POS Operator tries to refund via the change-status twin route.',
            ]);

        // The twin route must mirror the dedicated endpoint's gate: 403.
        $resp->assertStatus(403);

        // Money must NOT have moved: zero cash_back Transaction rows.
        $cashBackCount = Transaction::where('order_id', $order->id)
            ->where('type', 'cash_back')
            ->count();
        $this->assertSame(0, $cashBackCount,
            'Forbidden refund-via-change-status must NOT create a cash_back transaction.');

        // Status must be UNCHANGED (still DELIVERED, never flipped to RETURNED).
        $this->assertSame(
            OrderStatus::DELIVERED,
            (int) $order->fresh()->status,
            'Forbidden refund must leave the order status at DELIVERED (no RETURNED flip).'
        );
    }

    // ─────────────────────────────────────────────────────────────────────
    // 2. LEGITIMATE REFUND — pos-refund holders still succeed (no over-block).
    // ─────────────────────────────────────────────────────────────────────

    public function test_admin_can_refund_via_change_status_returned(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = $this->makeDeliveredPaidOrder($branch, $customer);

        $admin = $this->newAdmin($branch);

        $resp = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'admin-200-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::RETURNED,
                'reason' => 'Admin issues a legitimate refund at the counter.',
            ]);

        $resp->assertStatus(200);

        $this->assertSame(
            OrderStatus::RETURNED,
            (int) $order->fresh()->status,
            'Admin refund must flip the order to RETURNED.'
        );

        // Real refund money trail recorded.
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'type'     => 'cash_back',
        ]);
    }

    public function test_branch_manager_can_refund_via_change_status_returned(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order    = $this->makeDeliveredPaidOrder($branch, $customer);

        $manager = $this->newBranchManager($branch);

        $resp = $this->actingAs($manager, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'manager-200-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/change-status/{$order->id}", [
                'status' => OrderStatus::RETURNED,
                'reason' => 'Branch Manager issues a legitimate refund.',
            ]);

        $resp->assertStatus(200);
        $this->assertSame(OrderStatus::RETURNED, (int) $order->fresh()->status);
    }

    // ─────────────────────────────────────────────────────────────────────
    // 3. NO OVER-BLOCK — the Operator's NON-refund transitions stay OK.
    // ─────────────────────────────────────────────────────────────────────

    public function test_pos_operator_non_refund_transitions_still_allowed(): void
    {
        $branch   = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $operator = $this->newPosOperator($branch);

        // ACCEPT → PREPARING (kitchen advance, no permission needed): allowed.
        $accepted = Order::factory()->create([
            'user_id'            => $customer->id,
            'branch_id'          => $branch->id,
            'order_type'         => OrderType::POS,
            'status'             => OrderStatus::ACCEPT,
            'payment_status'     => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'subtotal'           => 10.00,
            'total'              => 10.00,
            'total_tax'          => 0,
            'discount'           => 0,
            'created_at'         => Carbon::now()->subMinutes(2),
        ]);

        $respPrep = $this->actingAs($operator, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'op-prep-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/change-status/{$accepted->id}", [
                'status' => OrderStatus::PREPARING,
            ]);

        $respPrep->assertStatus(200);
        $this->assertSame(OrderStatus::PREPARING, (int) $accepted->fresh()->status);

        // ACCEPT → DELIVERED (the `pos`-shortcut the operator holds): allowed.
        $toDeliver = Order::factory()->create([
            'user_id'            => $customer->id,
            'branch_id'          => $branch->id,
            'order_type'         => OrderType::POS,
            'status'             => OrderStatus::ACCEPT,
            'payment_status'     => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'subtotal'           => 10.00,
            'total'              => 10.00,
            'total_tax'          => 0,
            'discount'           => 0,
            'created_at'         => Carbon::now()->subMinutes(2),
        ]);

        $respDeliver = $this->actingAs($operator, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'op-deliver-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/change-status/{$toDeliver->id}", [
                'status' => OrderStatus::DELIVERED,
            ]);

        $respDeliver->assertStatus(200);
        $this->assertSame(OrderStatus::DELIVERED, (int) $toDeliver->fresh()->status);
    }
}
