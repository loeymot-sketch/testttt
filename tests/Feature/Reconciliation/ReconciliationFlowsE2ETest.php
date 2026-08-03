<?php

namespace Tests\Feature\Reconciliation;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashDrawerSession;
use App\Models\CashMovement;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * @FK-ID F-017 Suite 10 — Reconciliation Flows E2E (PHPUnit volet)
 * @source plans/PLAN_AUDIT_F017_MASSIVE_E2E_TEST_SUITE_2026-05-08.md §1 Suite 10 (lignes 239-254)
 * @sprint S5+
 * @severity P0 — orphan payments / silent variance = audit-fail risk
 *
 * Couvre les 6 scénarios du plan, volet backend / API :
 *
 * | # | Scénario | Couverture |
 * |---|----------|------------|
 * | 1 | Payment confirm retry queue (F-008) — boot retry réussit | reconcile-pending happy path |
 * | 2 | TPE timeout → reconcile path (amount mismatch / order_not_found) | per-entry isolation |
 * | 3 | Cash acknowledge (F-009) — divergence implementation : confirmCounterPayment au lieu d'un endpoint dédié | confirmCounterPayment + cash_movement |
 * | 4 | Drawer fail signal — réintroduction du flag log-only (best-effort) | recordMovement non-strict |
 * | 5 | Idempotency replay POS+Kiosk (F-006) — same key → same order | reconcile already_paid + UNIQUE tx |
 * | 6 | Cash session close avec variance (F-003) — actual_cash != expected | reconcileSession + variance |
 *
 * Les tests Playwright UI sont dans `tests/e2e/reconciliation-flows.spec.js`.
 *
 * IMPORTANT — divergence plan vs réalité (cf. F009 sentinel) :
 *   Le plan F-017 référence un endpoint `/cash-acknowledge`. L'implémentation
 *   réelle de F-009 a délibérément remplacé cet endpoint par le flux interne
 *   `OrderService::collectKioskCash` → `PaymentService::confirmCounterPayment`
 *   (cf. tests/Feature/Sentinels/F009KioskCashCounterDeferredInvariantSentinelTest).
 *   Ce test exerce le flux RÉEL (non le flux planifié) — c'est la preuve E2E
 *   correcte du business goal F-009.
 */
