<?php

namespace Tests\Feature\Order;

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
 * [E2E stores · vague B · O-B6 · 2026-10-01] « Mes commandes » porte le numéro APPELÉ.
 *
 * L'écran client et le comptoir appellent « N°A0051 » (queue_number). L'application ouvre le suivi
 * d'une commande depuis la liste « Mes commandes » : sans ce champ, elle ne pouvait afficher que la
 * série, que personne n'appelle.
 */
class MesCommandesNumeroAppeleTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function la_liste_des_commandes_du_client_donne_le_numero_appele(): void
    {
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);
        $branch = Branch::factory()->create();
        $client = UserFactory::new()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);
        Order::factory()->create([
            'branch_id' => $branch->id,
            'user_id' => $client->id,
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::PENDING,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_datetime' => now(),
            'queue_number' => 'A0051',
        ]);

        $reponse = $this->actingAs($client)->withHeader('x-api-key', '123456')
            ->getJson('/api/frontend/order?paginate=0&order_column=id&order_by=desc');

        $reponse->assertOk();
        $this->assertSame('A0051', $reponse->json('data.0.queue_number'));
    }
}
