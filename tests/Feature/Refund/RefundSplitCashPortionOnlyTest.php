<?php

/**
 * [TERRAIN-HEAL 2026-07-16 · CAISSE-REFUND-SPLIT] Sur un remboursement pré-Z d'un paiement SPLIT
 * (cash + carte), le tiroir ne doit sortir QUE la portion CASH physique (order_payments.mode==CASH),
 * pas $order->total. Avant : cashBack sortait le total entier → sur-sortie tiroir = variance au Z.
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
use App\Models\Transaction;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class RefundSplitCashPortionOnlyTest extends TestCase
{
    use RefreshDatabase;

    public function test_split_refund_drawer_out_equals_cash_tranche_only(): void
    {
        $this->seedMinimalSettings();
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($cashier);

        $session = app(CashDrawerService::class)->openSession($branch->id, $cashier->id, 100.00);

        // Commande 10,00 € payée en SPLIT : 4,00 € cash + 6,00 € carte.
        $order = Order::factory()->create([
            'branch_id'       => $branch->id,
            'user_id'         => $cashier->id,
            'total'           => 10.00,
            'subtotal'        => 10.00,
            'discount'        => 0,
            'total_tax'       => 0,
            'delivery_charge' => 0,
            'payment_status'  => PaymentStatus::PAID,
            'status'          => OrderStatus::DELIVERED,
            'pos_payment_method' => PosPaymentMethod::CASH, // mono legacy = piège (le bug refund)
        ]);

        DB::table('order_payments')->insert([
            ['order_id' => $order->id, 'branch_id' => $branch->id, 'mode' => PosPaymentMethod::CASH, 'amount' => 4.00, 'tendered' => 4.00, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $order->id, 'branch_id' => $branch->id, 'mode' => PosPaymentMethod::CARD, 'amount' => 6.00, 'tendered' => null, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Transaction::create([
            'order_id' => $order->id, 'transaction_no' => 'TXN-INIT-SPLIT', 'amount' => 10.00,
            'payment_method' => 'cash', 'sign' => '+', 'type' => 'payment',
        ]);

        app(PaymentService::class)->cashBack($order, 'cash', 'TXN-CB-SPLIT');

        $out = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('order_id', $order->id)
            ->sum('amount');

        // Correct : 4,00 € (portion cash). Le bug sortait 10,00 € (total entier).
        $this->assertSame(4.0, round((float) $out, 2), 'Le tiroir doit sortir la portion CASH (4,00 €), pas le total (10,00 €).');
    }

    public function test_mono_cash_order_without_tranches_still_refunds_full_total(): void
    {
        $this->seedMinimalSettings();
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);

        $this->actingAs($cashier);

        $session = app(CashDrawerService::class)->openSession($branch->id, $cashier->id, 100.00);

        // Vente mono cash directe (posOrderStore) : PAS de lignes order_payments → repli sur le total.
        $order = Order::factory()->create([
            'branch_id' => $branch->id, 'user_id' => $cashier->id,
            'total' => 7.50, 'subtotal' => 7.50, 'discount' => 0, 'total_tax' => 0, 'delivery_charge' => 0,
            'payment_status' => PaymentStatus::PAID, 'status' => OrderStatus::DELIVERED,
        ]);
        // [PORTE OWNER `hasRecordedCashIn` RÉSOLUE 2026-08-07] Le garde du 30/07 (`662a846bc`) ne
        // porte QUE sur le REPLI mono-tender — d'où le fait que les variantes « split » de ce
        // fichier passent déjà : elles n'empruntent pas le repli. Ici, la vente mono legacy sans
        // tranche l'emprunte, et le repli exige qu'une ENTRÉE tiroir ait été enregistrée : sortir
        // le total sans entrée appariée produit une variance négative au rapprochement. La fixture
        // créait la commande PAID par factory sans passer par l'encaissement — cas que la
        // production ne produit pas quand une session est ouverte. On appelle la VRAIE méthode.
        app(PaymentService::class)->recordCashOrderMovement($order, 'encaissement especes (fixture = flux reel)');

        Transaction::create([
            'order_id' => $order->id, 'transaction_no' => 'TXN-MONO', 'amount' => 7.50,
            'payment_method' => 'cash', 'sign' => '+', 'type' => 'payment',
        ]);

        app(PaymentService::class)->cashBack($order, 'cash', 'TXN-CB-MONO');

        $out = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('order_id', $order->id)
            ->sum('amount');

        $this->assertSame(7.5, round((float) $out, 2), 'Vente mono cash sans tranches → tiroir = total (repli).');
    }

    /**
     * [MP-03 2026-07-22 · phantom cash-out] Le repli 'cash' était aveugle au cas « tranches PRÉSENTES
     * mais AUCUNE cash » : refundCashTranchePortion renvoie 0 aussi bien quand il n'y a pas de tranche
     * QUE quand il y a des tranches non-cash. Le repli `<=0 && gateway==='cash'` sortait alors le TOTAL
     * du tiroir alors qu'aucun cash n'a jamais été encaissé → sortie fantôme. Le repli ne doit s'armer
     * que sur une vente mono-tender (aucune ligne order_payments).
     */
    public function test_split_with_tranches_but_no_cash_does_not_phantom_out_drawer(): void
    {
        $this->seedMinimalSettings();
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($cashier);

        $session = app(CashDrawerService::class)->openSession($branch->id, $cashier->id, 100.00);

        // Vente 10,00 € payée SPLIT NON-cash : 6,00 € carte + 4,00 € mobile. pos_payment_method=CASH
        // (piège legacy : le champ dominant dit "cash" mais aucune tranche cash réelle).
        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $cashier->id,
            'total'              => 10.00,
            'subtotal'           => 10.00,
            'discount'           => 0,
            'total_tax'          => 0,
            'delivery_charge'    => 0,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'pos_payment_method' => PosPaymentMethod::CASH, // piège : dominant cash sans tranche cash
        ]);

        DB::table('order_payments')->insert([
            ['order_id' => $order->id, 'branch_id' => $branch->id, 'mode' => PosPaymentMethod::CARD, 'amount' => 6.00, 'tendered' => null, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['order_id' => $order->id, 'branch_id' => $branch->id, 'mode' => PosPaymentMethod::MOBILE_BANKING, 'amount' => 4.00, 'tendered' => null, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        Transaction::create([
            'order_id' => $order->id, 'transaction_no' => 'TXN-INIT-NOCASH', 'amount' => 10.00,
            'payment_method' => 'cash', 'sign' => '+', 'type' => 'payment',
        ]);

        app(PaymentService::class)->cashBack($order, 'cash', 'TXN-CB-NOCASH');

        $out = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('order_id', $order->id)
            ->sum('amount');

        // Aucune espèce jamais encaissée → RIEN ne doit sortir du tiroir (le bug sortait 10,00 €).
        $this->assertSame(0.0, round((float) $out, 2), 'Tranches non-cash → aucune sortie tiroir (pas de sortie fantôme du total).');
    }

    /**
     * [MP-03 twin] recordCashRefundMovement (chemin vente PAID sans Transaction, OrderService elseif)
     * partage la même cécité : son repli `pos_payment_method===CASH` s'armait sur des tranches
     * non-cash. Même garde : le repli ne s'arme que sans ligne order_payments.
     */
    public function test_record_cash_refund_movement_with_tranches_but_no_cash_does_not_phantom_out(): void
    {
        $this->seedMinimalSettings();
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        $this->actingAs($cashier);

        $session = app(CashDrawerService::class)->openSession($branch->id, $cashier->id, 100.00);

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $cashier->id,
            'total'              => 12.00,
            'subtotal'           => 12.00,
            'discount'           => 0,
            'total_tax'          => 0,
            'delivery_charge'    => 0,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'pos_payment_method' => PosPaymentMethod::CASH, // piège legacy identique
        ]);

        DB::table('order_payments')->insert([
            ['order_id' => $order->id, 'branch_id' => $branch->id, 'mode' => PosPaymentMethod::CARD, 'amount' => 12.00, 'tendered' => null, 'change_amount' => 0, 'created_at' => now(), 'updated_at' => now()],
        ]);

        app(PaymentService::class)->recordCashRefundMovement($order, round((float) $order->total, 2));

        $out = CashMovement::query()
            ->where('cash_drawer_session_id', $session->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('order_id', $order->id)
            ->sum('amount');

        $this->assertSame(0.0, round((float) $out, 2), 'Tranche unique carte → aucune sortie tiroir (pas de sortie fantôme).');
    }
}
