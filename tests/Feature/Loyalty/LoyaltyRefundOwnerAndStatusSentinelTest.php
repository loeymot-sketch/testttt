<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\LoyaltyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [AUDIT FIDÉLITÉ 2026-08-01 — 2 P0 money-path prouvés par reproduction]
 *
 * P0-1 « points détruits » — ASYMÉTRIE DE STATUT. Le débit
 * ({@see \App\Services\Loyalty\PosRedemptionService}) accepte un compte legacy
 * `status=1` via un fallback explicite ; le remboursement, lui, ne cherchait
 * QUE `Status::ACTIVE`. Conséquence : un client legacy paie ses points, la
 * commande est annulée, et ses points ne reviennent JAMAIS (log warning, aucune
 * alerte). Reproduit : 500 pts → rachat 300 (remise 3,00 € appliquée) →
 * annulation → solde 200. 3,00 € détruits en silence.
 *
 * P0-2 « points volés au voisin » — ATTRIBUTION EN BLOC. `orders.loyalty_customer_code`
 * est ÉCRASÉ par le dernier code utilisé, et le remboursement sommait TOUTES les
 * lignes redeem de la commande pour les créditer à ce seul porteur. Deux codes sur
 * une même commande (atteignable en 2 clics en caisse) → le client A perd ses points
 * et le client B reçoit des points qui ne sont pas les siens.
 *
 * Invariant scellé ici : **chaque ligne redeem est remboursée à SON propre
 * porteur** (`loyalty_transactions.user_id` = source de vérité, jamais le code
 * écrasé sur la commande), et le remboursement accepte exactement les mêmes
 * statuts que le débit.
 */
class LoyaltyRefundOwnerAndStatusSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function makeCustomer(int $branchId, int $points, int $status): User
    {
        return \Database\Factories\UserFactory::new()->create([
            'branch_id'      => $branchId,
            'status'         => $status,
            'loyalty_code'   => strtoupper(substr(md5(uniqid((string) mt_rand(), true)), 0, 15)),
            'loyalty_points' => $points,
        ]);
    }

    private function makeOrder(int $branchId, int $staffId, string $customerCode): Order
    {
        return Order::create([
            'user_id'               => $staffId,
            'branch_id'             => $branchId,
            'order_type'            => 5,
            'order_serial_no'       => 'OWNSTAT-'.uniqid(),
            'subtotal'              => 20.00,
            'total'                 => 15.00,
            'discount'              => 5.00,
            'delivery_charge'       => 0,
            'status'                => OrderStatus::PENDING,
            'payment_method'        => 1,
            'payment_status'        => 1,
            'source'                => 1,
            'loyalty_customer_code' => $customerCode,
        ]);
    }

    private function ledgerRedeem(User $customer, Order $order, int $points): void
    {
        LoyaltyTransaction::create([
            'user_id'        => $customer->id,
            'loyalty_code'   => $customer->loyalty_code,
            'order_id'       => $order->id,
            'type'           => 'redeem',
            'points'         => -$points,
            'balance_after'  => $customer->loyalty_points,
            'source_surface' => 'pos',
            'description'    => 'Test redeem',
        ]);
    }

    /** P0-1 — un compte legacy status=1 doit être remboursé comme un compte ACTIVE. */
    public function test_legacy_status_customer_gets_points_back_on_cancel(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $staff  = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id, 'status' => \App\Enums\Status::ACTIVE]);
        $staff->assignRole('Admin');

        // Compte legacy : exactement celui que le DÉBIT accepte via son fallback.
        $customer = $this->makeCustomer($branch->id, 200, 1);
        $order    = $this->makeOrder($branch->id, $staff->id, $customer->loyalty_code);
        $this->ledgerRedeem($customer, $order, 300);

        (new LoyaltyService)->refundPoints($order, 'pos');

        $customer->refresh();
        $this->assertSame(500, (int) $customer->loyalty_points,
            'Un compte legacy (status=1) doit récupérer ses points : le débit l\'accepte, le remboursement DOIT l\'accepter aussi.');
    }

    /** P0-2 — deux codes sur une commande : chacun récupère EXACTEMENT ses points. */
    public function test_two_loyalty_codes_each_get_their_own_points_back(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $staff  = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id, 'status' => \App\Enums\Status::ACTIVE]);
        $staff->assignRole('Admin');

        $alice = $this->makeCustomer($branch->id, 0, \App\Enums\Status::ACTIVE);   // a dépensé 200
        $bob   = $this->makeCustomer($branch->id, 0, \App\Enums\Status::ACTIVE);   // a dépensé 100

        // La commande ne porte QUE le dernier code (comportement réel : écrasement).
        $order = $this->makeOrder($branch->id, $staff->id, $bob->loyalty_code);
        $this->ledgerRedeem($alice, $order, 200);
        $this->ledgerRedeem($bob, $order, 100);

        (new LoyaltyService)->refundPoints($order, 'pos');

        $alice->refresh();
        $bob->refresh();

        $this->assertSame(200, (int) $alice->loyalty_points,
            'Alice doit récupérer SES 200 points — même si la commande ne porte plus son code.');
        $this->assertSame(100, (int) $bob->loyalty_points,
            'Bob ne doit récupérer que SES 100 points — jamais ceux d\'Alice.');
    }

    /** Le remboursement reste idempotent par porteur (double annulation = 1 seul crédit). */
    public function test_refund_stays_idempotent_per_owner(): void
    {
        $branch = \Database\Factories\BranchFactory::new()->create();
        $staff  = \Database\Factories\UserFactory::new()->create(['branch_id' => $branch->id, 'status' => \App\Enums\Status::ACTIVE]);
        $staff->assignRole('Admin');

        $alice = $this->makeCustomer($branch->id, 0, \App\Enums\Status::ACTIVE);
        $bob   = $this->makeCustomer($branch->id, 0, \App\Enums\Status::ACTIVE);
        $order = $this->makeOrder($branch->id, $staff->id, $bob->loyalty_code);
        $this->ledgerRedeem($alice, $order, 200);
        $this->ledgerRedeem($bob, $order, 100);

        $service = new LoyaltyService;
        $service->refundPoints($order, 'pos');
        $service->refundPoints($order, 'pos');   // rejeu

        $this->assertSame(200, (int) $alice->fresh()->loyalty_points);
        $this->assertSame(100, (int) $bob->fresh()->loyalty_points);
        $this->assertSame(2, LoyaltyTransaction::where('order_id', $order->id)->where('type', 'manual_add')->count(),
            'Une seule ligne de reversal par porteur, même après rejeu.');
    }
}
