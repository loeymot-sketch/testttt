<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderStatusChanged;
use App\Events\RefundCreated;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use Database\Factories\BranchFactory;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [RED-CUMUL 2026-08-04] Asymétrie award/clawback : « la maison paie ».
 *  - P0 : clawbackEarnedPoints exigeait status=ACTIVE alors que l'award n'a AUCUN filtre de
 *    statut → un compte legacy (status=1) ou désactivé (status=10) gardait ses points au
 *    remboursement (miroir exact du P0-1 08-01 sur refundPointsToOwner, jamais corrigé ici).
 *  - P1 : la garde anti-award ne couvrait que CANCELED(16), pas REJECTED(19)/RETURNED(22) →
 *    un event DELIVERED différé arrivant après un remboursement créditait quand même.
 */
class LoyaltyEarnClawbackAsymmetrySentinelTest extends TestCase
{
    use RefreshDatabase;

    private function customer(int $status, int $balance = 0, string $code = 'CLAWX1'): User
    {
        $branch = BranchFactory::new()->create();

        return UserFactory::new()->create([
            'branch_id' => $branch->id,
            'status' => $status,
            'loyalty_code' => $code,
            'loyalty_points' => $balance,
        ]);
    }

    private function deliveredOrder(User $customer, int $awarded): Order
    {
        return Order::create([
            'user_id' => $customer->id,
            'branch_id' => $customer->branch_id,
            'order_type' => OrderType::POS,
            'order_serial_no' => 'CLAWA-'.uniqid(),
            'subtotal' => 30.00, 'total' => 30.00, 'discount' => 0, 'delivery_charge' => 0,
            'status' => OrderStatus::DELIVERED, 'payment_method' => 1, 'payment_status' => 5,
            'source' => 1, 'loyalty_customer_code' => $customer->loyalty_code,
            'loyalty_points_awarded' => $awarded,
        ]);
    }

    /** @dataProvider nonActiveStatuses */
    public function test_clawback_removes_points_even_on_non_active_account(int $status): void
    {
        $customer = $this->customer($status, 300, 'CLAW-'.$status);
        $order = $this->deliveredOrder($customer, 300);
        LoyaltyTransaction::create([
            'user_id' => $customer->id, 'loyalty_code' => $customer->loyalty_code, 'order_id' => $order->id,
            'type' => 'earn', 'points' => 300, 'balance_after' => 300, 'source_surface' => 'pos', 'description' => 'earn',
        ]);

        RefundCreated::dispatch($order->fresh());

        $this->assertSame(0, (int) $customer->fresh()->loyalty_points,
            "compte status=$status : les points cumulés DOIVENT être repris au remboursement (la maison ne paie pas)");
        $this->assertSame(1, LoyaltyTransaction::where('order_id', $order->id)->where('type', 'manual_deduct')->count());
    }

    public static function nonActiveStatuses(): array
    {
        return ['legacy status=1' => [1], 'désactivé status=10' => [10]];
    }

    /** @dataProvider terminalRefundedStatuses */
    public function test_award_never_credits_a_terminal_refunded_order(int $terminalStatus): void
    {
        $customer = $this->customer(\App\Enums\Status::ACTIVE, 0, 'TERM-'.$terminalStatus);
        // La commande est DÉJÀ dans un état terminal remboursé quand l'event DELIVERED différé arrive.
        $order = Order::create([
            'user_id' => $customer->id, 'branch_id' => $customer->branch_id, 'order_type' => OrderType::POS,
            'order_serial_no' => 'TERM-'.uniqid(), 'subtotal' => 30, 'total' => 30, 'discount' => 0, 'delivery_charge' => 0,
            'status' => $terminalStatus, 'payment_method' => 1, 'payment_status' => 5, 'source' => 1,
            'loyalty_customer_code' => $customer->loyalty_code, 'loyalty_points_awarded' => null,
        ]);

        (new AwardLoyaltyPointsOnDelivery)->handle(new OrderStatusChanged($order->fresh(), OrderStatus::PREPARED, OrderStatus::DELIVERED));

        $this->assertSame(0, (int) $customer->fresh()->loyalty_points,
            "statut terminal $terminalStatus : aucun point ne doit être crédité sur une commande remboursée");
        $this->assertNotSame(-1, (int) $order->fresh()->loyalty_points_awarded, 'sentinelle non posée sur une commande terminale');
    }

    public static function terminalRefundedStatuses(): array
    {
        return ['REJECTED' => [OrderStatus::REJECTED], 'RETURNED' => [OrderStatus::RETURNED]];
    }
}
