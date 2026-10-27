<?php

namespace Tests\Feature\Delivery;

use App\Enums\Activity;
use App\Models\Branch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * Trouvé par l'audit E2E de supervision (2026-10-21) : avec des `throttle:N,M` nus, Laravel signe une requête invitée par
 * (domaine|IP) SANS la route — tous les limiteurs invités d'une même IP partagent UN compteur. 21 appels à /status
 * (limite 60) faisaient répondre 429 au devis (limite 20) sans qu'aucun devis n'ait été demandé : un client derrière une
 * IP partagée (4G, Wi-Fi) ne pouvait plus commander. Chaque route publique de livraison a désormais son propre seau.
 *
 * @group delivery
 */
class DeliveryPublicThrottleIsolationTest extends TestCase
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
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [2.9600, 50.4300]],
            'properties' => ['label' => '10 Rue Test 62110 Hénin-Beaumont', 'postcode' => '62110', 'city' => 'Hénin-Beaumont', 'score' => 0.95, 'housenumber' => '10', 'street' => 'Rue Test'],
        ]]])]);
    }

    private function api(): self
    {
        return $this->withHeader('x-api-key', 'test-api-key');
    }

    public function test_hammering_status_does_not_exhaust_the_quote_bucket(): void
    {
        for ($i = 0; $i < 25; $i++) {
            $this->api()->getJson('/api/frontend/delivery/status')->assertOk();
        }

        $this->api()->postJson('/api/frontend/delivery/quote', ['q' => '10 rue test henin beaumont'])->assertOk();
    }

    public function test_the_quote_route_keeps_its_own_ceiling_of_one_hundred_twenty_per_minute(): void
    {
        // Plafond général aligné sur la production (l'environnement de test le fixe à 120, qui masquerait le seau du devis).
        config(['app.api_throttle_per_minute' => 600]);

        for ($i = 0; $i < 120; $i++) {
            $this->api()->postJson('/api/frontend/delivery/quote', ['q' => '10 rue test henin beaumont'])->assertOk();
        }

        $this->api()->postJson('/api/frontend/delivery/quote', ['q' => '10 rue test henin beaumont'])->assertStatus(429);
        // Le seau du devis plein ne bloque pas la lecture du statut.
        $this->api()->getJson('/api/frontend/delivery/status')->assertOk();
    }
}
