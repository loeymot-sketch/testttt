<?php

/**
 * [abuse-heal 2026-06-19 livreur] Livreur (delivery-boy) cash-integration hardening.
 *
 * FINDING 3 (P2) — locks the COD-delivery → DeliveryBoyCashMovement integration:
 *   delivering a COD order while the driver has an OPEN session must record exactly
 *   one TYPE_ORDER_COLLECT / IN movement for the collected amount, plus the NF525
 *   cash-collection audit row, plus the PAID + fiscal-sequence finalization. No
 *   prior test asserted the movement row (DeliveryBoyChangeStatusFiscalAllocTest
 *   only checks payment_status + sequence).
 *
 * FINDING 1 (P1) — locks the refund reversal: when a driver-collected COD order is
 *   later transitioned to RETURNED (the fiscal counter-entry transition), the
 *   driver's collected cash must be reversed with a compensating DIRECTION_OUT
 *   movement so Σ(movements) nets to 0 for that order — otherwise the driver's
 *   session shows a false shortage (they look like they pocketed the refunded cash).
 *   RED before the OrderService heal, GREEN after.
 *
 * :memory: note — SQLite runs these sequentially; the locked-tx atomicity of the
 * collect + reversal paths (lockForUpdate + DB::transaction, already present in
 * changeStatus / deliveryBoyOrderChangeStatus) is the driver-path's documented
 * pattern and is exercised end-to-end against MySQL :8766.
 */

