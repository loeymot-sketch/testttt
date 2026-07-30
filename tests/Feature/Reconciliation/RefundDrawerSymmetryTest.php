<?php

namespace Tests\Feature\Reconciliation;

use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Models\Branch;
use App\Models\CashMovement;
use App\Models\Order;
use App\Models\User;
use App\Services\Cash\CashDrawerService;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * [SYMÉTRIE-TIROIR HEAL 2026-07-30 · REFUND-NO-IN]
 * Audit adversaire « sortie commande site » 2026-07-30, finding P2 :
 * `confirmCounterPayment` pose `pos_payment_method=CASH` inconditionnellement, mais le
 * mouvement tiroir IN (`recordCashOrderMovement`) est best-effort → sauté s'il n'y a AUCUNE
 * session caisse OPEN à l'encaissement. La commande finit donc PAID SANS IN. Au remboursement,
 * le repli mono-tender (« sortir le total ») s'armait quand même sur `pos_payment_method===CASH`
 * → sortie tiroir SANS entrée appariée = variance négative au rapprochement (la « symétrie »
 * annoncée par CASH-01 n'était pas réellement implémentée sur ce chemin). Le fix gate ce repli
 * sur l'existence RÉELLE d'un IN (`PaymentService::hasRecordedCashIn`).
 */
class RefundDrawerSymmetryTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $cashier;

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
        $this->actingAs($this->cashier);
    }

    private function makeWebCounterOrder(): Order
    {
        return Order::factory()->create([
            'branch_id'          => $this->branch->id,
            'user_id'            => $this->cashier->id,
            'total'              => 12.50,
            'subtotal'           => 12.50,
            'discount'           => 0,
            'total_tax'          => 0,
            'delivery_charge'    => 0,
            'status'             => OrderStatus::PENDING,
            'payment_status'     => PaymentStatus::PENDING_COUNTER,
            'payment_method'     => PaymentGateway::CASH_ON_DELIVERY,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'source_surface'     => 'web',
        ]);
    }

    private function hasCashIn(int $orderId): bool
    {
        return CashMovement::withoutGlobalScopes()->where('order_id', $orderId)
            ->where('type', CashMovement::TYPE_ORDER_PAYMENT)
            ->where('direction', CashMovement::DIRECTION_IN)->exists();
    }

    private function cashbackOutCount(int $orderId): int
    {
        return CashMovement::withoutGlobalScopes()->where('order_id', $orderId)
            ->where('type', CashMovement::TYPE_CASHBACK)
            ->where('direction', CashMovement::DIRECTION_OUT)->count();
    }

    /**
     * @test
     * Encaissement cash HORS session (aucun IN enregistré) → le remboursement NE sort PAS
     * le tiroir (sinon variance : sortie sans entrée appariée).
     */
    public function refund_without_recorded_cash_in_does_not_move_drawer(): void
    {
        // Encaissement SANS session ouverte → PAID, mais recordCashOrderMovement best-effort = skip.
        $order = $this->makeWebCounterOrder();
        app(PaymentService::class)->confirmCounterPayment($order, PosPaymentMethod::CASH);
        $order->refresh();

        $this->assertSame(PaymentStatus::PAID, (int) $order->payment_status, 'Le flux d\'encaissement fonctionne.');
        $this->assertFalse($this->hasCashIn($order->id), 'Pré-condition : encaissement hors session = AUCUN mouvement IN.');

        // Une session EST ouverte au moment du refund → le writer OUT n'est pas bloqué par l'absence
        // de session : seule la garde de symétrie doit empêcher la sortie.
        app(CashDrawerService::class)->openSession($this->branch->id, $this->cashier->id, 100.00);

        app(PaymentService::class)->recordCashRefundMovement($order->fresh(), 12.50);

        $this->assertSame(0, $this->cashbackOutCount($order->id),
            'Sans IN apparié, le remboursement ne doit créer AUCUNE sortie tiroir.');
    }

    /**
     * @test
     * Encaissement cash EN session (IN enregistré) → le remboursement sort bien le tiroir
     * (comportement légitime inchangé : aucune régression sur le chemin nominal).
     */
    public function refund_with_recorded_cash_in_moves_drawer(): void
    {
        // Session ouverte AVANT l'encaissement → IN enregistré (cf. S3 ReconciliationFlowsE2ETest).
        app(CashDrawerService::class)->openSession($this->branch->id, $this->cashier->id, 100.00);
        $order = $this->makeWebCounterOrder();
        app(PaymentService::class)->confirmCounterPayment($order, PosPaymentMethod::CASH);
        $order->refresh();

        $this->assertTrue($this->hasCashIn($order->id), 'Pré-condition : encaissement en session = mouvement IN présent.');

        app(PaymentService::class)->recordCashRefundMovement($order->fresh(), 12.50);

        $this->assertSame(1, $this->cashbackOutCount($order->id),
            'Avec IN apparié, le remboursement doit créer exactement une sortie tiroir.');
    }
}
