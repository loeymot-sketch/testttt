<?php

namespace Tests\Feature\Order;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\TimeSlot;
use App\Services\WaitEstimateService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [T-C TEMPS-ATTENTE 2026-08-16 · GOAL owner] Estimation d'attente retrait web.
 *
 * Formule owner (paliers, remplace l'ancienne formule linéaire du 2026-07-28
 * qui décalait tout d'un cran — 3 commandes donnait 20-25 au lieu de 15-20) :
 *   - file ≤ 3 commandes actives devant → 15-20 min
 *   - file 4 à 5 commandes              → 20-25 min
 *   - file > 5 commandes                → 25-30 min (plafond dur)
 *
 * File « devant » = sémantique KitchenReleaseRule (SSOT board KDS) :
 *  - statuts actifs cuisine = visibleStatuses() = ACCEPT / PREPARING / PREPARED
 *    (miroir KdsSyncService::sync $activeStatuses) ;
 *  - release paiement = applyBoardReleaseFilter (PAID | PENDING_COUNTER | POS cash) ;
 *  - programmées HORS fenêtre (scheduled_at > now + lead) EXCLUES
 *    (applyScheduledBoardFilter) — sinon estimation gonflée (§0.5.4 du plan) ;
 *  - isolation branche stricte (branch_id explicite).
 */
class WaitEstimateEndpointTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        config(['app.api_key' => '123456']);
        config(['kds.scheduled_lead_minutes' => 20]);

        // Horloge fixe (hiver CET — pas de piège DST), même discipline que KdsScheduledOrderGateTest.
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

    /** Commande active cuisine (released board) : ACCEPT + PAID, ASAP par défaut. */
    private function makeKitchenOrder(array $overrides = []): Order
    {
        return Order::factory()->create(array_merge([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::ACCEPT,
            'payment_status'   => PaymentStatus::PAID,
            'order_datetime'   => now(),
            'is_advance_order' => Ask::NO,
            'scheduled_at'     => null,
        ], $overrides));
    }

    private function estimate(): array
    {
        return app(WaitEstimateService::class)->estimate($this->branch->id);
    }

    /** @test */
    public function zero_commande_active_donne_15_20(): void
    {
        $result = $this->estimate();

        $this->assertSame(0, $result['queue_count']);
        $this->assertSame(15, $result['wait_low']);
        $this->assertSame(20, $result['wait_high']);
    }

    /** @test */
    public function trois_commandes_actives_restent_dans_le_palier_15_20(): void
    {
        $this->makeKitchenOrder();
        $this->makeKitchenOrder(['status' => OrderStatus::PREPARING]);
        $this->makeKitchenOrder(['status' => OrderStatus::PREPARED]);

        $result = $this->estimate();

        $this->assertSame(3, $result['queue_count']);
        $this->assertSame(15, $result['wait_low']);
        $this->assertSame(20, $result['wait_high']);
    }

    /** @test */
    public function quatre_a_cinq_commandes_donnent_20_25(): void
    {
        for ($i = 0; $i < 4; $i++) {
            $this->makeKitchenOrder();
        }
        $result = $this->estimate();
        $this->assertSame(4, $result['queue_count']);
        $this->assertSame(20, $result['wait_low']);
        $this->assertSame(25, $result['wait_high']);

        $this->makeKitchenOrder();
        $result = $this->estimate();
        $this->assertSame(5, $result['queue_count']);
        $this->assertSame(20, $result['wait_low']);
        $this->assertSame(25, $result['wait_high']);
    }

    /** @test */
    public function six_commandes_actives_donnent_25_30(): void
    {
        for ($i = 0; $i < 6; $i++) {
            $this->makeKitchenOrder();
        }

        $result = $this->estimate();

        $this->assertSame(6, $result['queue_count']);
        $this->assertSame(25, $result['wait_low']);
        $this->assertSame(30, $result['wait_high']);
    }

    /** @test */
    public function douze_commandes_actives_plafonnent_a_25_30(): void
    {
        for ($i = 0; $i < 12; $i++) {
            $this->makeKitchenOrder();
        }

        $result = $this->estimate();

        $this->assertSame(12, $result['queue_count']);
        $this->assertSame(25, $result['wait_low']);
        $this->assertSame(30, $result['wait_high']);
    }

    /** @test */
    public function queue_count_displayed_ne_montre_jamais_moins_de_2_meme_a_zero_commande(): void
    {
        // [T-C PLANCHER-JAMAIS-ZERO] Owner : « on va jamais dire que y a aucune
        // commande, toujours y a deux commandes avant vous minimum ».
        $result = $this->estimate();
        $this->assertSame(0, $result['queue_count'], 'le compte RÉEL reste honnête (staff/admin)');
        $this->assertSame(2, $result['queue_count_displayed'], 'le plancher client ne descend jamais sous 2');

        $this->makeKitchenOrder();
        $result = $this->estimate();
        $this->assertSame(1, $result['queue_count']);
        $this->assertSame(2, $result['queue_count_displayed'], '1 commande réelle → toujours affiché 2 minimum');

        $this->makeKitchenOrder();
        $this->makeKitchenOrder();
        $result = $this->estimate();
        $this->assertSame(3, $result['queue_count']);
        $this->assertSame(3, $result['queue_count_displayed'], 'au-delà du plancher, la vraie valeur est affichée telle quelle');
    }

    /** @test */
    public function programmee_future_hors_fenetre_non_comptee(): void
    {
        // Cible 13:00, now 12:00, lead 20 → hors fenêtre board → NE compte PAS.
        $this->makeKitchenOrder([
            'scheduled_at' => Carbon::parse('2026-03-10 13:00:00', 'Europe/Paris'),
        ]);
        // Programmée DANS la fenêtre (12:10 <= now+20) → compte (elle est sur le board).
        $this->makeKitchenOrder([
            'scheduled_at' => Carbon::parse('2026-03-10 12:10:00', 'Europe/Paris'),
        ]);

        $result = $this->estimate();

        $this->assertSame(1, $result['queue_count']);
        $this->assertSame(15, $result['wait_low']);
        $this->assertSame(20, $result['wait_high']);
    }

    /** @test */
    public function commandes_terminees_annulees_ou_non_released_non_comptees(): void
    {
        $this->makeKitchenOrder(['status' => OrderStatus::DELIVERED]);
        $this->makeKitchenOrder(['status' => OrderStatus::CANCELED]);
        $this->makeKitchenOrder(['status' => OrderStatus::REJECTED]);
        // PENDING (pas encore acceptée) — hors statuts actifs cuisine.
        $this->makeKitchenOrder(['status' => OrderStatus::PENDING]);
        // ACCEPT mais UNPAID non-cash — non released board (KitchenReleaseRule).
        $this->makeKitchenOrder([
            'payment_status' => PaymentStatus::UNPAID,
            'order_type'     => OrderType::KIOSK,
        ]);

        $result = $this->estimate();

        $this->assertSame(0, $result['queue_count']);
        $this->assertSame(15, $result['wait_low']);
        $this->assertSame(20, $result['wait_high']);
    }

    /** @test */
    public function commandes_stale_hors_fenetre_2h_non_comptees(): void
    {
        // [STALE-GUARD] Une ACCEPT abandonnée > 2 h ne gonfle plus l'estimation à vie.
        $this->makeKitchenOrder(['order_datetime' => now()->subHours(3)]);
        $this->makeKitchenOrder(); // fraîche → compte

        $result = $this->estimate();

        $this->assertSame(1, $result['queue_count']);
    }

    /** @test */
    public function commandes_autre_branche_non_comptees(): void
    {
        $other = Branch::factory()->create();
        $this->makeKitchenOrder(['branch_id' => $other->id]);
        $this->makeKitchenOrder(['branch_id' => $other->id]);
        $this->makeKitchenOrder(); // seule la nôtre compte

        $result = $this->estimate();

        $this->assertSame(1, $result['queue_count']);
    }

    /** @test */
    public function endpoint_public_retourne_200_json_complet_et_throttle_present(): void
    {
        // 2026-03-10 = mardi → dayOfWeek 2.
        TimeSlot::create([
            'day'          => Carbon::now()->dayOfWeek,
            'opening_time' => '11:00',
            'closing_time' => '22:30',
        ]);

        $this->makeKitchenOrder();

        $response = $this->withHeader('x-api-key', '123456')
            ->getJson('/api/frontend/order/wait-estimate?branch_id=' . $this->branch->id);

        $response->assertOk()
            ->assertJsonStructure([
                'queue_count',
                'queue_count_displayed',
                'wait_low',
                'wait_high',
                'closing_time',
                'server_time',
            ])
            ->assertJson([
                'queue_count'           => 1,
                'queue_count_displayed' => 2,
                'wait_low'              => 15,
                'wait_high'             => 20,
                'closing_time'          => '22:30',
            ]);

        $route = app('router')->getRoutes()->getByName('frontend.order.wait-estimate');
        $this->assertNotNull($route, 'Route nommée frontend.order.wait-estimate absente.');
        $this->assertContains('throttle:30,1', $route->gatherMiddleware(),
            'Endpoint public wait-estimate DOIT être throttled (vecteur d\'abus).');
    }
}
