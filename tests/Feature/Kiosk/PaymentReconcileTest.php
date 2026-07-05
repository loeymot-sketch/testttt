<?php

namespace Tests\Feature\Kiosk;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * @FK-ID F-008 — Payment Confirm Reconciliation Queue
 *
 * @source plans/PLAN_AUDIT_F008_PAYMENT_CONFIRM_RECONCILE_2026-05-07.md
 *
 * @sprint S4
 *
 * @severity P1 — TPE encaisse mais backend pas synchronisé → orders orphelins PENDING
 *
 * Scénario : TPE approuve cash débité, frontend retry 3× backoff 700ms,
 * backend down → frontend abandonne → order PENDING orphelin → client paie
 * mais commande pas en cuisine.
 *
 * Mécanisme F-008 : frontend persiste TPE result en localStorage avant
 * confirmBackendPayment, retry au boot via endpoint batch idempotent
 * `/api/frontend/payment/reconcile-pending`.
 *
 * Invariants :
 *  - Idempotent par transaction_id (UNIQUE pending_payment_confirmations)
 *  - Réutilise guard F-002 (amount echo) → aucun bypass de l'invariant TPE-amount
 *  - Réutilise allocation fiscal_sequence_no via finalizePaidKioskOrder
 *  - Throttle 5/min anti-abuse
 */
class PaymentReconcileTest extends TestCase
{
    use RefreshDatabase;

    public function test_reconcile_endpoint_marks_unpaid_order_as_paid(): void
    {
        $ctx = $this->createKioskContext();
        $order = $this->createKioskCardOrder($ctx, 50.00);

        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id' => $order->id,
                'transaction_id' => 'TX-RECONCILE-'.uniqid(),
                'amount_cents' => 5000,
                'card_type' => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertCount(1, $body['data']);
        $this->assertEquals('reconciled', $body['data'][0]['status']);
        $fresh = $order->fresh();
        $this->assertEquals(
            PaymentStatus::PAID,
            $fresh->payment_status,
            'Reconcile must promote order to PAID.'
        );
        $this->assertNotNull($body['data'][0]['transaction_id'] ?? null);
        // [AUDIT-F-001] AC1 invariant : reconcile success must trigger
        // fiscal_sequence_no allocation via finalizePaidKioskOrder delegation.
        // Without this assertion the report's "F-001 invariant preserved" claim
        // is unprotected by runtime evidence (only structurally protected by
        // the controller's `finalizePaidKioskOrder()` call site).
        $this->assertNotNull(
            $fresh->fiscal_sequence_no,
            'F-001 invariant: reconcile success must allocate fiscal_sequence_no via finalizePaidKioskOrder.'
        );
    }

    public function test_reconcile_is_idempotent_on_already_paid_order(): void
    {
        $ctx = $this->createKioskContext();
        $order = $this->createKioskCardOrder($ctx, 50.00);
        $order->payment_status = PaymentStatus::PAID;
        $order->transaction_id = 'TX-PRE-PAID';
        $order->save();

        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id' => $order->id,
                'transaction_id' => 'TX-PRE-PAID',
                'amount_cents' => 5000,
                'card_type' => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertEquals('already_paid', $body['data'][0]['status']);
    }

    public function test_reconcile_rejects_amount_mismatch(): void
    {
        $ctx = $this->createKioskContext();
        $order = $this->createKioskCardOrder($ctx, 50.00);

        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id' => $order->id,
                'transaction_id' => 'TX-WRONG',
                'amount_cents' => 100,
                'card_type' => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);

