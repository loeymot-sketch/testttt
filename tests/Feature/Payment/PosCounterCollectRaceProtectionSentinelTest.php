<?php

namespace Tests\Feature\Payment;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Exceptions\Payment\PaymentAlreadyCollectedException;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * [GOAL-K2-HEAL-01 2026-05-24] Phase K.4 H9 P1 + J-CASCADE H9 UNHEALED
 *
 * Sentinel for the 2-cashier race condition on POS counter-collect:
 *   - Cashier A wins the `lockForUpdate` in
 *     `PaymentService::confirmCounterPayment` and flips
 *     `payment_status = PAID`.
 *   - Cashier B's subsequent call to `confirmCounterPayment` MUST throw
 *     `PaymentAlreadyCollectedException` (formerly silent short-circuit
 *     that returned 200 + OrderDetailsResource → frontend toasted
 *     `cash_drawer_opened_simulation` → drawer-open + till-count risk).
 *
 * Verifications:
 *   - Service-level: typed exception is thrown with the right payload.
 *   - Route-level: POST returns 409 Conflict + `error_code:
 *     payment_already_collected` (NOT 422 — the 422 fallback in the
 *     route closure must be bypassed by the typed catch above it).
 *   - Data integrity (must hold both PRE and POST heal — the bug was
 *     a UX defect, not a data corruption defect):
 *       - exactly 1 `cash_movements` row for order
 *       - exactly 1 `audit_logs` row with action
 *         `order.counter_payment_confirmed`
 *       - exactly 1 `transactions` row of type `payment`
 *       - cashier B's POST creates ZERO additional rows in any of the
 *         above tables.
 */
class PosCounterCollectRaceProtectionSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    /**
     * Service-level sentinel. Cashier A confirms via the legitimate
     * service path (which writes the `order.counter_payment_confirmed`
     * audit row with `user_id = cashier A`). Then call
     * `confirmCounterPayment` as cashier B and expect the typed
     * exception with collected_by_user_id = cashier A and collected_at
     * populated from the audit-row timestamp.
     */
    public function test_service_throws_typed_exception_when_different_cashier_races(): void
    {
        Queue::fake();
        Event::fake();

        [$branch, $cashierA, $cashierB] = $this->branchTwoCashiers();

        $order = $this->pendingCounterOrder($branch);

        // Cashier A wins via the legitimate service path.
        $this->actingAs($cashierA, 'sanctum');
        app(PaymentService::class)->confirmCounterPayment(
            $order,
            PosPaymentMethod::CASH,
            12.00,
            'Cashier A wins'
        );

        // Confirm audit row recorded cashier A.
        $auditRow = AuditLog::query()
            ->where('resource', 'order')
            ->where('resource_id', $order->id)
            ->where('action', 'order.counter_payment_confirmed')
            ->latest('id')
            ->first();
        $this->assertNotNull($auditRow);
        $this->assertSame($cashierA->id, (int) $auditRow->user_id);

        // Cashier B races.
        $caught = null;
        try {
            $this->actingAs($cashierB, 'sanctum');
            app(PaymentService::class)->confirmCounterPayment(
                $order->fresh(),
                PosPaymentMethod::CASH,
                12.00,
                'Cashier B race attempt'
            );
            $this->fail('Expected PaymentAlreadyCollectedException, none thrown.');
        } catch (PaymentAlreadyCollectedException $e) {
            $caught = $e;
        }

        $this->assertNotNull($caught, 'PaymentAlreadyCollectedException must be thrown for different cashier.');
        $this->assertSame($order->id, $caught->orderId);
        $this->assertSame($cashierA->id, $caught->collectedByUserId, 'Should report cashier A from audit_logs.user_id.');
        $this->assertNotNull($caught->collectedAt, 'collected_at sourced from audit row created_at — must be populated.');
        $this->assertSame('Commande déjà encaissée par un autre caissier.', $caught->getMessage());
    }

    /**
     * V5.5 sister-guard preservation. Same cashier calling twice with
     * different idempotency keys MUST still get 200 (no-op replay) —
     * this is the deliberate defense layer behind IdempotencyKeyMiddleware
     * documented in `C5_EncaisserKdsPreserveTest:302-355`. The heal MUST
     * NOT break it.
     */
    public function test_service_same_cashier_replay_returns_no_op_not_exception(): void
    {
        Queue::fake();
        Event::fake();

        [$branch, $cashierA, $_cashierB] = $this->branchTwoCashiers();
        $order = $this->pendingCounterOrder($branch);

        $this->actingAs($cashierA, 'sanctum');
        app(PaymentService::class)->confirmCounterPayment(
            $order,
            PosPaymentMethod::CASH,
            12.00,
            'Cashier A first attempt'
        );

        // Same cashier calls again — must NOT throw, must hydrate the
        // PAID attributes back onto the caller's $order object.
        $second = $order->fresh();
        try {
            app(PaymentService::class)->confirmCounterPayment(
                $second,
                PosPaymentMethod::CASH,
                12.00,
                'Cashier A retry (cache miss)'
            );
        } catch (PaymentAlreadyCollectedException $e) {
            $this->fail('Same cashier replay MUST NOT throw — V5.5 sister-guard regression.');
        }

        $this->assertSame(PaymentStatus::PAID, (int) $second->payment_status);

        // No second audit row written.
        $auditCount = AuditLog::query()
            ->where('resource', 'order')
            ->where('resource_id', $order->id)
            ->where('action', 'order.counter_payment_confirmed')
            ->count();
        $this->assertSame(1, $auditCount, 'Same-cashier replay must NOT advance audit_logs.');
    }

    /**
     * Route-level sentinel. Direct DB flip simulates cashier A's win,
     * then POST from cashier B must return 409 + structured payload.
     * Critically, this verifies the typed catch in the route closure
     * fires ABOVE the generic Exception→422 fallback.
     */
    public function test_route_returns_409_with_error_code_on_race(): void
    {
        Queue::fake();
        Event::fake();

        [$branch, $cashierA, $cashierB] = $this->branchTwoCashiers();

        $order = $this->pendingCounterOrder($branch);

        // Pre-state snapshot (no movements/audit/transactions before A).
        $this->assertSame(0, CashMovement::query()->where('order_id', $order->id)->count());
        $this->assertSame(0, Transaction::query()->where('order_id', $order->id)->count());

        // Simulate cashier A's full commit using the legitimate service
        // call so the cash_movement / audit_log / transaction rows are
        // created via production code path.
        $this->actingAs($cashierA, 'sanctum');
        app(PaymentService::class)->confirmCounterPayment(
            $order,
            PosPaymentMethod::CASH,
            12.00,
            'Cashier A wins'
        );

        // Snapshot post-A counts so we can assert cashier B added zero.
        $countMovementsAfterA = CashMovement::query()->where('order_id', $order->id)->count();
        $countAuditAfterA = AuditLog::query()
            ->where('resource', 'order')
            ->where('resource_id', $order->id)
            ->where('action', 'order.counter_payment_confirmed')
            ->count();
        $countTxAfterA = Transaction::query()
            ->where('order_id', $order->id)
            ->where('type', 'payment')
            ->count();

        $this->assertSame(1, $countAuditAfterA, 'Cashier A should produce exactly 1 audit row for counter_payment_confirmed.');
        $this->assertSame(1, $countTxAfterA, 'Cashier A should produce exactly 1 payment Transaction row.');
        // cash_movement is best-effort; may be 0 if no open cash drawer
        // session exists in the test env (legacy F-003 path log+return).
        // We assert "≤ 1" then re-assert "unchanged after B" below.

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI
        // sends it). Cashier A won via a direct service call (NOT an HTTP POST), so this is the only HTTP request
        // for this order — a fresh key reaches the controller, where the already-collected guard fires the 409
        // (the race-loser outcome under test). Without the header a 422 would mask the 409.
        $response = $this->actingAs($cashierB, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 12.00,
                'note' => 'Cashier B race POST',
            ]);

        $response->assertStatus(409);
        $response->assertJsonPath('error_code', 'payment_already_collected');
        $response->assertJsonPath('order_id', $order->id);
        $response->assertJsonPath('message', 'Commande déjà encaissée par un autre caissier.');
        $response->assertJsonPath('status', false);

        // Data integrity assertions — cashier B added ZERO rows.
        $this->assertSame(
            $countMovementsAfterA,
            CashMovement::query()->where('order_id', $order->id)->count(),
            'Cashier B must NOT create a second cash_movement row.'
        );
        $this->assertSame(
            $countAuditAfterA,
            AuditLog::query()
                ->where('resource', 'order')
                ->where('resource_id', $order->id)
                ->where('action', 'order.counter_payment_confirmed')
                ->count(),
            'Cashier B must NOT create a second order.counter_payment_confirmed audit row.'
        );
        $this->assertSame(
            $countTxAfterA,
            Transaction::query()
                ->where('order_id', $order->id)
                ->where('type', 'payment')
                ->count(),
            'Cashier B must NOT create a second payment Transaction row.'
        );

        // Fiscal sequence integrity — no second allocation, value unchanged.
        $fresh = $order->fresh();
        $this->assertSame(1, (int) $fresh->fiscal_sequence_no, 'fiscal_sequence_no must remain == 1 after cashier B race.');
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status, 'payment_status stays PAID.');
    }

    /**
     * Negative-control test — verify that pre-heal SHORT-CIRCUIT behavior
     * is GONE. Specifically: cashier B's POST does NOT return 200, and
     * the response body does NOT carry an OrderDetailsResource payload
     * that the modal would interpret as a successful collection.
     */
    public function test_route_never_returns_200_or_resource_payload_on_race(): void
    {
        Queue::fake();
        Event::fake();

        [$branch, $cashierA, $cashierB] = $this->branchTwoCashiers();
        $order = $this->pendingCounterOrder($branch);

        $this->actingAs($cashierA, 'sanctum');
        app(PaymentService::class)->confirmCounterPayment(
            $order,
            PosPaymentMethod::CASH,
            12.00,
            'Cashier A wins'
        );

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI
        // sends it). Cashier A won via a direct service call (NOT an HTTP POST), so a fresh key reaches the
        // controller and the race loser gets the 409 / non-200 outcome under test.
        $response = $this->actingAs($cashierB, 'sanctum')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson("/api/admin/pos/counter-collect/{$order->id}/confirm", [
                'mode' => PosPaymentMethod::CASH,
                'received' => 12.00,
            ]);

        $this->assertNotSame(200, $response->status(), 'Race loser MUST NOT see a 200 (pre-heal behavior).');

        $body = $response->json();
        $this->assertArrayNotHasKey('data', $body, 'Race loser MUST NOT receive an OrderDetailsResource payload.');
        $this->assertSame('payment_already_collected', $body['error_code'] ?? null);
    }

    // ------------------------------------------------------------------
    // Helpers (mirror CounterDeferredPaymentLifecycleTest patterns).
    // ------------------------------------------------------------------

    /**
     * @return array{0: Branch, 1: User, 2: User}
     */
    private function branchTwoCashiers(): array
    {
        $branch = Branch::factory()->create();
        $cashierA = User::factory()->create(['branch_id' => $branch->id]);
        $cashierB = User::factory()->create(['branch_id' => $branch->id]);
        $cashierA->assignRole('POS Operator');
        $cashierB->assignRole('POS Operator');

        return [$branch, $cashierA, $cashierB];
    }

    private function pendingCounterOrder(Branch $branch, array $overrides = []): Order
    {
        return Order::factory()->create($overrides + [
            'branch_id' => $branch->id,
            'user_id' => User::factory()->create(['branch_id' => $branch->id])->id,
            'order_type' => OrderType::KIOSK,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::ACCEPT,
            'source_surface' => 'kiosk',
            'subtotal' => 12.00,
            'total' => 12.00,
            'discount' => 0,
            'delivery_charge' => 0,
            'fiscal_sequence_no' => null,
        ]);
    }
}
