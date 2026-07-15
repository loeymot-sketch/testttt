<?php

namespace Tests\Feature\Kds;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [F-KDS-KITCHEN-TICKET-AUTHZ 2026-07-15 / P1] GET admin/pos/orders/{order}/escpos est
 * appelé par l'auto-impression + la réimpression du KDS. Le rôle Chef (opérateur KDS désigné,
 * landing_url 'kitchen-display-system') n'a PAS `pos` → l'ancien abort_unless(can('pos'))
 * renvoyait 403 en boucle → auto-print/reprint cassés à 100 %. Le ticket CUISINE est lecture
 * seule (aucun incrément NF525) → autorisé aux détenteurs de `kitchen-display-system`. Le
 * ticket CLIENT (reçu fiscal) reste gardé sur `pos`.
 */
class KdsKitchenTicketAuthzTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function order(Branch $branch): Order
    {
        return Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'total' => 12.00,
        ]);
    }

    public function test_chef_can_read_kitchen_ticket_bytes(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef'); // dashboard, kitchen-display-system, order-status-screen — PAS pos
        $order = $this->order($branch);

        $this->actingAs($chef, 'sanctum')
            ->getJson("/api/admin/pos/orders/{$order->id}/escpos?ticket=kitchen")
            ->assertOk()
            ->assertJsonPath('ticket', 'kitchen');
    }

    public function test_chef_cannot_read_client_receipt_bytes(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');
        $order = $this->order($branch);

        // Le reçu CLIENT est le ticket fiscal → reste gardé sur `pos`.
        $this->actingAs($chef, 'sanctum')
            ->getJson("/api/admin/pos/orders/{$order->id}/escpos?ticket=client")
            ->assertStatus(403);
    }

    public function test_pos_operator_can_still_read_both_tickets(): void
    {
        $branch = Branch::factory()->create();
        $op = User::factory()->create(['branch_id' => $branch->id]);
        $op->assignRole('POS Operator');
        $order = $this->order($branch);

        $this->actingAs($op, 'sanctum')
            ->getJson("/api/admin/pos/orders/{$order->id}/escpos?ticket=kitchen")
            ->assertOk();
        $this->actingAs($op, 'sanctum')
            ->getJson("/api/admin/pos/orders/{$order->id}/escpos?ticket=client")
            ->assertOk();
    }

    public function test_chef_cannot_read_another_branch_kitchen_ticket(): void
    {
        $branchA = Branch::factory()->create();
        $branchB = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branchA->id]);
        $chef->assignRole('Chef');
        $orderB = $this->order($branchB);

        // Portée branche : le Chef de A ne lit pas une commande de B (firstOrFail scoped).
        $this->actingAs($chef, 'sanctum')
            ->getJson("/api/admin/pos/orders/{$orderB->id}/escpos?ticket=kitchen")
            ->assertStatus(404);
    }
}
