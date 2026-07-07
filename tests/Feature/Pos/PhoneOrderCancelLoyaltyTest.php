<?php

namespace Tests\Feature\Pos;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [C4-CAISSE-TELEPHONE FIX-1 / P2 2026-07-07] Symétrie remboursement fidélité sur
 * l'annulation INTERACTIVE d'une commande différée au comptoir (PaymentService::
 * cancelCounterPayment).
 *
 * Repro du bug (au HEAD avant fix) : une commande différée (borne Plan B / téléphone /
 * walk-in) créée avec un loyalty_code débite les points à la création (LoyaltyTransaction
 * type=redeem, points<0). Si le caissier l'ANNULE au comptoir (bouton « Annuler » de la
 * file à encaisser → cancelCounterPayment), l'ancien code posait payment_status=REFUNDED +
 * status=CANCELED + dispatch OrderCanceled (release stock/dispo SEULEMENT) SANS rembourser
 * les points → points redeem BRÛLÉS définitivement. Même forme d'asymétrie que C36 (cron de
 * purge) mais sur le chemin interactif.
 *
 * Ce test prouve :
 *   (a) annulation d'une commande téléphone différée avec redeem → points remboursés
 *       (ledger manual_add + users.loyalty_points restauré) ET commande CANCELED/REFUNDED ;
 *   (b) annulation d'une commande SANS fidélité → aucun remboursement parasite (no-op) ;
 *   (c) idempotence : une 2e annulation ne double-rembourse pas.
 */
class PhoneOrderCancelLoyaltyTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $operator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();

        $this->branch = Branch::factory()->create();

        $this->operator = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => Status::ACTIVE,
        ]);
        $this->operator->assignRole('POS Operator');
    }

    private function makeDeferredPhoneOrder(?string $loyaltyCode): Order
    {
        return Order::withoutGlobalScopes()->create([
            'order_serial_no' => 'PHONE-CANCEL-' . fake()->unique()->numerify('######'),
            'user_id' => $this->operator->id,
            'branch_id' => $this->branch->id,
            'subtotal' => 10,
            'discount' => 0,
            'delivery_charge' => 0,
            'total_tax' => 1,
            'total' => 11,
            'order_type' => OrderType::TAKEAWAY,
            'order_datetime' => now(),
            'preparation_time' => 15,
            'is_advance_order' => 0,
            // Marqueurs counter-deferred (identiques à une commande téléphone créée par posOrderStore).
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'pos_payment_method' => PosPaymentMethod::COUNTER_DEFERRED,
            'status' => OrderStatus::PREPARING,
            'source' => 1,
            'source_surface' => 'phone',
            'fiscal_sequence_no' => null,
            'loyalty_customer_code' => $loyaltyCode,
            'pos_customer_name' => 'Madame Durand',
            'pos_customer_phone' => '06 12 34 56 78',
        ]);
    }

    public function test_cancel_deferred_phone_order_with_redeem_refunds_loyalty_points(): void
    {
        $customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => Status::ACTIVE,
            'loyalty_code' => 'FK-PHONE-CANCEL-A',
            'loyalty_points' => 100,
        ]);

        $order = $this->makeDeferredPhoneOrder('FK-PHONE-CANCEL-A');

        // Ligne redeem écrite à la création (50 points utilisés, points négatifs).
        LoyaltyTransaction::create([
            'user_id' => $customer->id,
            'loyalty_code' => 'FK-PHONE-CANCEL-A',
            'order_id' => $order->id,
            'type' => 'redeem',
            'points' => -50,
            'balance_after' => 100,
            'source_surface' => 'phone',
            'description' => 'Redeem au checkout téléphone',
        ]);

        $this->actingAs($this->operator);
        app(PaymentService::class)->cancelCounterPayment($order, 'Client jamais venu');

        $fresh = $order->fresh();
        $this->assertSame(OrderStatus::CANCELED, (int) $fresh->status, 'la commande doit être annulée');
        $this->assertSame(PaymentStatus::REFUNDED, (int) $fresh->payment_status, 'payment_status doit passer REFUNDED');

        // Points restaurés (100 → 150).
        $this->assertSame(150, (int) $customer->fresh()->loyalty_points, 'le solde fidélité doit être re-crédité de 50 pts');

        // Reversal écrit dans le ledger.
        $refund = LoyaltyTransaction::where('order_id', $order->id)
            ->where('user_id', $customer->id)
            ->where('type', 'manual_add')
            ->first();
        $this->assertNotNull($refund, 'une ligne de remboursement (manual_add) doit exister');
        $this->assertSame(50, (int) $refund->points, 'le remboursement doit re-créditer 50 pts');
        // source_surface: 'phone' (non-kiosk) → tombe sur 'pos' comme changeStatus.
        $this->assertSame('pos', $refund->source_surface, 'source_surface du remboursement doit être pos pour une commande téléphone');
    }

    public function test_cancel_deferred_order_without_loyalty_is_noop_refund(): void
    {
        $order = $this->makeDeferredPhoneOrder(null);

        $this->actingAs($this->operator);
        app(PaymentService::class)->cancelCounterPayment($order, 'Annulation test');

        $this->assertSame(OrderStatus::CANCELED, (int) $order->fresh()->status);
        $this->assertSame(
            0,
            LoyaltyTransaction::where('order_id', $order->id)->count(),
            'aucune ligne fidélité pour une commande sans fidélité (pas de remboursement parasite)'
        );
    }

    public function test_double_cancel_does_not_double_refund(): void
    {
        $customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => Status::ACTIVE,
            'loyalty_code' => 'FK-PHONE-CANCEL-C',
            'loyalty_points' => 100,
        ]);

        $order = $this->makeDeferredPhoneOrder('FK-PHONE-CANCEL-C');
        LoyaltyTransaction::create([
            'user_id' => $customer->id,
            'loyalty_code' => 'FK-PHONE-CANCEL-C',
            'order_id' => $order->id,
            'type' => 'redeem',
            'points' => -50,
            'balance_after' => 100,
            'source_surface' => 'phone',
            'description' => 'Redeem au checkout téléphone',
        ]);

        $this->actingAs($this->operator);
        // 1re annulation : rembourse. 2e annulation : early-return (déjà REFUNDED) → pas de double-crédit.
        app(PaymentService::class)->cancelCounterPayment($order->fresh(), 'Annulation 1');
        app(PaymentService::class)->cancelCounterPayment($order->fresh(), 'Annulation 2');

        $this->assertSame(
            1,
            LoyaltyTransaction::where('order_id', $order->id)->where('type', 'manual_add')->count(),
            'le remboursement ne doit exister qu\'une seule fois'
        );
        $this->assertSame(150, (int) $customer->fresh()->loyalty_points, 'le solde ne doit être crédité qu\'une fois (150, pas 200)');
    }
}
