<?php

namespace Tests\Feature\OSS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\OrderStatusScreenOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [ULTRA WAVE 3 latent → HEAL 2026-07-04] Parité board-release OSS↔KDS.
 *
 * Le KDS applique `KitchenReleaseRule::applyBoardReleaseFilter` (PAID | PENDING_COUNTER |
 * POS-cash) sur TOUS ses chemins (list, orderItems, sync, guard changeStatus) — SSOT
 * « visible == bumpable ». L'OSS ne l'appliquait PAS : une commande UNPAID non-cash forcée
 * en PREPARING (action admin) s'affichait « En préparation » au mur client alors que la
 * cuisine ne l'a jamais reçue. Ce test verrouille la parité : le mur client ne montre que
 * ce que la cuisine voit.
 */
class OssBoardReleaseParityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function makeOrder(Branch $branch, int $paymentStatus): Order
    {
        return Order::factory()->create([
            'branch_id'        => $branch->id,
            'status'           => OrderStatus::PREPARING,
            'payment_status'   => $paymentStatus,
            'order_type'       => OrderType::TAKEAWAY,
            'order_datetime'   => now(),
            'is_advance_order' => Ask::NO,
            'queue_number'     => 'P' . $paymentStatus,
        ]);
    }

    /** @test */
    public function le_mur_client_ne_montre_que_les_commandes_released_comme_la_cuisine(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);

        $paid    = $this->makeOrder($branch, PaymentStatus::PAID);            // released → visible
        $pending = $this->makeOrder($branch, PaymentStatus::PENDING_COUNTER); // released (Plan B) → visible
        $unpaid  = $this->makeOrder($branch, PaymentStatus::UNPAID);          // NON released → masqué

        $svc = app(OrderStatusScreenOrderService::class);

        $listIds = $svc->list()->pluck('id')->all();
        $this->assertContains($paid->id, $listIds, 'OSS list : PAID visible.');
        $this->assertContains($pending->id, $listIds, 'OSS list : PENDING_COUNTER (borne Plan B) visible.');
        $this->assertNotContains($unpaid->id, $listIds, 'OSS list : UNPAID non-cash NE DOIT PAS s\'afficher au mur client (la cuisine ne le voit pas).');

        $wallIds = $svc->listForBranch($branch->id)->pluck('id')->all();
        $this->assertContains($paid->id, $wallIds, 'OSS mur public : PAID visible.');
        $this->assertContains($pending->id, $wallIds, 'OSS mur public : PENDING_COUNTER visible.');
        $this->assertNotContains($unpaid->id, $wallIds, 'OSS mur public : UNPAID non-cash masqué (parité KDS).');
    }
}
