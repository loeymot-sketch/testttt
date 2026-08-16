<?php

namespace Tests\Feature\Order;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\OrderTrackingService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [T-C SUIVI-CLIENT 2026-08-16 · GOAL owner] Suivi public d'une commande par
 * tracking_token — "le client pourra la suivre en temps réel depuis son
 * téléphone, puis que la commande était rentrée sur notre système, en cours,
 * jusqu'à la dernière étape... presque prête quand elle reste entre les 2
 * dernières commandes de la liste".
 */
class OrderTrackingTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456']);
        config(['kds.scheduled_lead_minutes' => 20]);

        $now = CarbonImmutable::parse('2026-03-10 12:00:00', 'Europe/Paris');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $this->branch = Branch::factory()->create();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function makeOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id' => $this->branch->id,
            'order_type' => OrderType::TAKEAWAY,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
            'scheduled_at' => null,
        ], $overrides));
    }

    private function track(string $token): array
    {
        return app(OrderTrackingService::class)->track($token);
    }

    /** @test */
    public function un_token_genere_automatiquement_a_la_creation_est_opaque_et_unique(): void
    {
        $a = $this->makeOrder();
        $b = $this->makeOrder();

        $this->assertNotEmpty($a->tracking_token);
        $this->assertNotEmpty($b->tracking_token);
        $this->assertNotEquals($a->tracking_token, $b->tracking_token);
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{48}$/', $a->tracking_token);
        // Jamais un simple id/token séquentiel devinable.
        $this->assertNotEquals((string) $a->id, $a->tracking_token);
    }

    /** @test */
    public function un_token_inconnu_renvoie_found_false_jamais_une_erreur(): void
    {
        $result = $this->track(str_repeat('x', 48));
        $this->assertFalse($result['found']);
    }

    /** @test */
    public function commande_acceptee_montre_l_etape_et_la_fourchette_de_temps(): void
    {
        $order = $this->makeOrder(['status' => OrderStatus::ACCEPT]);
        $result = $this->track($order->tracking_token);

        $this->assertTrue($result['found']);
        $this->assertSame(OrderStatus::ACCEPT, $result['status']);
        $this->assertFalse($result['ready']);
        $this->assertIsInt($result['wait_low']);
        $this->assertIsInt($result['wait_high']);
    }

    /** @test */
    public function commande_prete_ne_donne_plus_de_position_ni_de_fourchette(): void
    {
        $order = $this->makeOrder(['status' => OrderStatus::PREPARED]);
        $result = $this->track($order->tracking_token);

        $this->assertTrue($result['ready']);
        $this->assertNull($result['position_ahead']);
        $this->assertNull($result['wait_low']);
    }

    /** @test */
    public function almost_ready_quand_2_commandes_ou_moins_devant_dans_la_file(): void
    {
        // 3 commandes plus anciennes = devant. La dernière créée (la nôtre) a
        // donc 3 commandes devant — pas encore "bientôt prête" (seuil = 2).
        $earlier = [];
        for ($i = 0; $i < 3; $i++) {
            $earlier[] = $this->makeOrder(['order_datetime' => now()->subMinutes(10 - $i)]);
        }
        $mine = $this->makeOrder(['order_datetime' => now()]);

        $result = $this->track($mine->tracking_token);
        $this->assertSame(3, $result['position_ahead']);
        $this->assertFalse($result['almost_ready']);

        // On "sert" (remise au client) une commande plus ancienne → elle quitte la
        // file active. NB : PREPARED reste dans KitchenReleaseRule::visibleStatuses()
        // (toujours affichée au board tant qu'elle n'est pas remise) — seule DELIVERED
        // (statut générique "remise/terminée", pas seulement livraison à domicile)
        // sort réellement la commande du décompte "devant moi".
        $earlier[0]->update(['status' => OrderStatus::DELIVERED]);
        $result = $this->track($mine->tracking_token);
        $this->assertSame(2, $result['position_ahead']);
        $this->assertTrue($result['almost_ready'], 'avec 2 commandes ou moins devant, presque prête');
    }

    /** @test */
    public function commande_annulee_est_visible_mais_sans_illusion_de_file(): void
    {
        $order = $this->makeOrder(['status' => OrderStatus::CANCELED]);
        $result = $this->track($order->tracking_token);

        $this->assertTrue($result['found']);
        $this->assertSame('Annulée', $result['status_label']);
        $this->assertFalse($result['ready']);
        $this->assertNull($result['position_ahead']);
    }

    /** @test */
    public function endpoint_public_repond_200_avec_la_structure_attendue_et_est_throttle(): void
    {
        $order = $this->makeOrder();

        $response = $this->withHeader('x-api-key', '123456')
            ->getJson('/api/frontend/order/track/' . $order->tracking_token);

        $response->assertOk()->assertJsonStructure([
            'found', 'queue_number', 'status', 'status_label', 'step',
            'position_ahead', 'almost_ready', 'ready', 'wait_low', 'wait_high', 'server_time',
        ])->assertJson(['found' => true]);

        $route = app('router')->getRoutes()->getByName('frontend.order.track');
        $this->assertNotNull($route, 'Route nommée frontend.order.track absente.');
        $this->assertContains('throttle:30,1', $route->gatherMiddleware(),
            'Endpoint public order/track DOIT être throttled.');
    }

    /** @test */
    public function un_token_mal_forme_est_rejete_par_la_contrainte_de_route_avant_le_controleur(): void
    {
        // Cette app a un attrape-tout SPA `/{any}` (routes/web.php) qui répond 200
        // HTML à toute URL non matchée — donc un token malformé ne donne PAS un
        // 404 JSON en bout de chaîne. Ce qui compte réellement (et ce que cette
        // contrainte de route garantit) : notre route nommée ne matche PAS un
        // token malformé, donc OrderController::track() n'est jamais invoqué et
        // aucune requête DB n'est faite avec la valeur brute.
        $route = app('router')->getRoutes()->getByName('frontend.order.track');
        $this->assertNotNull($route);

        $request = \Illuminate\Http\Request::create('/api/frontend/order/track/trop-court', 'GET');
        $this->assertFalse(
            $route->matches($request),
            'La contrainte [A-Za-z0-9]{48} doit rejeter un token malformé avant le contrôleur.'
        );
    }

    /** @test */
    public function une_commande_d_une_autre_branche_reste_consultable_par_son_propre_token(): void
    {
        // [Multi-succursale] Le token identifie une commande précise, pas un
        // scope de branche — c'est le client final qui consulte SA commande.
        $otherBranch = Branch::factory()->create();
        $order = $this->makeOrder(['branch_id' => $otherBranch->id]);

        $result = $this->track($order->tracking_token);
        $this->assertTrue($result['found']);
    }
}
