<?php

namespace Tests\Feature\Refund;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\AuditLogService;
use App\Services\Fiscal\FiscalSequenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [WI-REFUND-PREZ 2026-06-04] Single-endpoint refund path-selection.
 *
 * PROBLEM (owner): clicking "Rembourser" + a reason FAILED for a same-day
 * (pre-Z) order because PosOrderController::refundWithCounterEntry routed
 * UNCONDITIONALLY into RefundWithCounterEntryService, whose SealedOrderGuard
 * ::assertSealed() rejects any parent NOT in a CLOSED Z window with HTTP 422.
 *
 * FIX: the controller now branches on SealedOrderGuard::isSealed():
 *   - sealed (post-Z) → counter-entry mirror (UNCHANGED, mode='counter_entry').
 *   - NOT sealed (pre-Z) → reuse the EXISTING OrderService::changeStatus(RETURNED)
 *     pre-Z refund (mode='pre_z', no mirror, captured in the still-open Z).
 *
 * [LOCK-OSM-PREZ-REFUND 2026-06-04, owner-gated] The frozen OrderStateMachine
 * was widened (3 additive, permission-gated guards) so a refund-capable user
 * (`pos-refund` = Admin / Branch Manager) may RETURN a not-yet-delivered pre-Z
 * order from ACCEPT / PREPARING / PREPARED. A live DB query (2026-06-04) showed
 * 1994/1999 paid+fiscalized pre-Z orders sit in those three statuses, so this
 * unblocks the owner's DOMINANT case. The cases below pin the new contract:
 *   (a) pre-Z DELIVERED  → 200 (unchanged: DELIVERED→RETURNED was always legal)
 *   (b) post-Z sealed    → 201 mirror (counter-entry path UNCHANGED)
 *   (c) pre-Z ACCEPT with `pos-refund` → 200 RETURNED (FLIPPED by the LOCK)
 *   (d) pre-Z PREPARING with `pos-refund` → 200 RETURNED (owner dominant case)
 *   (e) pre-Z ACCEPT WITHOUT `pos-refund` (plain POS Operator) → 403 (the
 *       permission gate closes for regular operators; no refund applies)
 */
class PreZRefundViaEndpointTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        Permission::firstOrCreate(['name' => 'pos-refund', 'guard_name' => 'sanctum']);
        Permission::firstOrCreate(['name' => 'pos-orders', 'guard_name' => 'sanctum']);

        Config::set('fiscal.audit_secret', 'test-fiscal-secret-' . str_repeat('a', 40));

        // Real AuditLogService so we can assert the order.returned audit row is
        // appended to the chain on the pre-Z path (acceptance criterion a).
        // The fiscal sequence is stubbed only for the post-Z mirror branch.
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

    private function sealZ(Branch $branch, Carbon $opened, Carbon $closed): void
    {
        ZReport::create([
            'branch_id'   => $branch->id,
            'sequence_no' => 1,
            'opened_at'   => $opened,
            'closed_at'   => $closed,
            'status'      => ZReport::STATUS_CLOSED,
        ]);
    }

    private function makeOrder(Branch $branch, User $customer, int $status, Carbon $createdAt): Order
    {
        $order = Order::factory()->create([
            'user_id'            => $customer->id,
            'branch_id'          => $branch->id,
            'order_type'         => OrderType::POS,
            'status'             => $status,
            'payment_status'     => PaymentStatus::PAID,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'subtotal'           => 30.00,
            'total'              => 30.00,
            'total_tax'          => 0,
            'discount'           => 0,
            'created_at'         => $createdAt,
        ]);
        $order->fiscal_sequence_no = 500;
        $order->save();

        // cashBack() requires a prior `payment`-type Transaction (else it
        // early-returns at PaymentService:132) AND Order->transaction must be
        // truthy for changeStatus to call cashBack (OrderService:2166).
        Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'FK-PREZ-PAID-' . $order->id,
            'amount'         => 30.00,
            'payment_method' => 'cash',
            'type'           => 'payment',
            'sign'           => '+',
        ]);

        return $order->fresh();
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

    /**
     * A regular POS Operator: holds `pos`/`pos-orders` but NOT `pos-refund`
     * (mass-refund vector mitigation — see RolePermissionTableSeeder, the
     * permission is granted only to Admin + Branch Manager). Used for the
     * negative case (e): the controller `pos-refund` gate must 403 BEFORE the
     * state machine is reached, so a non-DELIVERED order is never refunded.
     */
    private function newPosOperator(Branch $branch): User
    {
        $user = User::factory()->create([
            'branch_id' => $branch->id,
            'password'  => Hash::make('password'),
        ]);
        $user->assignRole('POS Operator');
        $user->givePermissionTo('pos-orders');
        $user->givePermissionTo('pos');
        return $user;
    }

    /**
     * (a) PRE-Z paid DELIVERED order → endpoint → 200, status RETURNED,
     * cashBack recorded (cash_back Transaction row), order.returned audit row,
     * NO mirror, NO fiscal gap, mode='pre_z'.
     */
    public function test_pre_z_delivered_order_refunds_via_endpoint_with_no_mirror(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        // NO closed Z window covering the order → pre-Z.
        $order = $this->makeOrder($branch, $customer, OrderStatus::DELIVERED, Carbon::now()->subMinutes(5));

        // [M8-01] Stock-tracked line so we can assert the pre-Z refund RELEASES
        // the decremented stock (via the RefundCreated cascade), exactly like the
        // post-Z mirror path. on_hand 2 → 1 (decrement) → must return to 2 (refund).
        $stockItem = \App\Models\Item::factory()->create();
        \App\Models\OrderItem::query()->create([
            'order_id' => $order->id, 'branch_id' => $branch->id, 'item_id' => $stockItem->id,
            'quantity' => 1, 'price' => 1, 'discount' => 0, 'total_price' => 1,
            'item_variations' => json_encode([]), 'item_extras' => json_encode([]),
        ]);
        \App\Models\StockLevel::query()->create([
            'branch_id' => $branch->id, 'stockable_type' => \App\Models\Item::class,
            'stockable_id' => $stockItem->id, 'on_hand' => 2, 'reserved' => 0,
        ]);
        app(\App\Services\Stock\StockService::class)->decrementForOrder($order->fresh('orderItems'));
        $this->assertSame(1, (int) \App\Models\StockLevel::where('stockable_id', $stockItem->id)->value('on_hand'));

        $admin = $this->newAdmin($branch);

        $orderCountBefore = Order::withoutGlobalScopes()->count();
        $auditCountBefore = \App\Models\AuditLog::withoutGlobalScopes()->count();

        $resp = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'prez-200-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/{$order->id}/refund-with-counter-entry", [
                'reason' => 'Client a rendu la commande — remboursement même jour.',
            ]);

        $resp->assertStatus(200);
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('mode', 'pre_z');
        $resp->assertJsonPath('meta.mirror_fiscal_sequence_no', null);

        // Parent flipped to RETURNED (the canonical "refunded" representation).
        $this->assertSame(
            OrderStatus::RETURNED,
            (int) $order->fresh()->status,
            'Pre-Z refund must set the parent order status to RETURNED.'
        );

        // [M8-01] Pre-Z refund must run the SAME RefundCreated cascade as the post-Z
        // mirror path: the parent's payment_status flips to REFUNDED (the listener's
        // pre-Z branch). Before the fix the cascade never fired on pre-Z, leaving
        // payment_status=PAID and stock/availability un-released.
        $this->assertSame(
            \App\Enums\PaymentStatus::REFUNDED,
            (int) $order->fresh()->payment_status,
            'Pre-Z refund must dispatch RefundCreated → parent payment_status = REFUNDED.'
        );

        // [M8-01] Stock RELEASED back via the RefundCreated cascade (on_hand 1 → 2).
        // Before the fix the pre-Z path never dispatched RefundCreated, so the
        // decremented stock leaked (never returned on a refund).
        $this->assertSame(
            2,
            (int) \App\Models\StockLevel::where('stockable_id', $stockItem->id)->value('on_hand'),
            'Pre-Z refund must RELEASE the decremented stock via the RefundCreated cascade (M8-01).'
        );

        // cashBack recorded — money returned (a cash_back Transaction row).
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'type'     => 'cash_back',
            'sign'     => '-',
        ]);

        // NO mirror order created (no fiscal gap; this is NOT the counter-entry path).
        $this->assertSame(
            $orderCountBefore,
            Order::withoutGlobalScopes()->count(),
            'Pre-Z refund must NOT create a mirror order.'
        );
        $this->assertSame(
            0,
            Order::withoutGlobalScopes()->where('parent_order_id', $order->id)->count(),
            'Pre-Z refund must NOT create any child mirror.'
        );

        // order.returned audit row appended to the NF525 chain.
        $this->assertGreaterThan(
            $auditCountBefore,
            \App\Models\AuditLog::withoutGlobalScopes()->count(),
            'Pre-Z refund must append an audit row (order.returned).'
        );
        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'order.returned',
            'resource_id' => $order->id,
        ]);
    }

    /**
     * (b) POST-Z sealed order → endpoint → 201, mirror created (counter-entry),
     * parent immutable, mode='counter_entry'. The existing path is UNCHANGED.
     */
    public function test_post_z_sealed_order_routes_to_counter_entry_mirror(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        $opened = Carbon::parse('2026-05-01 08:00:00');
        $closed = Carbon::parse('2026-05-01 20:00:00');
        $this->sealZ($branch, $opened, $closed);

        // Order created INSIDE the closed Z window → sealed (post-Z).
        $order = $this->makeOrder($branch, $customer, OrderStatus::ACCEPT, $opened->copy()->addHours(2));

        $admin = $this->newAdmin($branch);

        $resp = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'postz-201-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/{$order->id}/refund-with-counter-entry", [
                'reason' => 'Remboursement post-clôture Z — contrepartie miroir.',
            ]);

        $resp->assertStatus(201);
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('mode', 'counter_entry');

        // Mirror created, parent immutable (stays ACCEPT, stays PAID).
        $mirror = Order::withoutGlobalScopes()->where('parent_order_id', $order->id)->first();
        $this->assertNotNull($mirror, 'Post-Z refund must create a mirror counter-entry order.');
        $this->assertSame(OrderStatus::RETURNED, (int) $mirror->status);
        $this->assertEqualsWithDelta(-30.0, (float) $mirror->total, 0.01);

        $this->assertSame(
            OrderStatus::ACCEPT,
            (int) $order->fresh()->status,
            'Post-Z parent must stay IMMUTABLE (status unchanged).'
        );
        $this->assertSame(
            PaymentStatus::PAID,
            (int) $order->fresh()->payment_status,
            'Post-Z parent payment_status must stay PAID (REFUNDED lives on the mirror).'
        );
    }

    /**
     * (c) FLIPPED BY [LOCK-OSM-PREZ-REFUND 2026-06-04] — PRE-Z order in a
     * NON-DELIVERED status (ACCEPT), acted by a user holding `pos-refund`,
     * now REFUNDS successfully: → 200, mode='pre_z', status RETURNED, cashback
     * Transaction row, `order.returned` audit row, NO mirror, NO fiscal gap.
     *
     * This is the FIRST case that exercises the new permission-gated guard
     * inside OrderStateMachine::allows (DELIVERED→RETURNED at line 77 is
     * unconditional, so case (a) never reaches the `pos-refund` check).
     */
    public function test_pre_z_accept_order_with_pos_refund_now_refunds_200(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        $order = $this->makeOrder($branch, $customer, OrderStatus::ACCEPT, Carbon::now()->subMinutes(5));

        $admin = $this->newAdmin($branch);

        $orderCountBefore = Order::withoutGlobalScopes()->count();
        $auditCountBefore = \App\Models\AuditLog::withoutGlobalScopes()->count();

        $resp = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'prez-accept-200-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/{$order->id}/refund-with-counter-entry", [
                'reason' => 'Pre-Z ACCEPT order — remboursement même jour (LOCK).',
            ]);

        $resp->assertStatus(200);
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('mode', 'pre_z');
        $resp->assertJsonPath('meta.mirror_fiscal_sequence_no', null);

        $this->assertSame(
            OrderStatus::RETURNED,
            (int) $order->fresh()->status,
            'Pre-Z ACCEPT refund must set the parent order status to RETURNED.'
        );

        // cashBack recorded — money returned (a cash_back Transaction row).
        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'type'     => 'cash_back',
            'sign'     => '-',
        ]);

        // NO mirror order created (no fiscal gap; this is NOT the counter-entry path).
        $this->assertSame(
            $orderCountBefore,
            Order::withoutGlobalScopes()->count(),
            'Pre-Z refund must NOT create a mirror order.'
        );
        $this->assertSame(
            0,
            Order::withoutGlobalScopes()->where('parent_order_id', $order->id)->count(),
            'Pre-Z refund must NOT create any child mirror.'
        );

        // order.returned audit row appended to the NF525 chain.
        $this->assertGreaterThan(
            $auditCountBefore,
            \App\Models\AuditLog::withoutGlobalScopes()->count(),
            'Pre-Z refund must append an audit row (order.returned).'
        );
        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'order.returned',
            'resource_id' => $order->id,
        ]);
    }

    /**
     * (d) OWNER DOMINANT CASE — PRE-Z order in PREPARING status (1994/1999 live
     * pre-Z orders sit in ACCEPT/PREPARING/PREPARED), acted by an Admin holding
     * `pos-refund`, hits the single refund endpoint with a reason and is
     * refunded: → 200, status RETURNED, cashback + `order.returned` audit, NO
     * mirror. This is the exact bug the owner reported as un-refundable.
     */
    public function test_pre_z_preparing_order_refunds_via_endpoint_owner_dominant_case(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        $order = $this->makeOrder($branch, $customer, OrderStatus::PREPARING, Carbon::now()->subMinutes(5));

        $admin = $this->newAdmin($branch);

        $orderCountBefore = Order::withoutGlobalScopes()->count();
        $auditCountBefore = \App\Models\AuditLog::withoutGlobalScopes()->count();

        $resp = $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'prez-preparing-200-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/{$order->id}/refund-with-counter-entry", [
                'reason' => 'Commande en préparation annulée — remboursement même jour.',
            ]);

        $resp->assertStatus(200);
        $resp->assertJsonPath('success', true);
        $resp->assertJsonPath('mode', 'pre_z');
        $resp->assertJsonPath('meta.mirror_fiscal_sequence_no', null);

        $this->assertSame(
            OrderStatus::RETURNED,
            (int) $order->fresh()->status,
            'Pre-Z PREPARING refund must set the parent order status to RETURNED.'
        );

        $this->assertDatabaseHas('transactions', [
            'order_id' => $order->id,
            'type'     => 'cash_back',
            'sign'     => '-',
        ]);

        $this->assertSame(
            $orderCountBefore,
            Order::withoutGlobalScopes()->count(),
            'Pre-Z PREPARING refund must NOT create a mirror order.'
        );
        $this->assertSame(
            0,
            Order::withoutGlobalScopes()->where('parent_order_id', $order->id)->count(),
            'Pre-Z PREPARING refund must NOT create any child mirror.'
        );

        $this->assertGreaterThan(
            $auditCountBefore,
            \App\Models\AuditLog::withoutGlobalScopes()->count(),
            'Pre-Z PREPARING refund must append an audit row (order.returned).'
        );
        $this->assertDatabaseHas('audit_logs', [
            'action'      => 'order.returned',
            'resource_id' => $order->id,
        ]);
    }

    /**
     * (e) NEGATIVE — PRE-Z ACCEPT order acted by a plain POS Operator WITHOUT
     * `pos-refund` → the controller permission gate (PosOrderController:58-62)
     * 403s BEFORE the state machine is reached. The order is NOT refunded: no
     * status change, no cashback, no mirror. This pins the blast-radius control
     * from the LOCK doc — the new edge is closed for regular operators.
     */
    public function test_pre_z_accept_order_without_pos_refund_is_forbidden_403(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);

        $order = $this->makeOrder($branch, $customer, OrderStatus::ACCEPT, Carbon::now()->subMinutes(5));

        $operator = $this->newPosOperator($branch);

        $resp = $this->actingAs($operator, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'prez-403-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/{$order->id}/refund-with-counter-entry", [
                'reason' => 'POS Operator sans pos-refund — doit être refusé.',
            ]);

        $resp->assertStatus(403);

        // No state change, no mirror, no cashback — refund did not apply.
        $this->assertSame(OrderStatus::ACCEPT, (int) $order->fresh()->status);
        $this->assertSame(
            0,
            Order::withoutGlobalScopes()->where('parent_order_id', $order->id)->count(),
            'Forbidden refund must NOT create a mirror.'
        );
        $this->assertDatabaseMissing('transactions', [
            'order_id' => $order->id,
            'type'     => 'cash_back',
        ]);
    }

    /**
     * [HEAL R-1 / dispute-final-push 2026-06-13] Double-refund guard.
     *
     * Before the fix, the pre-Z path (refundPreZ → changeStatus(RETURNED)+cashBack)
     * had NO check that a refund already existed for the parent: a SECOND refund
     * request minted a SECOND cash_back for a single sale (double negative).
     * The symmetric guard now returns 409 MIRROR_ALREADY_EXISTS on the replay,
     * whether the first refund was pre-Z (parent → RETURNED) or post-Z (mirror
     * child exists). The first refund still succeeds.
     */
    public function test_pre_z_refund_replay_is_rejected_409_no_second_cashback(): void
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0]);
        $order = $this->makeOrder($branch, $customer, OrderStatus::DELIVERED, Carbon::now()->subMinutes(5));
        $admin = $this->newAdmin($branch);

        // First refund — succeeds (pre-Z, mode='pre_z').
        $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'r1-first-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/{$order->id}/refund-with-counter-entry", [
                'reason' => 'Premier remboursement légitime.',
            ])->assertStatus(200);

        $this->assertSame(OrderStatus::RETURNED, (int) $order->fresh()->status);
        $cashBackAfterFirst = Transaction::query()
            ->where('order_id', $order->id)->where('type', 'cash_back')->count();
        $this->assertSame(1, $cashBackAfterFirst, 'exactly one cash_back after the first refund');

        // Replay — MUST be rejected 409, NO second cash_back minted.
        $this->actingAs($admin, 'sanctum')
            ->withHeaders(['X-Idempotency-Key' => 'r1-replay-' . bin2hex(random_bytes(8))])
            ->postJson("/api/admin/pos-order/{$order->id}/refund-with-counter-entry", [
                'reason' => 'Tentative de double remboursement.',
            ])
            ->assertStatus(409)
            ->assertJsonPath('code', 'MIRROR_ALREADY_EXISTS');

        $this->assertSame(
            1,
            Transaction::query()->where('order_id', $order->id)->where('type', 'cash_back')->count(),
            'replay must NOT mint a second cash_back (double-negative guard).'
        );
    }
}
