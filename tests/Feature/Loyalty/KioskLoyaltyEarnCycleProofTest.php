<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Events\OrderStatusChanged;
use App\Listeners\AwardLoyaltyPointsOnDelivery;
use App\Models\Branch;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [LOYALTY-PROOF 2026-06-28] Preuve e2e du scénario owner : « le client met son
 * numéro / code de fidélité et il a des points ». Cycle complet EARN :
 * commande borne → PREPARED → points attribués au client + ledger + idempotence.
 */
class KioskLoyaltyEarnCycleProofTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(): User
    {
        return User::factory()->create([
            'loyalty_code' => 'CUST1234', 'loyalty_points' => 0, 'status' => 1, 'phone' => '+33611223344',
        ]);
    }

    private function makeKioskOrder(User $customer): Order
    {
        return Order::factory()->create([
            'branch_id' => Branch::factory()->create()->id,
            'user_id' => $customer->id,
            'order_type' => OrderType::KIOSK,
            'total' => 10.00,
            'loyalty_customer_code' => 'CUST1234',
            'status' => OrderStatus::ACCEPT,
            'loyalty_points_awarded' => null,
        ]);
    }

    public function test_kiosk_order_PREPARED_awards_points_to_customer_and_ledger(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeKioskOrder($customer);

        // La cuisine marque PRÊT → l'event d'attribution se déclenche.
        (new AwardLoyaltyPointsOnDelivery)->handle(
            new OrderStatusChanged($order->fresh(), OrderStatus::PREPARING, OrderStatus::PREPARED)
        );

        // 10 € × rate 10 = 100 points crédités au client (pas au compte machine).
        $this->assertSame(100, (int) $customer->fresh()->loyalty_points, 'points crédités au client');
        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id' => $customer->id, 'order_id' => $order->id, 'type' => 'earn', 'points' => 100,
        ]);
        $this->assertSame(100, (int) $order->fresh()->loyalty_points_awarded);
    }

    public function test_earn_is_idempotent_no_double_award_on_replay(): void
    {
        $customer = $this->makeCustomer();
        $order = $this->makeKioskOrder($customer);

        $listener = new AwardLoyaltyPointsOnDelivery;
        $listener->handle(new OrderStatusChanged($order->fresh(), OrderStatus::PREPARING, OrderStatus::PREPARED));
        $listener->handle(new OrderStatusChanged($order->fresh(), OrderStatus::PREPARING, OrderStatus::PREPARED)); // replay

        $this->assertSame(100, (int) $customer->fresh()->loyalty_points, 'pas de double attribution');
        $this->assertSame(1, LoyaltyTransaction::where('order_id', $order->id)->where('type', 'earn')->count());
    }
}
