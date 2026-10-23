<?php

namespace Tests\Feature\Delivery;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Order;
use App\Models\OrderAddress;
use App\Models\User;
use App\Services\Delivery\DeliveryQuoteService;
use App\Services\Delivery\DeliveryWaveService;
use App\Services\Delivery\RoadRouteService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

/**
 * [2026-10-23 · décision propriétaire] Deux calculs qu'il ne faut JAMAIS confondre :
 *  · CLIENT / prix / site : trajet ALLER SIMPLE, restaurant → adresse (le prix n'en dépend de toute façon pas) ;
 *  · GESTION LIVREUR : tournée ALLER-RETOUR réelle (restaurant → arrêts → restaurant, remises comprises), sur itinéraire routier.
 *
 * @group delivery
 */
class TourneeAllerRetourTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        Role::firstOrCreate(['name' => 'Delivery Boy', 'guard_name' => 'sanctum']);
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key', 'delivery.routing.enabled' => false]);
        Cache::flush();
        Carbon::setTestNow(Carbon::parse('2026-10-20 19:02:00'));
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function osrm(array $legSeconds, array $legMeters = []): void
    {
        $legs = [];
        foreach ($legSeconds as $i => $sec) {
            $legs[] = ['duration' => $sec, 'distance' => $legMeters[$i] ?? 1000];
        }
        Http::fake(['*' => Http::response(['code' => 'Ok', 'routes' => [['legs' => $legs]]], 200)]);
    }

    private function stopAt(float $lat, float $lng): DeliveryStop
    {
        $customer = User::factory()->create(['branch_id' => $this->branch->id]);
        $order = Order::factory()->create([
            'branch_id' => $this->branch->id, 'user_id' => $customer->id, 'order_type' => OrderType::DELIVERY,
            'status' => OrderStatus::PREPARED, 'payment_status' => PaymentStatus::PAID, 'payment_method' => PaymentGateway::CARD,
        ]);
        OrderAddress::query()->create([
            'order_id' => $order->id, 'user_id' => $customer->id, 'label' => 'x', 'address' => '1 rue',
            'latitude' => (string) $lat, 'longitude' => (string) $lng, 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);

        return app(DeliveryWaveService::class)->assign($order->fresh());
    }

    private function crowKm(float $lat, float $lng): float
    {
        $dLat = deg2rad($lat - 50.4215667);
        $dLng = deg2rad($lng - 2.9549060);
        $h = sin($dLat / 2) ** 2 + cos(deg2rad(50.4215667)) * cos(deg2rad($lat)) * sin($dLng / 2) ** 2;

        return 6371.0 * 2 * atan2(sqrt($h), sqrt(1 - $h));
    }

    // ── Fournisseur d'itinéraires ───────────────────────────────────────────────────────────────

    public function test_real_road_legs_are_read_from_the_router(): void
    {
        config(['delivery.routing.enabled' => true]);
        $this->osrm([600, 660], [4200, 4700]);

        $r = app(RoadRouteService::class)->legs([[50.42, 2.95], [50.43, 2.96], [50.42, 2.95]]);

        $this->assertSame('road', $r['source']);
        $this->assertEqualsWithDelta(600.0, $r['legs'][0]['seconds'], 0.001);
        $this->assertEqualsWithDelta(4.7, $r['legs'][1]['km'], 0.001);
        Http::assertSent(fn ($req) => str_contains($req->url(), '/route/v1/driving/2.95,50.42;2.96,50.43;2.95,50.42'));
    }

    public function test_a_router_outage_falls_back_to_the_estimate_says_so_and_is_not_retried_for_a_minute(): void
    {
        config(['delivery.routing.enabled' => true]);
        Http::fake(['*' => Http::response('boom', 500)]);

        $a = app(RoadRouteService::class)->legs([[50.42, 2.95], [50.43, 2.96]]);
        $b = app(RoadRouteService::class)->legs([[50.42, 2.95], [50.43, 2.96]]);

        $this->assertSame('estimate', $a['source'], 'Une estimation ne se fait JAMAIS passer pour un itinéraire réel.');
        $this->assertSame('estimate', $b['source']);
        $this->assertGreaterThan(0, $a['legs'][0]['seconds']);
        Http::assertSentCount(1);
    }

    public function test_a_router_answer_with_the_wrong_number_of_legs_is_rejected(): void
    {
        config(['delivery.routing.enabled' => true]);
        $this->osrm([600]); // 1 étape renvoyée pour 2 demandées

        $r = app(RoadRouteService::class)->legs([[50.42, 2.95], [50.43, 2.96], [50.42, 2.95]]);

        $this->assertSame('estimate', $r['source']);
    }

    public function test_routing_switched_off_makes_no_network_call(): void
    {
        Http::fake();
        $r = app(RoadRouteService::class)->legs([[50.42, 2.95], [50.43, 2.96]]);

        $this->assertSame('estimate', $r['source']);
        Http::assertNothingSent();
    }

    // ── Aller-retour réel : gestion des livreurs ────────────────────────────────────────────────

    public function test_the_tour_is_the_real_round_trip_with_handovers_on_the_road_itinerary(): void
    {
        config(['delivery.routing.enabled' => true]);
        $this->osrm([600, 660]); // aller 10 min, retour 11 min (conduite brute)
        $stop = $this->stopAt(50.43, 2.96);
        $stop = $stop->fresh();

        $t = app(DeliveryWaveService::class)->tourSummary($this->branch->id, DeliveryStop::query()->with('order.address')->get());

        // facteur de circulation 1,15 : aller = ceil(10×1,15 + 3 de stationnement) = 15 ; retour = ceil(11×1,15) = 13 ; remise 4.
        $this->assertSame('road', $t['source']);
        $this->assertSame(19, $t['out_minutes'], 'Aller (15) + remise (4).');
        $this->assertSame(13, $t['return_minutes']);
        $this->assertSame(32, $t['tour_minutes'], 'Aller + remise + RETOUR au restaurant.');
        $this->assertSame($stop->wave_at->copy()->addMinutes(32)->toDateTimeString(), $t['back_at']);
        // L'itinéraire est maintenant en mémoire : le re-séquençage (sans réseau) en tire l'ETA d'ARRIVÉE, aller simple.
        app(DeliveryWaveService::class)->resequence($this->branch->id, $stop->wave_at);
        $this->assertSame(15, (int) $stop->fresh()->eta_minutes, 'L\'ETA vu par le client reste l\'ALLER SIMPLE (15), jamais l\'aller-retour.');
        Http::assertSentCount(1);
    }

    /** [relecture adverse P1-2] Créer une commande n'attend JAMAIS un tiers : la transaction d'assign() tient des verrous. */
    public function test_creating_an_order_never_calls_the_router(): void
    {
        config(['delivery.routing.enabled' => true]);
        $this->osrm([600, 660]);

        $stop = $this->stopAt(50.43, 2.96);

        Http::assertNothingSent();
        $this->assertGreaterThan(0, (int) $stop->fresh()->eta_minutes, 'L\'ETA existe quand même (estimation).');
    }

    /** [relecture adverse P1-2] Une panne du fournisseur coûte UN délai par minute, pas un par tournée. */
    public function test_one_outage_is_remembered_for_every_route_not_only_the_one_that_failed(): void
    {
        config(['delivery.routing.enabled' => true]);
        Http::fake(['*' => Http::response('boom', 500)]);

        $routes = app(RoadRouteService::class);
        $routes->legs([[50.42, 2.95], [50.43, 2.96]]);
        $routes->legs([[50.42, 2.95], [50.50, 3.10]]);   // autre tournée, autre clé de mémoire
        $routes->legs([[50.42, 2.95], [50.44, 2.97], [50.42, 2.95]]);

        Http::assertSentCount(1);
    }

    /** [relecture adverse P2-2] Une tournée en retard (pas partie) ne peut pas annoncer un retour dans le passé. */
    public function test_the_return_time_of_a_late_unleft_tour_is_never_in_the_past(): void
    {
        $stop = $this->stopAt(50.43, 2.96); // départ 19:30
        Carbon::setTestNow(Carbon::parse('2026-10-20 19:41:00'));

        $t = app(DeliveryWaveService::class)->tourSummary($this->branch->id, [$stop->fresh()->load('order.address')]);

        $this->assertSame(Carbon::now()->addMinutes($t['tour_minutes'])->toDateTimeString(), $t['back_at'], 'Au plus tôt : maintenant + durée.');
    }

    public function test_the_return_time_counts_from_the_real_departure_once_the_courier_has_left(): void
    {
        $stop = $this->stopAt(50.43, 2.96);
        $stop->forceFill(['left_at' => Carbon::parse('2026-10-20 19:20:00')])->save();
        Carbon::setTestNow(Carbon::parse('2026-10-20 19:41:00'));

        $t = app(DeliveryWaveService::class)->tourSummary($this->branch->id, [$stop->fresh()->load('order.address')]);

        $this->assertSame(Carbon::parse('2026-10-20 19:20:00')->addMinutes($t['tour_minutes'])->toDateTimeString(), $t['back_at']);
    }

    /** [relecture adverse P2-1] Un arrêt confié à Uber n'est pas du temps de NOS livreurs. */
    public function test_a_stop_given_to_uber_is_left_out_of_our_couriers_tour(): void
    {
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('POS Operator');
        $cashier->givePermissionTo('pos');
        $own = $this->stopAt(50.43, 2.96);
        $uber = $this->stopAt(50.50, 3.05); // loin : alourdirait fortement la tournée

        $this->mock(\App\Services\UberDirect\UberDirectDispatchService::class, function ($mock) use ($uber) {
            $mock->shouldIgnoreMissing();
            $mock->shouldReceive('isAvailable')->andReturn(false);
            $mock->shouldReceive('syncActive')->andReturnNull();
            $mock->shouldReceive('activeCourse')->andReturnUsing(
                fn (int $orderId) => $orderId === (int) $uber->order_id ? new \App\Models\UberDirectDelivery(['status' => 'pickup']) : null
            );
        });
        $expected = app(DeliveryWaveService::class)->tourSummary($this->branch->id, [$own->fresh()->load('order.address')]);

        $board = $this->actingAs($cashier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->getJson('/api/admin/pos-delivery/board')->assertOk();

        $this->assertSame(1, $board->json('data.0.tour.stops'), 'Seul notre arrêt compte.');
        $this->assertSame($expected['tour_minutes'], $board->json('data.0.tour.tour_minutes'));
    }

    public function test_the_tour_is_much_longer_than_the_one_way_the_customer_sees(): void
    {
        $stop = $this->stopAt(50.45, 2.99); // ≈ 4 km à vol d'oiseau
        $t = app(DeliveryWaveService::class)->tourSummary($this->branch->id, [$stop->fresh()->load('order.address')]);

        $this->assertSame('estimate', $t['source']);
        $this->assertGreaterThan((int) $stop->eta_minutes + 4, $t['tour_minutes'], 'La tournée inclut le RETOUR : elle dépasse l\'aller + la remise.');
        $x = $this->crowKm(50.45, 2.99) * 1.3 / 28 * 60;
        $this->assertSame((int) ceil($x + 3) + 4 + (int) ceil($x), $t['tour_minutes'], 'Estimation : aller + remise + retour (même distance).');
    }

    public function test_several_stops_are_chained_and_the_return_starts_from_the_last_one(): void
    {
        config(['delivery.routing.enabled' => true]);
        $this->osrm([300, 240, 420]); // resto→A 5 min, A→B 4 min, B→resto 7 min
        $this->stopAt(50.43, 2.96);
        $this->stopAt(50.435, 2.965);

        $t = app(DeliveryWaveService::class)->tourSummary($this->branch->id, DeliveryStop::query()->with('order.address')->get());

        // arrivées : A = ceil(5×1,15+3)=9 ; B = 9 + 4 (remise A) + ceil(4×1,15+3)=8 → 21 ; sortie = 21 + 4 ; retour = ceil(7×1,15)=9.
        $this->assertSame(2, $t['stops']);
        $this->assertSame(25, $t['out_minutes']);
        $this->assertSame(9, $t['return_minutes']);
        $this->assertSame(34, $t['tour_minutes']);
    }

    public function test_no_gps_point_means_no_invented_duration(): void
    {
        $stop = $this->stopAt(50.43, 2.96);
        $stop->order->address->forceFill(['latitude' => '', 'longitude' => ''])->save();

        $this->assertNull(app(DeliveryWaveService::class)->tourSummary($this->branch->id, [$stop->fresh()->load('order.address')]));
    }

    // ── Écrans : caisse et livreur ──────────────────────────────────────────────────────────────

    public function test_the_cash_register_board_and_the_courier_screen_both_carry_the_round_trip(): void
    {
        $courier = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);
        $courier->assignRole('Delivery Boy');
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('POS Operator');
        $cashier->givePermissionTo('pos');
        $stop = $this->stopAt(50.43, 2.96);

        $board = $this->actingAs($cashier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->getJson('/api/admin/pos-delivery/board')->assertOk();
        $this->assertGreaterThan(0, $board->json('data.0.tour.tour_minutes'));
        $this->assertSame('estimate', $board->json('data.0.tour.source'));
        $this->assertNotNull($board->json('data.0.tour.back_at'));

        $stop->forceFill(['delivery_boy_id' => $courier->id])->save();
        $run = $this->actingAs($courier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->getJson('/api/frontend/delivery-run')->assertOk();
        $this->assertSame($board->json('data.0.tour.tour_minutes'), $run->json('data.0.tour.tour_minutes'), 'Même durée pour la caisse et le livreur.');
    }

    public function test_a_fully_delivered_wave_has_no_tour_to_show(): void
    {
        $cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $cashier->assignRole('POS Operator');
        $cashier->givePermissionTo('pos');
        $stop = $this->stopAt(50.43, 2.96);
        $stop->forceFill(['left_at' => now(), 'delivered_at' => now()])->save();

        $board = $this->actingAs($cashier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->getJson('/api/admin/pos-delivery/board')->assertOk();

        $this->assertNull($board->json('data.0.tour'));
    }

    // ── Côté client : aller simple, toujours ────────────────────────────────────────────────────

    public function test_what_the_customer_sees_is_the_one_way_trip_and_never_the_round_trip(): void
    {
        $q = app(DeliveryQuoteService::class)->quoteForAddress($this->branch->id, [
            'geocode_status' => 'OK', 'latitude' => '50.45', 'longitude' => '2.99', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);

        $oneWay = $this->crowKm(50.45, 2.99);
        $this->assertEqualsWithDelta($oneWay, $q['distance_km'], 0.01, 'Distance = restaurant → adresse, UNE fois.');
        $this->assertSame(app(DeliveryQuoteService::class)->travelMinutes($oneWay), $q['travel_minutes']);
        $this->assertLessThan(
            (int) ceil(2 * $oneWay * 1.3 / 28 * 60),
            $q['travel_minutes'],
            'Le temps montré au client n\'est pas doublé : aller simple.'
        );
        $this->assertEqualsWithDelta(3.90, $q['delivery_charge'], 0.001, 'Le prix ne dépend ni du retour ni du routage.');
    }
}
