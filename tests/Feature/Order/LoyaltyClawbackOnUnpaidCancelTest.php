<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Http\Requests\OrderStatusRequest;
use App\Models\Branch;
use App\Models\LoyaltyTransaction;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

/**
 * [TERRAIN-HEAL 2026-07-16 · LOYALTY-UNPAID-AWARD-CLAWBACK — P1 intersection KDS↔Loyalty↔Caisse]
 *
 * Une commande borne Plan B (PENDING_COUNTER / impayée) est board-released et créditée en points GAGNÉS
 * dès PREPARED (AwardLoyaltyPointsOnDelivery ne teste pas payment_status). Si elle est ensuite annulée SANS
 * avoir été payée, le clawback via RefundCreated (gardé sur PAID) ne se déclenche PAS → points gagnés jamais
 * repris = exploit répétable. Ce test verrouille : l'annulation d'une commande awarded IMPAYÉE reprend bien
 * les points GAGNÉS (via le clawback direct ajouté dans changeStatus, indépendant de RefundCreated).
 */
class LoyaltyClawbackOnUnpaidCancelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_cancel_of_unpaid_awarded_order_claws_back_earned_points(): void
    {
        $branch = Branch::factory()->create();

        // Caissier avec la permission de retour (ACCEPT→RETURNED exige pos-refund).
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('pos-refund', 'sanctum');
        $cashier->givePermissionTo('pos-refund');
        $this->actingAs($cashier, 'sanctum');
        Auth::setUser($cashier);

        // Client fidélité : solde 100 pts (crédités au passage PREPARED de la commande impayée).
        $customer = User::factory()->create([
            'loyalty_code'   => 'LC-CLAWTEST',
            'loyalty_points' => 100,
            'status'         => Status::ACTIVE,
        ]);

        // Commande borne Plan B : IMPAYÉE, mais déjà créditée (loyalty_points_awarded = 100).
        $order = Order::factory()->create([
            'branch_id'              => $branch->id,
            'status'                 => OrderStatus::ACCEPT, // ACCEPT→RETURNED autorisé (pos-refund)
            'payment_status'         => PaymentStatus::UNPAID, // impayée → RefundCreated NE se déclenche pas
            'fiscal_sequence_no'     => null,                  // non scellé → mutable
            'loyalty_customer_code'  => 'LC-CLAWTEST',
            'loyalty_points_awarded' => 100,                   // points GAGNÉS déjà crédités
        ]);

        $request = new OrderStatusRequest;
        $request->merge(['status' => OrderStatus::RETURNED, 'reason' => 'client parti sans payer']);
        app(OrderService::class)->changeStatus($order, $request, false);

        // Les points GAGNÉS doivent avoir été repris malgré l'absence de paiement.
        $this->assertSame(0, (int) $customer->fresh()->loyalty_points, 'Les 100 pts gagnés doivent être repris à l\'annulation d\'une commande impayée.');

        $this->assertDatabaseHas('loyalty_transactions', [
            'user_id'  => $customer->id,
            'order_id' => $order->id,
            'type'     => 'manual_deduct',
        ]);
    }

    public function test_clawback_is_idempotent_across_repeated_terminal_transitions(): void
    {
        $branch  = Branch::factory()->create();
        $cashier = User::factory()->create(['branch_id' => $branch->id]);
        Permission::findOrCreate('pos-refund', 'sanctum');
        $cashier->givePermissionTo('pos-refund');
        $this->actingAs($cashier, 'sanctum');
        Auth::setUser($cashier);

        $customer = User::factory()->create(['loyalty_code' => 'LC-IDEM', 'loyalty_points' => 100, 'status' => Status::ACTIVE]);
        $order = Order::factory()->create([
            'branch_id' => $branch->id, 'status' => OrderStatus::ACCEPT, 'payment_status' => PaymentStatus::UNPAID,
            'fiscal_sequence_no' => null, 'loyalty_customer_code' => 'LC-IDEM', 'loyalty_points_awarded' => 100,
        ]);

        $req = new OrderStatusRequest;
        $req->merge(['status' => OrderStatus::RETURNED, 'reason' => 'test']);
        app(OrderService::class)->changeStatus($order, $req, false);

        // Un seul manual_deduct malgré un solde déjà à 0 (idempotence via guard user+order).
        $this->assertSame(1, LoyaltyTransaction::where('order_id', $order->id)->where('type', 'manual_deduct')->count());
        $this->assertSame(0, (int) $customer->fresh()->loyalty_points);
    }
}
