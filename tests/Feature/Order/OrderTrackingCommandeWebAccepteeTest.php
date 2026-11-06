<?php

namespace Tests\Feature\Order;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Database\Factories\UserFactory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [E2E stores · vague B · 2026-10-01 · P0] Le suivi d'une commande WEB acceptée répondait 500.
 *
 * `FrontendOrder` (le modèle que sert la route du client) ne convertit pas `accepted_at` en
 * date, contrairement à `Order`. Dès que la caisse acceptait la commande avec un temps de
 * préparation, `OrderTrackingService::estimateFor` appelait `getTimestamp()` sur une CHAÎNE :
 * « Call to a member function getTimestamp() on string ». Mesuré en production : 53 erreurs
 * du 25 au 28/09 sur `GET /api/frontend/order/show/{id}`. Après trois échecs la page de suivi
 * s'arrête (« connexion perdue ») : le passage à « prête », la vibration et la notification de
 * l'application ne partent plus sans un « Réessayer ».
 *
 * Les tests de suivi existants passaient par `Order` — qui, lui, convertit la date. Celui-ci
 * passe par le modèle et la route que le client utilise réellement.
 */
class OrderTrackingCommandeWebAccepteeTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
        config(['app.api_key' => '123456']);
        $maintenant = CarbonImmutable::parse('2026-10-01 19:00:00', config('app.timezone'));
        Carbon::setTestNow($maintenant);
        CarbonImmutable::setTestNow($maintenant);
        $this->branch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function commandeAcceptee(?int $userId = null): Order
    {
        return Order::factory()->create([
            'branch_id' => $this->branch->id,
            'user_id' => $userId,
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PENDING_COUNTER,
            'order_datetime' => now()->subMinutes(6),
            'preparation_time' => 15,
            'accepted_at' => now()->subMinutes(5),
            // Temps choisi par le caissier (B2-R2-02) : c'est lui que le suivi décompte.
            'preparation_time_confirmed_at' => now()->subMinutes(5),
        ]);
    }

    /** @test */
    public function la_route_du_client_repond_pendant_la_preparation(): void
    {
        $client = UserFactory::new()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $commande = $this->commandeAcceptee($client->id);

        $reponse = $this->actingAs($client)->withHeader('x-api-key', '123456')
            ->getJson('/api/frontend/order/show/' . $commande->id);

        $reponse->assertOk();
        $this->assertSame(10, $reponse->json('tracking.wait_low'));
    }
}