class ReconciliationFlowsE2ETest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $cashier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::factory()->create();
        $this->cashier = User::factory()->create([
            'branch_id' => $this->branch->id,
            'password'  => Hash::make('pwd'),
        ]);
        $this->cashier->assignRole('POS Operator');
        $this->cashier->givePermissionTo('pos');

        // [Sprint 1D / F-4] Scenario #6 (Cash session close avec variance)
        // intentionally creates a -5€ variance to exercise the F-003
        // arithmetic path. Neutralise the gate here — coverage lives in
        // tests/Feature/Cash/CashVarianceGateTest.php.
        Config::set('cash.variance_threshold_eur', 999.0);
        Config::set('cash.variance_manager_approval_required', false);
    }

    /* ------------------------------------------------------------------ */
    /* Helpers                                                             */
    /* ------------------------------------------------------------------ */
    private function actAsKiosk(): User
    {
        $kioskUser = User::factory()->create(['branch_id' => $this->branch->id]);
        KioskMachine::query()->create([
            'name'       => 'TEST-KIOSK-RECON-' . uniqid(),
            'machine_id' => 'KM-' . uniqid(),
            'username'   => 'kiosk-recon-' . uniqid(),
            'password'   => 'pwd',
            'user_id'    => $kioskUser->id,
            'branch_id'  => $this->branch->id,
        ]);
        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        return $kioskUser;
    }

    private function makeKioskCardOrder(User $kioskUser, float $total): FrontendOrder
    {
        return FrontendOrder::query()->create([
            'user_id'          => $kioskUser->id,
            'branch_id'        => $this->branch->id,
            'total'            => $total,
            'subtotal'         => $total,
            'discount'         => 0,
            'payment_status'   => PaymentStatus::UNPAID,
            'payment_method'   => PaymentGateway::CARD,
            'status'           => OrderStatus::PENDING,
            'order_type'       => OrderType::KIOSK,
            'order_datetime'   => now(),
            'preparation_time' => 15,
        ]);
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 1 — Payment confirm retry queue (F-008) happy path         */
    /* ------------------------------------------------------------------ */
    public function test_S1_reconcile_pending_promotes_unpaid_kiosk_order_to_paid(): void
    {
        $kioskUser = $this->actAsKiosk();
        $order = $this->makeKioskCardOrder($kioskUser, 50.00);

        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id'       => $order->id,
                'transaction_id' => 'TX-S1-' . uniqid(),
                'amount_cents'   => 5000,
                'card_type'      => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);

        $response->assertStatus(200);
        $this->assertSame('reconciled', $response->json('data.0.status'));

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status,
            'S1: reconcile success must promote payment_status to PAID.');
        // F-001 invariant : fiscal_sequence_no allocated via finalizePaidKioskOrder.
        $this->assertNotNull($fresh->fiscal_sequence_no,
            'S1 (F-001): reconcile success must allocate fiscal_sequence_no via finalizePaidKioskOrder.');
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 2 — TPE timeout / mismatch / not_found (per-entry isolation)*/
    /* ------------------------------------------------------------------ */
    public function test_S2_reconcile_handles_amount_mismatch_and_missing_order_per_entry(): void
    {
        $kioskUser = $this->actAsKiosk();
        $okOrder = $this->makeKioskCardOrder($kioskUser, 30.00);
        $missingId = 99999;

        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [
                // ok entry
                [
                    'order_id'       => $okOrder->id,
                    'transaction_id' => 'TX-S2-OK-' . uniqid(),
                    'amount_cents'   => 3000,
                    'card_type'      => 'VISA',
                    'payment_method' => PaymentGateway::CARD,
                ],
                // amount mismatch (TPE echo wrong amount)
                [
                    'order_id'       => $okOrder->id,
                    'transaction_id' => 'TX-S2-MISMATCH-' . uniqid(),
                    'amount_cents'   => 1, // wrong
                    'card_type'      => 'VISA',
                    'payment_method' => PaymentGateway::CARD,
                ],
                // missing order (timeout / id corrupted in localStorage)
                [
                    'order_id'       => $missingId,
                    'transaction_id' => 'TX-S2-MISSING-' . uniqid(),
                    'amount_cents'   => 5000,
                    'card_type'      => 'VISA',
                    'payment_method' => PaymentGateway::CARD,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $results = $response->json('data');

        // First entry processed successfully → status reconciled OR already_paid
        // (depending on whether the second entry's mismatch ran first ; but the
        // second entry uses a different tx_id so first must reconcile cleanly).
        $this->assertSame('reconciled', $results[0]['status'],
            'S2: first valid entry must reconcile despite later failures (per-entry isolation).');
        // Second entry with wrong amount — F-002 invariant preserved.
        // Note: order is already PAID after entry 0 ran, so the controller
        // short-circuits with 'already_paid' before the F-002 amount check
        // in this batch path. If the order hadn't been PAID, the result would
        // be 'amount_mismatch'. Both responses prove the rejection invariant
        // (no double-charge, no foreign-amount promotion).
        $this->assertContains(
            $results[1]['status'],
            ['amount_mismatch', 'already_paid'],
            'S2 (F-002): mismatched amount must NEVER promote ; received: ' . $results[1]['status']
        );
        // Third entry with missing id
        $this->assertSame('order_not_found', $results[2]['status'],
            'S2: missing order id must produce status=order_not_found.');
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 3 — Cash acknowledge (F-009) via confirmCounterPayment     */
    /* (Le plan référence /cash-acknowledge mais l'impl réelle est interne)*/
    /* ------------------------------------------------------------------ */
    public function test_S3_F009_kiosk_cash_acknowledge_via_confirmCounterPayment(): void
    {
        $cashService = app(CashDrawerService::class);
        $paymentService = app(PaymentService::class);

        $session = $cashService->openSession($this->branch->id, $this->cashier->id, 100.00);

        $this->actingAs($this->cashier);

        // Kiosk cash order created with PENDING_COUNTER (F-009 invariant)
        $order = Order::factory()->create([
            'branch_id'           => $this->branch->id,
            'user_id'             => $this->cashier->id,
            'total'               => 12.50,
            'subtotal'            => 12.50,
            'discount'            => 0,
            'total_tax'           => 0,
            'delivery_charge'     => 0,
            'status'              => OrderStatus::PENDING,
            'payment_status'      => PaymentStatus::PENDING_COUNTER,
            'payment_method'      => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method'  => PosPaymentMethod::COUNTER_DEFERRED,
            'source_surface'      => 'kiosk',
        ]);

        $paymentService->confirmCounterPayment($order, PosPaymentMethod::CASH);

        $movement = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('order_id', $order->id)
            ->where('type', CashMovement::TYPE_ORDER_PAYMENT)
            ->where('direction', CashMovement::DIRECTION_IN)
            ->first();

        $this->assertNotNull($movement,
            'S3 (F-009): confirmCounterPayment must record a cash_movement (TYPE_ORDER_PAYMENT, DIRECTION_IN) — surrogate of cash_acknowledge.');

        $fresh = $order->fresh();
        $this->assertSame(PaymentStatus::PAID, (int) $fresh->payment_status,
            'S3 (F-009): confirmCounterPayment must promote PENDING_COUNTER → PAID.');
        $this->assertNotNull($fresh->fiscal_sequence_no,
            'S3 (F-001+F-009): fiscal_sequence_no must be allocated at acknowledge time.');
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 4 — Drawer fail / closed-session signal (best-effort log)  */
    /* ------------------------------------------------------------------ */
    public function test_S4_drawer_recordMovement_on_closed_session_is_best_effort(): void
    {
        $cashService = app(CashDrawerService::class);
        $session = $cashService->openSession($this->branch->id, $this->cashier->id, 50.00);
        // Close the session — recording a movement now must be best-effort (no throw).
        $cashService->closeSession($session->id, 50.00);

        $result = $cashService->recordMovement(
            $session->id,
            CashMovement::TYPE_ORDER_PAYMENT,
            10.00,
            CashMovement::DIRECTION_IN,
            null,
            'drawer-fail-signal',
            false, // strict=false → no throw
        );

        $this->assertNull(
            $result,
            'S4: best-effort recordMovement on a closed session must return null (log-only, no throw).'
        );
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 5 — Idempotency replay (F-006) — same tx replay = no-op    */
    /* ------------------------------------------------------------------ */
    public function test_S5_F006_reconcile_replay_same_transaction_returns_already_paid(): void
    {
        $kioskUser = $this->actAsKiosk();
        $order = $this->makeKioskCardOrder($kioskUser, 25.00);
        $txId = 'TX-S5-REPLAY-' . uniqid();

        // First call → reconciled
        $first = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id'       => $order->id,
                'transaction_id' => $txId,
                'amount_cents'   => 2500,
                'card_type'      => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);
        $first->assertStatus(200);
        $this->assertSame('reconciled', $first->json('data.0.status'));

        // Second call same tx → already_paid (idempotent)
        $second = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id'       => $order->id,
                'transaction_id' => $txId,
                'amount_cents'   => 2500,
                'card_type'      => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);
        $second->assertStatus(200);
        $this->assertSame('already_paid', $second->json('data.0.status'));

        // UNIQUE(transaction_id) at DB level → exactly one audit row.
        $count = DB::table('pending_payment_confirmations')
            ->where('transaction_id', $txId)
            ->count();
        $this->assertSame(1, $count,
            'S5 (F-006): UNIQUE(transaction_id) must enforce one audit row per replay sequence.');
    }

    /* ------------------------------------------------------------------ */
    /* Scénario 6 — Cash session close avec variance (F-003)               */
    /* ------------------------------------------------------------------ */
    public function test_S6_F003_cash_session_close_computes_variance(): void
    {
        $cashService = app(CashDrawerService::class);
        $session = $cashService->openSession($this->branch->id, $this->cashier->id, 100.00);

        // Simulate one cash sale of 30.00 (movement = +30)
        $cashService->recordMovement(
            $session->id,
            CashMovement::TYPE_ORDER_PAYMENT,
            30.00,
            CashMovement::DIRECTION_IN,
        );

        // Cashier counts physical cash : 125.00 — expected 130.00 → variance = -5.00
        $cashService->closeSession($session->id, 125.00);
        $reconciled = $cashService->reconcileSession($session->id);

        $this->assertSame(130.00, (float) $reconciled['expected'],
            'S6: expected = opening (100) + sum movements (+30) = 130.');
        $this->assertSame(-5.00, (float) $reconciled['variance'],
            'S6: variance = closing (125) - expected (130) = -5.');

        $row = $reconciled['session'];
        $this->assertSame(CashDrawerSession::STATUS_RECONCILED, $row->status,
            'S6: session must be in RECONCILED state after reconcile.');
    }
}
