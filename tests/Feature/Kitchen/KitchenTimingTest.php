<?php

namespace Tests\Feature\Kitchen;

use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use Database\Factories\OrderFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [KITCHEN-TIMING 2026-07-03] Mesure du TEMPS RÉEL de préparation : au franchissement des statuts clés
 * (ACCEPT → PREPARING → PREPARED), l'horodatage correspondant est posé UNE fois. Socle de l'analytique
 * de productivité cuisine (temps de prépa réel = prepared_at − accepted_at, vs l'estimé preparation_time).
 *
 * PÉRIMÈTRE V1 : la pose se fait dans OrderService::changeStatus (le flux dominant — kiosk/livraison/
 * sur-place bumpés depuis le KDS, + l'auto-accept PENDING→ACCEPT). SUIVI (documenté, non couvert ici) :
 * les ventes POS directes créées d'emblée en PREPARING (auto_prepare_on_paid) court-circuitent
 * changeStatus → n'obtiennent que prepared_at ; stamper accepted_at/preparing_at à la création est un
 * complément à faire hors nuit (chemin de création = fiscal-critique, à ne pas toucher sans supervision).
 */
class KitchenTimingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function apiKey(): string
    {
        return config('app.api_key', env('MIX_API_KEY', 'test-api-key'));
    }

    private function admin(): User
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        return $admin;
    }

    /** Crée une commande valide (FK branch + user satisfaites) au statut donné. */
    private function makeOrder(int $status, array $extra = []): Order
    {
        $branch = Branch::factory()->create();
        $customer = User::factory()->create(['branch_id' => $branch->id]);
        return OrderFactory::new()->create(array_merge([
            'branch_id' => $branch->id,
            'user_id' => $customer->id,
            'status' => $status,
            'accepted_at' => null,
            'preparing_at' => null,
            'prepared_at' => null,
        ], $extra));
    }

    private function bump(User $admin, int $orderId, int $status)
    {
        return $this->actingAs($admin)
            ->withHeader('x-api-key', $this->apiKey())
            ->postJson('/api/admin/pos-order/change-status/' . $orderId, ['status' => $status]);
    }

    /** @test */
    public function les_horodatages_cuisine_sont_poses_au_franchissement_des_statuts(): void
    {
        $admin = $this->admin();
        $order = $this->makeOrder(OrderStatus::ACCEPT);

        // ACCEPT → PREPARING : preparing_at posé.
        $this->bump($admin, $order->id, OrderStatus::PREPARING)->assertStatus(200);
        $order->refresh();
        $this->assertNotNull($order->preparing_at, 'preparing_at doit être posé au passage PREPARING');
        $this->assertNull($order->prepared_at, 'prepared_at ne doit PAS encore être posé');

        // PREPARING → PREPARED : prepared_at posé, preparing_at inchangé.
        $preparingAt = $order->preparing_at;
        $this->bump($admin, $order->id, OrderStatus::PREPARED)->assertStatus(200);
        $order->refresh();
        $this->assertNotNull($order->prepared_at, 'prepared_at doit être posé au passage PREPARED');
        $this->assertEquals(
            $preparingAt->timestamp,
            $order->preparing_at->timestamp,
            'preparing_at ne doit pas être réécrit'
        );
        $this->assertTrue(
            $order->prepared_at->greaterThanOrEqualTo($order->preparing_at),
            'prepared_at doit être ≥ preparing_at (temps de cuisson mesurable ≥ 0)'
        );
    }

    /** @test */
    public function un_horodatage_deja_pose_survit_a_une_transition_ulterieure(): void
    {
        $admin = $this->admin();
        // Commande déjà « acceptée » il y a 30 min (accepted_at pré-posé), au statut ACCEPT.
        $fixed = now()->subMinutes(30);
        $order = $this->makeOrder(OrderStatus::ACCEPT, ['accepted_at' => $fixed]);

        // Transition VALIDE ACCEPT → PREPARING : pose preparing_at, NE touche PAS accepted_at déjà posé
        // (first-write-wins + indépendance des horodatages).
        $this->bump($admin, $order->id, OrderStatus::PREPARING)->assertStatus(200);
        $order->refresh();

        $this->assertEquals(
            $fixed->timestamp,
            $order->accepted_at->timestamp,
            'un horodatage déjà posé (accepted_at) ne doit jamais être écrasé par une transition ultérieure'
        );
        $this->assertNotNull($order->preparing_at, 'preparing_at doit être posé au passage PREPARING');
    }
}