        $response->assertStatus(200);
        $body = $response->json();
        $this->assertEquals('amount_mismatch', $body['data'][0]['status']);
        $this->assertEquals(
            PaymentStatus::UNPAID,
            $order->fresh()->payment_status,
            'Mismatch must NOT promote order — F-002 invariant preserved.'
        );
    }

    public function test_reconcile_processes_batch_partially(): void
    {
        $ctx = $this->createKioskContext();
        $okOrder = $this->createKioskCardOrder($ctx, 30.00);
        $missingId = 999999;

        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [
                [
                    'order_id' => $okOrder->id,
                    'transaction_id' => 'TX-BATCH-A',
                    'amount_cents' => 3000,
                    'card_type' => 'VISA',
                    'payment_method' => PaymentGateway::CARD,
                ],
                [
                    'order_id' => $missingId,
                    'transaction_id' => 'TX-BATCH-B',
                    'amount_cents' => 5000,
                    'card_type' => 'VISA',
                    'payment_method' => PaymentGateway::CARD,
                ],
            ],
        ]);

        $response->assertStatus(200);
        $results = $response->json('data');
        $this->assertEquals('reconciled', $results[0]['status']);
        $this->assertEquals('order_not_found', $results[1]['status']);
    }

    public function test_reconcile_persists_pending_payment_confirmation_row(): void
    {
        $ctx = $this->createKioskContext();
        $order = $this->createKioskCardOrder($ctx, 50.00);
        $txId = 'TX-AUDIT-ROW';

        $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id' => $order->id,
                'transaction_id' => $txId,
                'amount_cents' => 5000,
                'card_type' => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ])->assertStatus(200);

        $row = DB::table('pending_payment_confirmations')
            ->where('transaction_id', $txId)
            ->first();

        $this->assertNotNull($row, 'Reconcile must persist audit row in pending_payment_confirmations.');
        $this->assertEquals('resolved', $row->status);
        $this->assertEquals($order->id, (int) $row->order_id);
        $this->assertNotNull($row->resolved_at);
    }

    public function test_reconcile_unique_transaction_id_idempotency(): void
    {
        $ctx = $this->createKioskContext();
        $order = $this->createKioskCardOrder($ctx, 50.00);
        $txId = 'TX-UNIQUE-'.uniqid();

        // First call → row created (resolved)
        $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id' => $order->id,
                'transaction_id' => $txId,
                'amount_cents' => 5000,
                'card_type' => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ])->assertStatus(200);

        // Second call same tx → must NOT throw (UNIQUE constraint), must return already_paid
        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id' => $order->id,
                'transaction_id' => $txId,
                'amount_cents' => 5000,
                'card_type' => 'VISA',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);

        $response->assertStatus(200);
        $this->assertEquals('already_paid', $response->json('data.0.status'));

        $count = DB::table('pending_payment_confirmations')
            ->where('transaction_id', $txId)
            ->count();
        $this->assertEquals(1, $count, 'UNIQUE(transaction_id) must enforce one audit row per tx.');
    }

    public function test_reconcile_validates_payload_structure(): void
    {
        $this->createKioskContext();

        // Empty entries → 422
        $this->postJson('/api/frontend/payment/reconcile-pending', ['entries' => []])
            ->assertStatus(422);

        // Missing transaction_id → 422
        $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id' => 1,
                'amount_cents' => 5000,
                'payment_method' => PaymentGateway::CARD,
            ]],
        ])->assertStatus(422);

        // Missing amount_cents → 422
        $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id' => 1,
                'transaction_id' => 'TX',
                'payment_method' => PaymentGateway::CARD,
            ]],
        ])->assertStatus(422);
    }

    public function test_reconcile_requires_authentication(): void
    {
        // No Sanctum::actingAs — should reject 401
        $response = $this->postJson('/api/frontend/payment/reconcile-pending', [
            'entries' => [[
                'order_id' => 1,
                'transaction_id' => 'TX',
                'amount_cents' => 5000,
                'payment_method' => PaymentGateway::CARD,
            ]],
        ]);
        // 401 (no token) ; the auth:sanctum middleware enforces it.
        $this->assertContains($response->status(), [401, 403]);
    }

    /**
     * [SELF-AUDIT R3 P2 2026-07-05] orders.transaction_id n'a PAS d'index UNIQUE → deux commandes
     * réconciliées avec le MÊME transaction_id TPE étaient TOUTES DEUX scellées PAID + fiscal (2 numéros
     * fiscaux pour 1 encaissement réel). Ce test verrouille le refus de la 2e (miroir /payment-confirm).
     */
    public function test_reconcile_rejects_reusing_a_transaction_id_across_two_orders(): void
    {
        $ctx = $this->createKioskContext();
        $a = $this->createKioskCardOrder($ctx, 9.90);
        $b = $this->createKioskCardOrder($ctx, 9.90);
        $tx = 'TPE-DUP-'.uniqid();

        // A scellée avec le tx.
        $this->postJson('/api/frontend/payment/reconcile-pending', ['entries' => [[
            'order_id' => $a->id, 'transaction_id' => $tx, 'amount_cents' => 990, 'card_type' => 'VISA', 'payment_method' => PaymentGateway::CARD,
        ]]])->assertStatus(200);

        // B avec le MÊME tx → doit être REFUSÉE (pas de 2e scellage fiscal).
        $res = $this->postJson('/api/frontend/payment/reconcile-pending', ['entries' => [[
            'order_id' => $b->id, 'transaction_id' => $tx, 'amount_cents' => 990, 'card_type' => 'VISA', 'payment_method' => PaymentGateway::CARD,
        ]]]);
        $res->assertStatus(200);
        $this->assertSame('tx_conflict', $res->json('data.0.status'), 'La 2e commande au même tx TPE doit être refusée.');

        $a->refresh();
        $b->refresh();
        $this->assertSame(PaymentStatus::PAID, (int) $a->payment_status, 'A scellée.');
        $this->assertNotNull($a->fiscal_sequence_no, 'A porte un numéro fiscal.');
        $this->assertNotSame(PaymentStatus::PAID, (int) $b->payment_status, 'B NON scellée.');
        $this->assertNull($b->fiscal_sequence_no, 'Aucun 2e numéro fiscal alloué depuis le même encaissement.');

        // La ligne d'audit de A (résolue) n'est PAS corrompue par le refus de B.
        $row = DB::table('pending_payment_confirmations')->where('transaction_id', $tx)->first();
        $this->assertNotNull($row);
        $this->assertSame('resolved', $row->status, 'L\'audit du tx légitime (A) reste RÉSOLU, pas écrasé en FAILED.');
        $this->assertSame((int) $a->id, (int) $row->order_id, 'L\'audit reste rattaché à la commande légitime A.');
    }

    /* ------------------------------------------------------------------ */
    /* Helpers */
    /* ------------------------------------------------------------------ */

    private function createKioskContext(): array
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        KioskMachine::query()->create([
            'name' => 'TEST-KIOSK-'.uniqid(),
            'machine_id' => 'KM-'.uniqid(),
            'username' => 'kiosk-test-'.uniqid(),
            'password' => 'pwd',
            'user_id' => $user->id,
            'branch_id' => $branch->id,
        ]);

        Sanctum::actingAs($user, ['kiosk:order']);

        return ['branch' => $branch, 'user' => $user];
    }

    private function createKioskCardOrder(array $ctx, float $total): FrontendOrder
    {
        return FrontendOrder::query()->create([
            'user_id' => $ctx['user']->id,
            'branch_id' => $ctx['branch']->id,
            'total' => $total,
            'subtotal' => $total,
            'discount' => 0,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => PaymentGateway::CARD,
            'status' => OrderStatus::PENDING,
            // [F-001 lock] order_type=KIOSK is required by finalizePaidKioskOrder
            // to gate fiscal_sequence_no auto-allocation. Without this, the
            // service short-circuits and the F-001 invariant cannot be asserted
            // by the AC1 test below.
            'order_type' => OrderType::KIOSK,
            'order_datetime' => now(),
            'preparation_time' => 15,
        ]);
    }
}
