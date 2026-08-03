<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCanceled;
use App\Events\RefundCreated;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [SELF-AUDIT R2 2026-07-05] Deux angles morts de OrderService::changeStatus(RETURNED) :
 *  - P3 #5 : RETURNED ne libérait le stock QUE via cashBack→RefundCreated (gardé sur $locked->transaction)
 *    → une commande décrémentée puis RETURNED en étant UNPAID/sans Transaction ne libérait NI stock NI
 *    disponibilité (déplétion fantôme). Fix = OrderCanceled dispatché inconditionnellement pour RETURNED.
 *  - P2 #3 : une vente POS cash DIRECTE (PAYÉE, entrée tiroir, AUCUNE Transaction) remboursée pré-Z ne
 *    posait AUCUNE sortie tiroir (cashBack sauté faute de Transaction) → variance fantôme. Fix = CASHBACK
 *    OUT directe quand cash + PAID.
 *
 * ACCEPT→RETURNED exige la permission `pos-refund` (OrderStateMachine::allows) → user gardé + acting sanctum.
 */
class ChangeStatusReturnedSelfAuditR2Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function actingRefundUser(int $branchId): User
    {
        $user = User::factory()->create(['branch_id' => $branchId]);
        Permission::findOrCreate('pos-refund', 'sanctum');
        $user->givePermissionTo('pos-refund');
        $this->actingAs($user, 'sanctum');
        Auth::setUser($user);

        return $user;
    }

    /** @test — P3 #5 : RETURNED déclenche la libération stock (OrderCanceled) même sans Transaction/paiement. */
    public function returned_transition_dispatches_order_canceled_for_stock_release(): void
    {
        $branch = Branch::factory()->create();
        $this->actingRefundUser($branch->id);
        Event::fake([OrderCanceled::class]);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::ACCEPT,   // ACCEPT→RETURNED autorisé (pos-refund)
            'payment_status' => PaymentStatus::UNPAID, // ni Transaction ni cashBack
            'fiscal_sequence_no' => null,                  // non scellé (pas de Z clos) → mutable
        ]);

        $request = new OrderStatusRequest;
        $request->merge(['status' => OrderStatus::RETURNED, 'reason' => 'retour staff']);
        app(OrderService::class)->changeStatus($order, $request, false);

        Event::assertDispatched(
            OrderCanceled::class,
            fn ($e) => (int) $e->order->id === (int) $order->id
        );
    }

    /** @test — P2 #3 : un retour cash DIRECT pré-Z pose une sortie tiroir (CASHBACK OUT = total). */
    public function pre_z_direct_cash_return_records_cashback_out_movement(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->actingRefundUser($branch->id);
        app(CashDrawerService::class)->openSession($branch->id, $user->id, 100.00);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,          // vente cash directe = payée
            'pos_payment_method' => PosPaymentMethod::CASH,
            'fiscal_sequence_no' => null,                          // non scellé → mutable pré-Z
            'total' => 18.00,
        ]);

        $request = new OrderStatusRequest;
        $request->merge(['status' => OrderStatus::RETURNED, 'reason' => 'retour cash pré-Z']);
        app(OrderService::class)->changeStatus($order, $request, false);

        $out = CashMovement::where('order_id', $order->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('direction', CashMovement::DIRECTION_OUT)
            ->first();
        $this->assertNotNull($out, 'Un retour cash direct pré-Z DOIT poser une sortie tiroir (sinon variance fantôme).');
        $this->assertEqualsWithDelta(18.00, (float) $out->amount, 0.01, 'La sortie vaut le total de la vente.');
    }

    /** @test — R4 P1 : un retour cash DIRECT (points GAGNÉS) dispatch RefundCreated → clawback fidélité. */
    public function pre_z_direct_cash_return_dispatches_refund_created_for_loyalty_clawback(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->actingRefundUser($branch->id);
        Event::fake([RefundCreated::class]);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'fiscal_sequence_no' => null,
            'total' => 30.00,
            'loyalty_points_awarded' => 300, // points gagnés à reprendre
        ]);

        $request = new OrderStatusRequest;
        $request->merge(['status' => OrderStatus::RETURNED, 'reason' => 'retour cash fidélité']);
        app(OrderService::class)->changeStatus($order, $request, false);

        // RefundCreated déclenche ClawbackLoyaltyPointsOnRefund (reprise des points gagnés) +
        // payment_status=REFUNDED. Sans lui : double-dip cash+points.
        Event::assertDispatched(RefundCreated::class, fn ($e) => (int) $e->order->id === (int) $order->id);
    }

    /** @test — garde : un retour UNPAID ne dispatch PAS RefundCreated (pas de REFUNDED erroné sur non-payé). */
    public function unpaid_return_does_not_dispatch_refund_created(): void
    {
        $branch = Branch::factory()->create();
        $this->actingRefundUser($branch->id);
        Event::fake([RefundCreated::class]);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::UNPAID,
            'fiscal_sequence_no' => null,
        ]);

        $request = new OrderStatusRequest;
        $request->merge(['status' => OrderStatus::RETURNED, 'reason' => 'retour unpaid']);
        app(OrderService::class)->changeStatus($order, $request, false);

        Event::assertNotDispatched(RefundCreated::class);
    }

    /** @test — garde : un retour d'une vente CARTE ne sort JAMAIS d'argent du tiroir. */
    public function pre_z_card_return_records_no_cashback_out(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->actingRefundUser($branch->id);
        app(CashDrawerService::class)->openSession($branch->id, $user->id, 100.00);

        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CARD,
            'fiscal_sequence_no' => null,
            'total' => 18.00,
        ]);

        $request = new OrderStatusRequest;
        $request->merge(['status' => OrderStatus::RETURNED, 'reason' => 'retour carte']);
        app(OrderService::class)->changeStatus($order, $request, false);

        $this->assertSame(
            0,
            CashMovement::where('order_id', $order->id)->where('direction', CashMovement::DIRECTION_OUT)->count(),
            'Un retour CARTE ne doit poser aucune sortie tiroir.'
        );
    }

    // ---------------------------------------------------------------------
    // [F-CASH-REFUND-DRAWER 2026-07-15 / P1] La vente cash COLLECTÉE AU COMPTOIR
    // (Plan B borne + walk-in différé) porte une ligne Transaction → le retour pré-Z
    // passait par cashBack('credit') → le garde tiroir 'cash' (heal 2026-07-11) ne
    // s'armait jamais → AUCUNE sortie tiroir + avoir wallet fantôme. Le slug doit
    // dériver de l'origine (pos_payment_method) : cash → 'cash' (sortie tiroir), sinon
    // 'credit' (carte/en-ligne, pas de tiroir). Sœur de pre_z_direct_cash (sans Txn).
    // ---------------------------------------------------------------------

    private function seedPaymentTransaction(Order $order, string $method): void
    {
        \App\Models\Transaction::create([
            'order_id'       => $order->id,
            'transaction_no' => 'TXN-INIT-'.$order->id,
            'amount'         => $order->total,
            'payment_method' => $method,
            'sign'           => '+',
            'type'           => 'payment',
        ]);
    }

    /** @test — cash COMPTOIR (avec Transaction) remboursé pré-Z pose bien une sortie tiroir. */
    public function pre_z_counter_collected_cash_return_records_cashback_out_movement(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->actingRefundUser($branch->id);
        app(CashDrawerService::class)->openSession($branch->id, $user->id, 100.00);

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $user->id,
            'status'             => OrderStatus::ACCEPT,
            'payment_status'     => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH, // collecté en espèces au comptoir
            'fiscal_sequence_no' => null,
            'total'              => 12.00,
        ]);
        $this->seedPaymentTransaction($order, 'counter_cash'); // ligne Transaction présente → chemin cashBack

        $request = new OrderStatusRequest;
        $request->merge(['status' => OrderStatus::RETURNED, 'reason' => 'retour cash comptoir pré-Z']);
        app(OrderService::class)->changeStatus($order, $request, false);

        $out = CashMovement::where('order_id', $order->id)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('direction', CashMovement::DIRECTION_OUT)
            ->first();
        $this->assertNotNull($out, 'Un retour cash comptoir (avec Transaction) DOIT poser une sortie tiroir.');
        $this->assertEqualsWithDelta(12.00, (float) $out->amount, 0.01);
        $this->assertSame(1, CashMovement::where('order_id', $order->id)->where('direction', CashMovement::DIRECTION_OUT)->count(),
            'Exactement UNE sortie tiroir (pas de double écriture avec le chemin post-Z).');
    }

    /**
     * @test — [MP-01 2026-07-22 · owner-autorisé] Un retour cash COMPTOIR (avec Transaction) pose la
     * sortie tiroir (l'argent est rendu PHYSIQUEMENT en espèces) et ne crédite PLUS l'avoir wallet —
     * sinon double remboursement (tiroir + avoir CUMULÉS). Remplace l'ancien « contrat préservé /
     * escaladé (gate owner) » : le fix est désormais appliqué. L'avoir reste non-fiscal (aucun impact
     * chaîne NF525) ; l'atomicité balance est prouvée par CashBackAtomicityTest via le chemin 'credit'.
     */
    public function pre_z_counter_cash_return_records_drawer_out_and_does_not_credit_wallet(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->actingRefundUser($branch->id);
        app(CashDrawerService::class)->openSession($branch->id, $user->id, 100.00);
        $customer = User::factory()->create(['branch_id' => $branch->id, 'balance' => 0.0]);

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $customer->id,
            'status'             => OrderStatus::ACCEPT,
            'payment_status'     => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CASH,
            'fiscal_sequence_no' => null,
            'total'              => 9.00,
        ]);
        $this->seedPaymentTransaction($order, 'counter_cash');

        $request = new OrderStatusRequest;
        $request->merge(['status' => OrderStatus::RETURNED, 'reason' => 'retour cash']);
        app(OrderService::class)->changeStatus($order, $request, false);

        // La sortie tiroir est bien posée (le vrai fix) …
        $this->assertSame(1, CashMovement::where('order_id', $order->id)
            ->where('type', CashMovement::TYPE_CASHBACK)->where('direction', CashMovement::DIRECTION_OUT)->count());
        // … et l'avoir wallet N'est PAS crédité (pas de double remboursement : le cash est déjà sorti).
        $this->assertEqualsWithDelta(0.0, (float) $customer->fresh()->balance, 0.001);
    }

    /** @test — garde : une vente CARTE avec Transaction ne sort JAMAIS d’argent du tiroir (préserve heal 2026-07-11). */
    public function pre_z_card_with_transaction_return_records_no_cashback_out(): void
    {
        $branch = Branch::factory()->create();
        $user = $this->actingRefundUser($branch->id);
        app(CashDrawerService::class)->openSession($branch->id, $user->id, 100.00);

        $order = Order::factory()->create([
            'branch_id'          => $branch->id,
            'user_id'            => $user->id,
            'status'             => OrderStatus::ACCEPT,
            'payment_status'     => PaymentStatus::PAID,
            'pos_payment_method' => PosPaymentMethod::CARD,
            'fiscal_sequence_no' => null,
            'total'              => 20.00,
        ]);
        $this->seedPaymentTransaction($order, 'counter_card');

        $request = new OrderStatusRequest;
        $request->merge(['status' => OrderStatus::RETURNED, 'reason' => 'retour carte avec txn']);
        app(OrderService::class)->changeStatus($order, $request, false);

        $this->assertSame(0,
            CashMovement::where('order_id', $order->id)->where('direction', CashMovement::DIRECTION_OUT)->count(),
            'Un retour CARTE (même avec Transaction) ne doit poser aucune sortie tiroir.');
    }
}
