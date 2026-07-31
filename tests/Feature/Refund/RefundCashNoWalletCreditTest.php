<?php

/**
 * [MP-01 2026-07-22 · double-refund cash + avoir] Un remboursement ESPÈCES rend l'argent
 * PHYSIQUEMENT via le tiroir (CASHBACK/OUT). Créditer EN PLUS l'avoir wallet
 * (users.balance) = double remboursement du client (sortie tiroir + avoir cumulés).
 *
 * Contrat corrigé (owner-autorisé, remplace le "contrat préservé" escaladé de 2026-07-15) :
 *   - remboursement ESPÈCES  → sortie tiroir, PAS de crédit avoir.
 *   - remboursement NON-espèces (carte/en-ligne 'credit') → crédit avoir (l'avoir tient lieu
 *     de reversal TPE en V1 simulé), PAS de sortie tiroir.
 *
 * @group refund
 * @group cash
 * @group money-path
 */

namespace Tests\Feature\Refund;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\Fiscal\AuditLogService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RefundCashNoWalletCreditTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // No-op audit chain (we assert refund side-effects, not the HMAC head).
        $this->app->instance(AuditLogService::class, new class extends AuditLogService {
            public function __construct() {}

            public function write(array $data): \App\Models\AuditLog
            {
                return new \App\Models\AuditLog();
            }
        });
    }

    /**
     * @test — ESPÈCES : sortie tiroir = total, avoir wallet INCHANGÉ (pas de double remboursement).
     */
    public function cash_refund_records_drawer_out_and_does_not_credit_wallet(): void
    {
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($cashier);

        $session  = app(CashDrawerService::class)->openSession($branch->id, $cashier->id, 100.00);
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0.0]);

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $customer->id,
            'total'              => 9.00,
            'subtotal'           => 9.00,
            'discount'           => 0,
            'total_tax'          => 0,
            'delivery_charge'    => 0,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'pos_payment_method' => PosPaymentMethod::CASH,
        ]);
        Transaction::create([
            'order_id' => $order->id, 'transaction_no' => 'TXN-INIT-MP01-CASH', 'amount' => 9.00,
            'payment_method' => 'cash', 'sign' => '+', 'type' => 'payment',
        ]);

        // [SUPERVISOR A5 2026-07-31] Semer l'ENTRÉE cash (le vrai encaissement en produit une via
        // recordCashOrderMovement). La garde hasRecordedCashIn (REFUND-NO-IN 2026-07-30) n'émet le OUT de
        // remboursement QUE si une entrée existe ; la factory ne créait pas ce IN → le OUT était
        // légitimement supprimé (test rouge PÉRIMÉ, précédait la garde). Setup désormais représentatif.
        app(CashDrawerService::class)->recordMovement(
            $session->id,
            CashMovement::TYPE_ORDER_PAYMENT,
            9.00,
            CashMovement::DIRECTION_IN,
            $order->id,
        );

        app(PaymentService::class)->cashBack($order, 'cash', 'TXN-CB-MP01-CASH');

        $out = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('order_id', $order->id)
            ->sum('amount');
        $this->assertSame(9.0, round((float) $out, 2), 'Le tiroir doit rendre les 9,00 € en espèces.');

        $this->assertEqualsWithDelta(
            0.0,
            (float) $customer->fresh()->balance,
            0.001,
            'Un remboursement ESPÈCES ne doit PAS créditer l\'avoir wallet (sinon double remboursement).'
        );
    }

    /**
     * @test — NON-espèces ('credit') : avoir wallet crédité (tient lieu de reversal TPE), AUCUNE
     * sortie tiroir. Le contrat avoir/carte est préservé.
     */
    public function credit_refund_credits_wallet_and_records_no_drawer_out(): void
    {
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($cashier);

        $session  = app(CashDrawerService::class)->openSession($branch->id, $cashier->id, 100.00);
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0.0]);

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $customer->id,
            'total'              => 9.00,
            'subtotal'           => 9.00,
            'discount'           => 0,
            'total_tax'          => 0,
            'delivery_charge'    => 0,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'pos_payment_method' => PosPaymentMethod::CARD,
        ]);
        Transaction::create([
            'order_id' => $order->id, 'transaction_no' => 'TXN-INIT-MP01-CREDIT', 'amount' => 9.00,
            'payment_method' => 'card', 'sign' => '+', 'type' => 'payment',
        ]);

        app(PaymentService::class)->cashBack($order, 'credit', 'TXN-CB-MP01-CREDIT');

        $this->assertEqualsWithDelta(
            9.0,
            (float) $customer->fresh()->balance,
            0.001,
            'Un remboursement carte/en-ligne crédite l\'avoir wallet (reversal TPE simulé).'
        );

        $this->assertSame(
            0,
            CashMovement::query()
                ->where('cash_drawer_session_id', $session->id)
                ->where('order_id', $order->id)
                ->where('direction', CashMovement::DIRECTION_OUT)
                ->count(),
            'Un remboursement NON-espèces ne sort JAMAIS d\'argent du tiroir.'
        );
    }
}
