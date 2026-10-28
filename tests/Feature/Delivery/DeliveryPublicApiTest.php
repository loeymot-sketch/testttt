<?php

namespace Tests\Feature\Delivery;

use App\Enums\Activity;
use App\Models\Branch;
use App\Services\Delivery\DeliveryAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * Endpoints publics de la livraison (site + application) : statut, suggestions, devis, créneaux.
 *
 * @group delivery
 */
class DeliveryPublicApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        Settings::group('order_setup')->set(['order_setup_delivery' => Activity::ENABLE]);
    }

    private function fakeGeocode(string $city, string $postcode, float $lat, float $lng, float $score = 0.95): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [$lng, $lat]],
            'properties' => ['label' => "10 Rue Test {$postcode} {$city}", 'postcode' => $postcode, 'city' => $city, 'score' => $score, 'housenumber' => '10', 'street' => 'Rue Test'],
        ]]])]);
    }

    private function api(): self
    {
        return $this->withHeader('x-api-key', 'test-api-key');
    }

    public function test_status_exposes_enabled_flag_and_the_public_grid(): void
    {
        $r = $this->api()->getJson('/api/frontend/delivery/status')->assertOk();
        $this->assertTrue($r->json('enabled'));
        $this->assertSame(3.9, $r->json('grid.home_fee'));
        $this->assertEquals(10.0, $r->json('grid.max_km'));
        $this->assertCount(3, $r->json('grid.tiers'));
    }

    public function test_quote_in_the_home_city_is_3_90_with_slots(): void
    {
        $this->fakeGeocode('Hénin-Beaumont', '62110', 50.4300, 2.9600);

        $r = $this->api()->postJson('/api/frontend/delivery/quote', ['q' => '10 rue test henin beaumont'])->assertOk();

        $this->assertTrue($r->json('allowed'));
        $this->assertSame(3.9, $r->json('fee'));
        $this->assertSame('home', $r->json('tier'));
        $this->assertSame('Hénin-Beaumont', $r->json('address.city'));
        $this->assertNotEmpty($r->json('waves'));
        $this->assertGreaterThan(0, $r->json('travel_minutes'));
    }

    public function test_quote_in_a_nearby_town_uses_the_radius_tier(): void
    {
        // ≈ 6 km au nord du restaurant, commune voisine.
        $this->fakeGeocode('Oignies', '62590', 50.4755, 2.9549);

        $r = $this->api()->postJson('/api/frontend/delivery/quote', ['q' => '10 rue test oignies'])->assertOk();

        $this->assertTrue($r->json('allowed'));
        $this->assertSame(5.9, $r->json('fee'));
    }

    public function test_quote_beyond_ten_km_is_refused_and_suggests_uber_eats(): void
    {
        $this->fakeGeocode('Arras', '62000', 50.2900, 2.7800); // ≈ 18 km

        $r = $this->api()->postJson('/api/frontend/delivery/quote', ['q' => '10 rue test arras'])->assertOk();

        $this->assertFalse($r->json('allowed'));
        $this->assertTrue($r->json('suggest_uber_eats'));
        $this->assertSame(0.0, (float) $r->json('fee'));
        $this->assertSame([], $r->json('waves'));
    }

    public function test_quote_when_delivery_is_suspended_says_so_and_suggests_uber_eats(): void
    {
        app(DeliveryAvailability::class)->set(false);
        Http::fake();

        $r = $this->api()->postJson('/api/frontend/delivery/quote', ['q' => '10 rue test henin beaumont'])->assertOk();

        $this->assertFalse($r->json('enabled'));
        $this->assertFalse($r->json('allowed'));
        Http::assertNothingSent();
    }

    public function test_unresolvable_address_is_a_clean_422_never_a_price(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response('down', 503)]);

        $this->api()->postJson('/api/frontend/delivery/quote', ['q' => '10 rue test henin beaumont'])
            ->assertStatus(422)
            ->assertJsonPath('code', 'GEOCODE_FAILED');
    }

    public function test_suggest_returns_candidates(): void
    {
        $this->fakeGeocode('Hénin-Beaumont', '62110', 50.4300, 2.9600);

        $r = $this->api()->getJson('/api/frontend/delivery/suggest?q=10 rue test')->assertOk();
        $this->assertSame('Hénin-Beaumont', $r->json('data.0.city'));
    }
}
