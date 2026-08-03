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
 * @group delivery
 */
class DeliveryZoneGuardTest extends TestCase
{
    use RefreshDatabase;

    /** Zone Hénin-Beaumont du seeder (format DOUBLE-ENCODÉ historique). */
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
            'delivery_fee_base'    => 3.00,
            'delivery_fee_per_km'  => 2.00,
            'delivery_fee_minimum' => 4.00,
            'delivery_fee_free_km' => 3.00,
        ]);
    }

    public function test_point_inside_zone_quotes_normally(): void
    {
        $branch = $this->makeBranch($this->heninZoneDoubleEncoded());
        $svc = app(DeliveryQuoteService::class);

        $quote = $svc->quoteForAddress($branch->id, [
            'latitude' => 50.4230, 'longitude' => 2.9600, // Hénin, dans le carré
        ]);

        $this->assertArrayHasKey('delivery_charge', $quote);
        $this->assertSame(4.0, (float) $quote['delivery_charge']); // < 3 km → forfait 4 €
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

        $quote = $svc->quoteForAddress($branch->id, [
            'latitude' => 48.8566, 'longitude' => 2.3522, // Paris, mais zone absente
        ]);

        $this->assertArrayHasKey('delivery_charge', $quote); // fee distance, pas de refus
    }

    public function test_zone_single_encoded_format_also_supported(): void
    {
        $branch = $this->makeBranch('[{"lat":50.45,"lng":2.92},{"lat":50.45,"lng":2.99},{"lat":50.39,"lng":2.99},{"lat":50.39,"lng":2.92}]');
        $svc = app(DeliveryQuoteService::class);

        $quote = $svc->quoteForAddress($branch->id, [
            'latitude' => 50.4230, 'longitude' => 2.9600,
        ]);
        $this->assertSame(4.0, (float) $quote['delivery_charge']);

        $this->expectException(OutsideDeliveryZoneException::class);
        $svc->quoteForAddress($branch->id, ['latitude' => 48.8566, 'longitude' => 2.3522]);
    }

    public function test_zone_corrupted_guard_is_neutral(): void
    {
        $branch = $this->makeBranch('{"not":"a-polygon"}');
        $svc = app(DeliveryQuoteService::class);

        $quote = $svc->quoteForAddress($branch->id, [
            'latitude' => 48.8566, 'longitude' => 2.3522,
        ]);
        $this->assertArrayHasKey('delivery_charge', $quote);
    }
}
