<?php

namespace Tests\Feature\Delivery;

use App\Exceptions\Delivery\GeocodeUnavailableException;
use App\Services\Delivery\AddressGeocoder;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Le géocodage est fait par le SERVEUR (BAN), jamais fourni par le client : sinon un client à
 * 9 km pose lat/lng ≈ restaurant et paie le forfait de la commune.
 *
 * @group delivery
 */
class AddressGeocoderTest extends TestCase
{
    private function banPayload(array $features): array
    {
        return ['type' => 'FeatureCollection', 'features' => $features];
    }

    private function feature(string $label, string $postcode, string $city, float $lng, float $lat, float $score = 0.93): array
    {
        return [
            'geometry'   => ['type' => 'Point', 'coordinates' => [$lng, $lat]],
            'properties' => [
                'label' => $label, 'postcode' => $postcode, 'city' => $city, 'score' => $score,
                'housenumber' => '437', 'street' => 'Rue Élie Gruyelle', 'citycode' => '62427',
            ],
        ];
    }

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
    }

    public function test_geocode_returns_server_side_coordinates_city_and_postcode(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response($this->banPayload([
            $this->feature('437 Rue Élie Gruyelle 62110 Hénin-Beaumont', '62110', 'Hénin-Beaumont', 2.9549, 50.4215),
        ]))]);

        $g = app(AddressGeocoder::class)->geocode('437 rue elie gruyelle henin beaumont');

        $this->assertSame('Hénin-Beaumont', $g['city']);
        $this->assertSame('62110', $g['postal_code']);
        $this->assertEqualsWithDelta(50.4215, $g['latitude'], 0.0001);
        $this->assertEqualsWithDelta(2.9549, $g['longitude'], 0.0001);
    }

    public function test_low_score_match_is_refused_not_guessed(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response($this->banPayload([
            $this->feature('Rue quelconque 62000 Arras', '62000', 'Arras', 2.78, 50.29, 0.31),
        ]))]);

        $this->expectException(GeocodeUnavailableException::class);
        app(AddressGeocoder::class)->geocode('blabla');
    }

    public function test_no_result_is_refused(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response($this->banPayload([]))]);
        $this->expectException(GeocodeUnavailableException::class);
        app(AddressGeocoder::class)->geocode('zzzzzz');
    }

    public function test_provider_down_fails_closed(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response('boom', 503)]);
        $this->expectException(GeocodeUnavailableException::class);
        app(AddressGeocoder::class)->geocode('437 rue elie gruyelle');
    }

    public function test_search_returns_suggestions_for_autocomplete(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response($this->banPayload([
            $this->feature('437 Rue Élie Gruyelle 62110 Hénin-Beaumont', '62110', 'Hénin-Beaumont', 2.9549, 50.4215),
            $this->feature('12 Rue Élie Gruyelle 62110 Hénin-Beaumont', '62110', 'Hénin-Beaumont', 2.9551, 50.4219),
        ]))]);

        $list = app(AddressGeocoder::class)->search('rue elie gru');
        $this->assertCount(2, $list);
        $this->assertSame('437 Rue Élie Gruyelle 62110 Hénin-Beaumont', $list[0]['label']);
    }

    public function test_short_query_does_not_call_the_provider(): void
    {
        Http::fake();
        $this->assertSame([], app(AddressGeocoder::class)->search('ab'));
        Http::assertNothingSent();
    }

    public function test_a_bare_municipality_is_not_a_deliverable_address(): void
    {
        $f = $this->feature('Hénin-Beaumont', '62110', 'Hénin-Beaumont', 2.9549, 50.4215, 0.95);
        $f['properties']['type'] = 'municipality';
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response($this->banPayload([$f]))]);

        $this->expectException(GeocodeUnavailableException::class);
        app(AddressGeocoder::class)->geocode('henin-beaumont');
    }

    public function test_a_street_level_match_is_accepted(): void
    {
        $f = $this->feature('437 Rue Élie Gruyelle 62110 Hénin-Beaumont', '62110', 'Hénin-Beaumont', 2.9549, 50.4215);
        $f['properties']['type'] = 'housenumber';
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response($this->banPayload([$f]))]);

        $this->assertSame('housenumber', app(AddressGeocoder::class)->geocode('437 rue elie gruyelle')['type']);
    }

    public function test_the_restaurant_position_is_sent_as_a_proximity_hint(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response($this->banPayload([
            $this->feature('437 Rue Élie Gruyelle 62110 Hénin-Beaumont', '62110', 'Hénin-Beaumont', 2.9549, 50.4215),
        ]))]);

        app(AddressGeocoder::class)->geocode('12 rue de la gare oignies');
        app(AddressGeocoder::class)->search('12 rue de la gare');

        Http::assertSent(fn ($req) => abs((float) ($req['lat'] ?? 0) - 50.4215667) < 0.001 && abs((float) ($req['lon'] ?? 0) - 2.954906) < 0.001);
        Http::assertSentCount(2);
    }

    public function test_same_address_is_cached(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response($this->banPayload([
            $this->feature('437 Rue Élie Gruyelle 62110 Hénin-Beaumont', '62110', 'Hénin-Beaumont', 2.9549, 50.4215),
        ]))]);
        $svc = app(AddressGeocoder::class);
        $svc->geocode('437 rue elie gruyelle');
        $svc->geocode('437 Rue Elie Gruyelle');
        Http::assertSentCount(1);
    }
}
