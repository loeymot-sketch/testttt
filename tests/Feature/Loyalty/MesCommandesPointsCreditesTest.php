<?php

namespace Tests\Feature\Loyalty;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [POINTS À LA VALIDATION 2026-11-06] « Mes commandes » reçoit, pour chaque commande, les points
 * RÉELLEMENT crédités (`loyalty_points_awarded`, 0 tant que rien ne l'est). La sentinelle
 * « crédit en cours » (-1) ne sort jamais : le client ne voit pas de nombre négatif.
 */
class MesCommandesPointsCreditesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);
    }

    public function test_chaque_commande_porte_les_points_reellement_credites(): void
    {
        $branch = Branch::factory()->create();
        $client = UserFactory::new()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);
        $base = ['branch_id' => $branch->id, 'user_id' => $client->id, 'order_type' => OrderType::TAKEAWAY,
            'payment_status' => PaymentStatus::PAID, 'order_datetime' => now()];

        $creditee = Order::factory()->create($base + ['status' => OrderStatus::ACCEPT, 'loyalty_points_awarded' => 124]);
        $enAttente = Order::factory()->create($base + ['status' => OrderStatus::PENDING, 'loyalty_points_awarded' => null]);
        $enCours = Order::factory()->create($base + ['status' => OrderStatus::PREPARING, 'loyalty_points_awarded' => -1]);
        $retournee = Order::factory()->create($base + ['status' => OrderStatus::RETURNED, 'loyalty_points_awarded' => 300]);

        $rows = collect($this->actingAs($client)->withHeader('x-api-key', '123456')
            ->getJson('/api/frontend/order?limit=50')->assertOk()->json('data'))->keyBy('id');

        $this->assertSame(124, $rows[$creditee->id]['loyalty_points_awarded']);
        $this->assertSame(0, $rows[$enAttente->id]['loyalty_points_awarded']);
        $this->assertSame(0, $rows[$enCours->id]['loyalty_points_awarded'], 'la sentinelle -1 ne sort jamais');
        $this->assertSame(OrderStatus::PENDING, (int) $rows[$enAttente->id]['status']);
        $this->assertSame(0, $rows[$retournee->id]['loyalty_points_awarded'], 'retournée : points repris, aucun gain affiché');
    }
}
