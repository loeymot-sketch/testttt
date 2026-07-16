<?php

/**
 * [TERRAIN-HEAL 2026-07-16 · CAISSE-REFUND-SPLIT-DIRECT] Régression du chemin DIRECT.
 *
 * Le fix CAISSE-REFUND-SPLIT (RefundSplitCashPortionOnlyTest) couvrait le chemin cashBack() — les
 * ventes AVEC une ligne Transaction (comptoir différé / borne Plan B). Une vente POS INLINE (posOrderStore)
 * n'a AUCUNE Transaction : son remboursement pré-Z passe par `OrderService` elseif → `recordCashRefundMovement`,
 * qui sortait $order->total. Sur un split cash+carte, seule la tranche CASH est entrée au tiroir à la vente
 * (SplitPaymentService::persistTranches) → sortir le total sur-sortait le tiroir de la portion CARTE.
 * Ce test verrouille les deux côtés du contrat sur le point d'entrée DIRECT (sans Transaction).
 *
 * @group refund
 * @group cash
 */

namespace Tests\Feature\Refund;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefundDirectPathSplitCashPortionTest extends TestCase
{
    use RefreshDatabase;

    /** Vente inline SPLIT (pas de Transaction) : le tiroir ne sort que la tranche CASH, pas le total. */
    public function test_direct_split_refund_drawer_out_equals_cash_tranche_only(): void
    {
        $this->seedMinimalSettings();
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($cashier);

        $session = app(CashDrawerService::class)->openSession($branch->id, $cashier->id, 100.00);

        // Commande inline 20,00 € en SPLIT : 12,00 € cash (dominant) + 8,00 € carte. AUCUNE Transaction.
        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $cashier->id,
            'total'              => 20.00,
            'subtotal'           => 20.00,
            'discount'           => 0,
            'total_tax'          => 0,
            'delivery_charge'    => 0,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'pos_payment_method' => PosPaymentMethod::CASH, // mode DOMINANT du split
        ]);

        DB::table('order_payments')->insert([
            ['order_id' => $order->id, 'branch_id' => $branch->id, 'mode' => PosPaymentMethod::CASH, 'amount' => 12.00, 'tendered' => 12.00, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $order->id, 'branch_id' => $branch->id, 'mode' => PosPaymentMethod::CARD, 'amount' => 8.00, 'tendered' => null, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Point d'entrée DIRECT réel (OrderService:2348 passe $order->total).
        app(PaymentService::class)->recordCashRefundMovement($order, round((float) $order->total, 2));

        $out = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('order_id', $order->id)
            ->sum('amount');

        // Correct : 12,00 € (portion cash entrée au tiroir). Le bug sortait 20,00 € → variance −8,00 € (portion carte).
        $this->assertSame(12.0, round((float) $out, 2), 'Chemin DIRECT : le tiroir doit sortir la portion CASH (12,00 €), pas le total (20,00 €).');
    }

    /** Vente inline mono-cash (pas de tranches order_payments) : repli sur le total. */
    public function test_direct_mono_cash_refund_falls_back_to_total(): void
    {
        $this->seedMinimalSettings();
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($cashier);

        $session = app(CashDrawerService::class)->openSession($branch->id, $cashier->id, 100.00);

        $order = Order::factory()->create([
            'branch_id' => $branch->id, 'user_id' => $cashier->id,
            'total' => 9.00, 'subtotal' => 9.00, 'discount' => 0, 'total_tax' => 0, 'delivery_charge' => 0,
            'payment_status' => PaymentStatus::PAID, 'status' => OrderStatus::DELIVERED,
            'pos_payment_method' => PosPaymentMethod::CASH,
        ]);
        // Pas de lignes order_payments → repli sur le montant passé (=total).

        app(PaymentService::class)->recordCashRefundMovement($order, round((float) $order->total, 2));

        $out = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('order_id', $order->id)
            ->sum('amount');

        $this->assertSame(9.0, round((float) $out, 2), 'Vente mono cash directe sans tranches → tiroir = total (repli).');
    }

    /**
     * [LOYALTY-REFUND-NONCASH] Vente mono-CARTE (aucune tranche cash, aucun cash au tiroir à la vente) :
     * recordCashRefundMovement ne doit poser AUCUN mouvement de tiroir — sinon on sortirait du cash
     * physiquement absent. (La méthode est désormais appelée pour TOUT tender PAID pour le clawback fidélité.)
     */
    public function test_direct_card_refund_records_no_cash_movement(): void
    {
        $this->seedMinimalSettings();
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($cashier);

        $session = app(CashDrawerService::class)->openSession($branch->id, $cashier->id, 100.00);

        $order = Order::factory()->create([
            'branch_id' => $branch->id, 'user_id' => $cashier->id,
            'total' => 15.00, 'subtotal' => 15.00, 'discount' => 0, 'total_tax' => 0, 'delivery_charge' => 0,
            'payment_status' => PaymentStatus::PAID, 'status' => OrderStatus::DELIVERED,
            'pos_payment_method' => PosPaymentMethod::CARD, // 100% carte → rien au tiroir
        ]);
        // Pas de lignes order_payments (vente inline mono-tender).

        app(PaymentService::class)->recordCashRefundMovement($order, round((float) $order->total, 2));

        $out = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('order_id', $order->id)
            ->count();

        $this->assertSame(0, $out, 'Vente carte : aucun cash au tiroir à la vente → aucun mouvement de sortie au refund.');
    }
}
