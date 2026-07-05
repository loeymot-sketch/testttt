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
}
