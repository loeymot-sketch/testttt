<?php

namespace Tests\Feature\Delivery;

use App\Exceptions\Delivery\OutsideDeliveryZoneException;
use App\Models\Branch;
use App\Services\Delivery\DeliveryQuoteService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL ROBUSTESSE 2026-07-27 · durcissement livraison pré-lancement]
 * Les coords de livraison viennent du CLIENT (saveAddress) — la garde ZONE
 * (polygone branches.zone, ray-casting) refuse tout point hors zone (422
 * OUTSIDE_DELIVERY_ZONE). Zone absente/corrompue → garde neutre (optionnelle).
 * Risque résiduel documenté : manipulation INTRA-zone, fermée au lancement
 * par re-géocodage serveur (décision provider owner).
 *
 * [LIVRAISON 2026-10-20] Le prix vient du barème propriétaire (DeliveryTariff) ; les colonnes
 * `branches.delivery_fee_*` ne pilotent plus rien. Zone absente/corrompue → la garde ZONE est neutre,
 * mais le rayon maximal de 10 km reste appliqué par le barème (refus + Uber Eats).
 * Géométrie : 1° lat ≈ 111,19 km ; +0,054° ≈ 6,0 km (palier ≤ 8 km = 5,90 €).
 *
 * @group delivery
 */
class DeliveryZoneGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Zone Hénin-Beaumont du seeder (format DOUBLE-ENCODÉ historique). */
    protected function setUp(): void
    {
        parent::setUp();
        // Le polygone est OPTIONNEL et désactivé par défaut (la zone = rayon de 10 km, cf. config/delivery.php) :
        // ces tests exercent explicitement l'option activée.
        config(['delivery.use_branch_zone_polygon' => true]);
    }

    private function heninZoneDoubleEncoded(): string
    {
        return json_encode('[{"lat":50.45,"lng":2.92},{"lat":50.45,"lng":2.99},{"lat":50.39,"lng":2.99},{"lat":50.39,"lng":2.92}]');
    }

    private function makeBranch(?string $zone): Branch
    {
        return Branch::factory()->create([
            'latitude'  => 50.4215667,
            'longitude' => 2.9549060,
            'zone'      => $zone,
        ]);
    }

    public function test_point_inside_zone_quotes_normally(): void
    {
        $branch = $this->makeBranch($this->heninZoneDoubleEncoded());
        $svc = app(DeliveryQuoteService::class);

        $quote = $svc->quoteForAddress($branch->id, [
            'latitude' => 50.4230, 'longitude' => 2.9600, // Hénin, dans le carré
            'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);

        $this->assertArrayHasKey('delivery_charge', $quote);
        $this->assertSame(3.9, (float) $quote['delivery_charge']); // commune du restaurant → 3,90 €
    }

    public function test_point_outside_zone_is_refused_422(): void
    {
        $branch = $this->makeBranch($this->heninZoneDoubleEncoded());
        $svc = app(DeliveryQuoteService::class);

        $this->expectException(OutsideDeliveryZoneException::class);
        $svc->quoteForAddress($branch->id, [
            'latitude' => 48.8566, 'longitude' => 2.3522, // Paris — hors zone
        ]);
    }

    public function test_fraud_scenario_coords_near_resto_but_outside_zone_refused(): void
    {
        // Client réel à Lens (~7 km, hors polygone) qui poserait des coords juste
        // hors du carré : refusé — il ne peut plus « téléporter » son adresse.
        $branch = $this->makeBranch($this->heninZoneDoubleEncoded());
        $svc = app(DeliveryQuoteService::class);

        $this->expectException(OutsideDeliveryZoneException::class);
        $svc->quoteForAddress($branch->id, [
            'latitude' => 50.4300, 'longitude' => 2.8300, // Lens — ouest du carré (lng < 2.92)
        ]);
    }

    public function test_zone_absent_guard_is_neutral(): void
    {
        $branch = $this->makeBranch(null);
        $svc = app(DeliveryQuoteService::class);

        // Point ≈ 6 km au nord, hors de tout polygone mais zone absente → pas de refus ZONE, barème au rayon.
        $quote = $svc->quoteForAddress($branch->id, [
            'latitude' => 50.4755667, 'longitude' => 2.9549060, 'city' => 'Oignies', 'postal_code' => '62590',
        ]);

        $this->assertArrayHasKey('delivery_charge', $quote);
        $this->assertSame(5.9, (float) $quote['delivery_charge']); // ≤ 8 km, pas de refus de zone

        // Zone absente ne désarme PAS le rayon maximal : Paris reste refusé, avec proposition Uber Eats.
        try {
            $svc->quoteForAddress($branch->id, ['latitude' => 48.8566, 'longitude' => 2.3522]);
            $this->fail('Paris doit rester refusé par le rayon maximal.');
        } catch (OutsideDeliveryZoneException $e) {
            $this->assertTrue($e->suggestUberEats);
        }
    }

    public function test_zone_single_encoded_format_also_supported(): void
    {
        $branch = $this->makeBranch('[{"lat":50.45,"lng":2.92},{"lat":50.45,"lng":2.99},{"lat":50.39,"lng":2.99},{"lat":50.39,"lng":2.92}]');
        $svc = app(DeliveryQuoteService::class);

        $quote = $svc->quoteForAddress($branch->id, [
            'latitude' => 50.4230, 'longitude' => 2.9600, // ≈ 0,4 km, commune inconnue → palier ≤ 5 km
        ]);
        $this->assertSame(4.9, (float) $quote['delivery_charge']);

        $this->expectException(OutsideDeliveryZoneException::class);
        $svc->quoteForAddress($branch->id, ['latitude' => 48.8566, 'longitude' => 2.3522]);
    }

    public function test_zone_corrupted_guard_is_neutral(): void
    {
        $branch = $this->makeBranch('{"not":"a-polygon"}');
        $svc = app(DeliveryQuoteService::class);

        $quote = $svc->quoteForAddress($branch->id, [
            'latitude' => 50.4755667, 'longitude' => 2.9549060, 'city' => 'Oignies', 'postal_code' => '62590',
        ]);
        $this->assertArrayHasKey('delivery_charge', $quote);
        $this->assertSame(5.9, (float) $quote['delivery_charge']);
    }

    public function test_the_polygon_is_ignored_by_default_the_radius_is_the_zone(): void
    {
        config(['delivery.use_branch_zone_polygon' => false]);
        // Polygone absurde (Paris) autour d'une branche à Hénin-Beaumont : l'adresse du restaurant doit rester livrable.
        $branch = $this->makeBranch('[{"lat":48.86,"lng":2.33},{"lat":48.87,"lng":2.36},{"lat":48.85,"lng":2.37},{"lat":48.84,"lng":2.34}]');
        $quote = app(DeliveryQuoteService::class)->quoteForAddress($branch->id, [
            'id' => 1, 'latitude' => '50.4300', 'longitude' => '2.9600', 'geocode_status' => 'OK', 'city' => 'Hénin-Beaumont', 'postal_code' => '62110',
        ]);

        $this->assertEquals(3.9, $quote['delivery_charge']);
    }
}