namespace Tests\Feature\Abuse;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Http\Requests\OrderStatusRequest;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DeliveryBoyCashMovement;
use App\Models\DeliveryBoyCashSession;
use App\Models\Order;
use App\Models\User;
use App\Services\Delivery\DeliveryBoyCashSessionService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class LivreurCashIntegrationAbuseTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();

        $this->driver = User::forceCreate([
            'name' => 'Driver', 'email' => 'driver@livreur-cash.test', 'username' => 'driver_livreur_cash',
            'password' => bcrypt('x'), 'branch_id' => $this->branch->id, 'email_verified_at' => now(), 'status' => 1,
        ]);
        $this->driver->assignRole(EnumRole::DELIVERY_BOY);

        $this->actingAs($this->driver->fresh(), 'sanctum');
    }

    private function makeCodOrder(int $status = OrderStatus::OUT_FOR_DELIVERY, ?int $seq = null): Order
    {
        return Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'order_type'         => OrderType::DELIVERY,
            'delivery_boy_id'    => $this->driver->id,
            'status'             => $status,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status'     => PaymentStatus::UNPAID,
            'total'              => 27.50,
            'fiscal_sequence_no' => $seq,
        ]);
    }

    private function openDriverSession(float $opening = 50.0): DeliveryBoyCashSession
    {
        return app(DeliveryBoyCashSessionService::class)->openSession(
            (int) $this->branch->id,
            (int) $this->driver->id,
            $opening,
            (int) $this->driver->id,
        );
    }

    /** Driver-app change-status (the path that finalizes COD + records cash). */
    private function driverChangeStatus(Order $order, int $status, ?string $reason = null): Order
    {
        $payload = ['status' => $status];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }
        $req = Request::create('/api/frontend/delivery-boy-order/change-status/' . $order->id, 'POST', $payload);

        app(OrderService::class)->deliveryBoyOrderChangeStatus($order, $req);

        // Refresh the passed model in-place so callers reusing $order see the
        // persisted status (the next changeStatus reads $order->status pre-lock).
        $order->setRawAttributes($order->fresh()->getAttributes(), true);

        return $order;
    }

    /** Admin/POS change-status (used for the RETURNED counter-entry transition). */
    private function adminChangeStatus(Order $order, int $status, ?string $reason = null): Order
    {
        $payload = ['status' => $status];
        if ($reason !== null) {
            $payload['reason'] = $reason;
        }
        $request = OrderStatusRequest::create('/api/admin/online-order/change-status/' . $order->id, 'POST', $payload);
        $request->setContainer(app())->setRedirector(app('redirect'));

        app(OrderService::class)->changeStatus($order, $request);

        return $order->fresh();
    }

    private function movementsForOrder(int $sessionId, int $orderId)
    {
        return DeliveryBoyCashMovement::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('delivery_boy_cash_session_id', $sessionId)
            ->where('order_id', $orderId)
            ->get();
    }

    // ------------------------------------------------------------------ //
    //  FINDING 3 — COD delivered records the order-collect IN movement
    // ------------------------------------------------------------------ //

    /** @test */
    public function test_cod_delivered_with_open_session_records_order_collect_in_movement(): void
    {
        $session = $this->openDriverSession(50.0);
        $order   = $this->makeCodOrder(OrderStatus::OUT_FOR_DELIVERY);

        $fresh = $this->driverChangeStatus($order, OrderStatus::DELIVERED);

        // (c) order finalized: PAID + fiscal allocated.
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status, 'COD delivered must be PAID.');
        $this->assertNotNull($fresh->fiscal_sequence_no, 'COD delivered must allocate a fiscal sequence.');

        // (a) exactly one cash-collection audit row.
        $escrowAudits = AuditLog::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('action', 'delivery.cash_collected_escrow')
            ->where('resource_id', $order->id)
            ->count();
        $this->assertSame(1, $escrowAudits, 'Exactly one delivery.cash_collected_escrow audit row expected.');

        // (b) exactly one order_collect / IN / 27.50 movement tied to order + session.
        $movements = $this->movementsForOrder((int) $session->id, (int) $order->id);
        $this->assertCount(1, $movements, 'Exactly one cash movement expected for the collected COD order.');

        $movement = $movements->first();
        $this->assertSame(DeliveryBoyCashMovement::TYPE_ORDER_COLLECT, $movement->type);
        $this->assertSame(DeliveryBoyCashMovement::DIRECTION_IN, $movement->direction);
        $this->assertSame('27.50', (string) $movement->amount);
        $this->assertSame((int) $session->id, (int) $movement->delivery_boy_cash_session_id);
    }

    // ------------------------------------------------------------------ //
    //  FINDING 1 — RETURNED of a collected COD reverses the cash movement
    // ------------------------------------------------------------------ //

    /** @test */
    public function test_returned_after_collect_records_compensating_out_and_nets_to_zero(): void
    {
        $session = $this->openDriverSession(50.0);
        $order   = $this->makeCodOrder(OrderStatus::OUT_FOR_DELIVERY);

        // Collect: deliver the COD order (records the +IN movement).
        $this->driverChangeStatus($order, OrderStatus::DELIVERED);

        $afterCollect = $this->movementsForOrder((int) $session->id, (int) $order->id);
        $this->assertCount(1, $afterCollect, 'Pre-condition: collect must have recorded exactly one IN movement.');

        // Refund: DELIVERED → RETURNED is the fiscal counter-entry transition.
        $returned = $this->adminChangeStatus($order, OrderStatus::RETURNED, 'client refused delivery — refund');
        $this->assertSame(OrderStatus::RETURNED, (int) $returned->status, 'Order must reach RETURNED.');

        // A compensating -OUT movement must now exist for that order.
        $all = $this->movementsForOrder((int) $session->id, (int) $order->id);
        $out = $all->firstWhere('direction', DeliveryBoyCashMovement::DIRECTION_OUT);
        $this->assertNotNull(
            $out,
            'FINDING 1: RETURNED of a driver-collected COD must record a compensating DIRECTION_OUT movement.'
        );
        $this->assertSame('27.50', (string) $out->amount, 'Reversal amount must equal the collected amount.');

        // Σ(signed movements) for that order must net to exactly 0 (no false shortage).
        $net = round($all->sum(fn (DeliveryBoyCashMovement $m) => $m->signedAmount()), 2);
        $this->assertSame(0.0, $net, 'Σ(movements) for a collected-then-returned COD order must net to 0.');
    }

    /** @test — the reversal is idempotent: re-invoking RETURNED does not double the -OUT */
    public function test_returned_reversal_is_idempotent(): void
    {
        $session = $this->openDriverSession(50.0);
        $order   = $this->makeCodOrder(OrderStatus::OUT_FOR_DELIVERY);

        $this->driverChangeStatus($order, OrderStatus::DELIVERED);
        $this->adminChangeStatus($order, OrderStatus::RETURNED, 'refund 1');
        // Second call is a no-op transition (already RETURNED) — must not add a 2nd reversal.
        $this->adminChangeStatus($order, OrderStatus::RETURNED, 'refund 2');

        $outs = $this->movementsForOrder((int) $session->id, (int) $order->id)
            ->where('direction', DeliveryBoyCashMovement::DIRECTION_OUT);
        $this->assertCount(1, $outs, 'Exactly one compensating -OUT movement — reversal must be idempotent.');
    }

    /** @test — a RETURNED order that was NEVER collected gets no spurious reversal */
    public function test_returned_without_prior_collect_records_no_reversal(): void
    {
        // Deliver the COD order while NO driver session is open → the collect path
        // skips the movement silently (G-DELIV-CASH), so no escrow movement exists.
        $order = $this->makeCodOrder(OrderStatus::OUT_FOR_DELIVERY);
        $this->driverChangeStatus($order, OrderStatus::DELIVERED);

        // Now open a session AFTER delivery — there is still no movement for this order.
        $session = $this->openDriverSession(50.0);
        $this->assertCount(
            0,
            $this->movementsForOrder((int) $session->id, (int) $order->id),
            'Pre-condition: order delivered without an open session has no collect movement.'
        );

        // RETURNED must not fabricate a reversal for cash that was never recorded
        // into a session (the escrow audit exists, but there is no IN movement to reverse).
        $this->adminChangeStatus($order, OrderStatus::RETURNED, 'refund after no-session delivery');

        $this->assertCount(
            0,
            $this->movementsForOrder((int) $session->id, (int) $order->id),
            'A RETURNED order with no recorded session collect must NOT get a spurious reversal movement.'
        );
    }

    // ------------------------------------------------------------------ //
    //  FINDING 2 — delivery reconcile variance gate mirrors the drawer
    // ------------------------------------------------------------------ //

    private function makeBranchManager(): User
    {
        $manager = User::forceCreate([
            'name' => 'Manager', 'email' => 'manager@livreur-cash.test', 'username' => 'manager_livreur_cash',
            'password' => bcrypt('x'), 'branch_id' => $this->branch->id, 'email_verified_at' => now(), 'status' => 1,
        ]);
        $manager->assignRole('Branch Manager');

        return $manager->fresh();
    }

    /**
     * Open a driver session with $opening, record one IN $collect, close at $closing.
     * variance = $closing − ($opening + $collect).
     */
    private function openCollectClose(float $opening, float $collect, float $closing): DeliveryBoyCashSession
    {
        $svc = app(DeliveryBoyCashSessionService::class);
        $session = $svc->openSession((int) $this->branch->id, (int) $this->driver->id, $opening, (int) $this->driver->id);
        if ($collect > 0) {
            $svc->recordMovement(
                (int) $session->id,
                DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
                $collect,
                DeliveryBoyCashMovement::DIRECTION_IN,
            );
        }
        $svc->closeSession((int) $session->id, $closing);

        return $session->refresh();
    }

    /** @test — under-threshold variance reconciles without a reason (matches drawer) */
    public function test_reconcile_under_threshold_succeeds_without_reason(): void
    {
        \Illuminate\Support\Facades\Config::set('cash.variance_threshold_eur', 2.00);
        $session = $this->openCollectClose(50.0, 10.0, 61.50); // expected 60, variance +1.50 (<=2)

        $result = app(DeliveryBoyCashSessionService::class)->reconcileSession((int) $session->id);

        $this->assertEqualsWithDelta(1.50, $result['variance'], 0.01);
        $this->assertSame(DeliveryBoyCashSession::STATUS_RECONCILED, $result['session']->status);
    }

    /** @test — over-threshold without reason → CODE_REASON_REQUIRED (matches drawer) */
    public function test_reconcile_over_threshold_without_reason_throws_reason_required(): void
    {
        \Illuminate\Support\Facades\Config::set('cash.variance_threshold_eur', 2.00);
        $session = $this->openCollectClose(50.0, 10.0, 65.0); // expected 60, variance +5 (>2)

        try {
            app(DeliveryBoyCashSessionService::class)->reconcileSession((int) $session->id);
            $this->fail('Expected CashVarianceRequiresApprovalException');
        } catch (\App\Exceptions\CashVarianceRequiresApprovalException $e) {
            $this->assertSame(422, $e->getStatusCode());
            $this->assertSame(\App\Exceptions\CashVarianceRequiresApprovalException::CODE_REASON_REQUIRED, $e->getErrorCode());
            $this->assertEqualsWithDelta(5.0, $e->getVariance(), 0.01);
        }

        // Session stays CLOSED, not advanced to RECONCILED (fail-closed).
        $this->assertSame(DeliveryBoyCashSession::STATUS_CLOSED, $session->refresh()->status);
    }

    /** @test — over-threshold + reason but non-manager actor → CODE_MANAGER_APPROVAL (matches drawer) */
    public function test_reconcile_over_threshold_with_reason_but_non_manager_throws_manager_approval(): void
    {
        \Illuminate\Support\Facades\Config::set('cash.variance_threshold_eur', 2.00);
        $session = $this->openCollectClose(50.0, 10.0, 65.0); // variance +5 (>2)

        // A POS Operator does NOT hold cash.reconcile.variance.override (mirror of the
        // drawer's CashVarianceGateTest non-manager case). NB: roles are referenced by
        // NAME here on purpose — assignRole(int) resolves to a role *id*, and the test
        // seeder's "Branch Manager" happens to own id 3 (= EnumRole::DELIVERY_BOY), so an
        // int-id assign would wrongly grant the override. By-name keeps the actor honest.
        $operator = User::forceCreate([
            'name' => 'Operator', 'email' => 'operator@livreur-cash.test', 'username' => 'operator_livreur_cash',
            'password' => bcrypt('x'), 'branch_id' => $this->branch->id, 'email_verified_at' => now(), 'status' => 1,
        ]);
        $operator->assignRole('POS Operator');

        try {
            app(DeliveryBoyCashSessionService::class)->reconcileSession(
                (int) $session->id,
                'operator over-counted',
                $operator->fresh(),
            );
            $this->fail('Expected CashVarianceRequiresApprovalException');
        } catch (\App\Exceptions\CashVarianceRequiresApprovalException $e) {
            $this->assertSame(
                \App\Exceptions\CashVarianceRequiresApprovalException::CODE_MANAGER_APPROVAL,
                $e->getErrorCode()
            );
        }

        $this->assertSame(DeliveryBoyCashSession::STATUS_CLOSED, $session->refresh()->status);
    }

    /** @test — over-threshold + reason + manager → reconciles (matches drawer) */
    public function test_reconcile_over_threshold_with_reason_and_manager_succeeds(): void
    {
        \Illuminate\Support\Facades\Config::set('cash.variance_threshold_eur', 2.00);
        $session = $this->openCollectClose(50.0, 10.0, 70.0); // expected 60, variance +10 (>2)

        $manager = $this->makeBranchManager();
        $result = app(DeliveryBoyCashSessionService::class)->reconcileSession(
            (int) $session->id,
            'till re-count surplus approved by floor manager',
            $manager,
        );

        $this->assertEqualsWithDelta(10.0, $result['variance'], 0.01);
        $this->assertSame(DeliveryBoyCashSession::STATUS_RECONCILED, $result['session']->status);
        $this->assertSame('till re-count surplus approved by floor manager', $result['session']->variance_reason);
    }

    // ------------------------------------------------------------------ //
    //  FINDING 4 — close/reconcile are traceable to the acting staff
    // ------------------------------------------------------------------ //

    /** @test — the acting staff id lands in the close + reconcile audit payloads */
    public function test_close_and_reconcile_audit_payloads_capture_acting_staff(): void
    {
        \Illuminate\Support\Facades\Config::set('cash.variance_threshold_eur', 2.00);

        // Counter staff (a manager) reconciles the driver's shift — must be traceable.
        $manager = $this->makeBranchManager();
        $this->actingAs($manager);

        $svc = app(DeliveryBoyCashSessionService::class);
        $session = $svc->openSession((int) $this->branch->id, (int) $this->driver->id, 50.0, (int) $this->driver->id);
        $svc->recordMovement(
            (int) $session->id,
            DeliveryBoyCashMovement::TYPE_ORDER_COLLECT,
            10.0,
            DeliveryBoyCashMovement::DIRECTION_IN,
        );
        $svc->closeSession((int) $session->id, 60.0); // variance 0 → no gate
        $svc->reconcileSession((int) $session->id, null, $manager);

        $closedPayload = $this->auditPayload('cash.delivery.session.closed', (int) $session->id);
        $this->assertSame(
            (int) $manager->id,
            (int) ($closedPayload['closed_by_user_id'] ?? 0),
            'FINDING 4: the close audit payload must record the acting staff.'
        );

        $reconciledPayload = $this->auditPayload('cash.delivery.session.reconciled', (int) $session->id);
        $this->assertSame(
            (int) $manager->id,
            (int) ($reconciledPayload['reconciled_by_user_id'] ?? 0),
            'FINDING 4: the reconcile audit payload must record the acting staff.'
        );

        // And the session columns are the source of truth for the same actor.
        $this->assertSame((int) $manager->id, (int) $session->refresh()->closed_by_user_id);
        $this->assertSame((int) $manager->id, (int) $session->refresh()->reconciled_by_user_id);
    }

    /** @return array<string,mixed> */
    private function auditPayload(string $action, int $sessionId): array
    {
        $row = AuditLog::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('action', $action)
            ->where('resource', 'delivery_boy_cash_session')
            ->where('resource_id', $sessionId)
            ->latest('id')
            ->firstOrFail();

        return is_array($row->payload) ? $row->payload : (array) json_decode((string) $row->payload, true);
    }
}
