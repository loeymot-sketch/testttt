<?php

namespace Tests\Feature\Delivery;

use App\Models\Address;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Toute adresse enregistrée (site, application, admin) est géocodée par le SERVEUR : les coordonnées et la
 * commune envoyées par le navigateur sont ignorées. Sans cela, un client à 9 km envoie « 50.4215, 2.9549 »
 * (le restaurant) et paie le forfait de la commune.
 *
 * @group delivery
 * @group sentinel
 */
class AddressServerGeocodingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key', 'delivery.geocoder.enabled' => true]);
        Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
    }

    private function fakeArras(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [2.78, 50.29]],
            'properties' => ['label' => '1 Place des Héros 62000 Arras', 'postcode' => '62000', 'city' => 'Arras', 'score' => 0.97, 'housenumber' => '1', 'street' => 'Place des Héros'],
        ]]])]);
    }

    public function test_web_address_ignores_forged_coordinates_and_city(): void
    {
        $this->fakeArras();
        $customer = User::factory()->create(['phone' => '0612345678']);

        $r = $this->actingAs($customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')->postJson('/api/frontend/address', [
            'label' => 'Domicile', 'address' => '1 place des heros arras',
            'latitude' => '50.4215', 'longitude' => '2.9549',      // forgés : « chez le restaurant »
            'city' => 'Hénin-Beaumont', 'postal_code' => '62110',    // forgés : « commune maison »
        ]);

        $r->assertSuccessful();
        $a = Address::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('Arras', $a->city, 'La commune vient du géocodeur, jamais du client.');
        $this->assertSame('62000', $a->postal_code);
        $this->assertEqualsWithDelta(2.78, (float) $a->longitude, 0.001);
        $this->assertEqualsWithDelta(50.29, (float) $a->latitude, 0.001);
        $this->assertSame('OK', $a->geocode_status);
    }

    public function test_admin_customer_address_ignores_forged_coordinates_too(): void
    {
        $this->fakeArras();
        $cashier = User::factory()->create();
        $cashier->assignRole('POS Operator');
        $cashier->givePermissionTo('pos');
        $customer = User::factory()->create();

        $this->actingAs($cashier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson("/api/admin/users/address/{$customer->id}", [
                'label' => 'Domicile', 'address' => '1 place des heros arras', 'latitude' => '50.4215', 'longitude' => '2.9549',
            ])->assertSuccessful();

        $a = Address::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('Arras', $a->city);
        $this->assertEqualsWithDelta(2.78, (float) $a->longitude, 0.001);
    }

    public function test_the_saved_address_is_the_one_the_geocoder_understood(): void
    {
        $this->fakeArras();
        $customer = User::factory()->create(['phone' => '0612345678']);

        $this->actingAs($customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')->postJson('/api/frontend/address', [
            'label' => 'Domicile', 'address' => 'chez moi pres de la mairie', 'apartment' => '3e étage',
        ])->assertSuccessful();

        $a = Address::query()->where('user_id', $customer->id)->firstOrFail();
        $this->assertSame('1 Place des Héros 62000 Arras', $a->address, 'Le livreur reçoit l\'adresse chiffrée, pas le texte libre.');
        $this->assertSame('3e étage', $a->apartment, 'Le complément reste séparé.');
    }

    public function test_the_same_place_is_saved_once(): void
    {
        $this->fakeArras();
        $customer = User::factory()->create(['phone' => '0612345678']);
        $call = fn (string $label) => $this->actingAs($customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/address', ['label' => $label, 'address' => '1 place des heros arras']);

        $first = $call('Domicile')->assertSuccessful()->json('data.id');
        $second = $call('Autre libellé')->assertSuccessful()->json('data.id');

        $this->assertSame($first, $second);
        $this->assertSame(1, Address::query()->where('user_id', $customer->id)->count());
    }

    public function test_unresolvable_address_is_refused_and_nothing_is_saved(): void
    {
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response('down', 503)]);
        $customer = User::factory()->create(['phone' => '0612345678']);

        $this->actingAs($customer, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/address', ['label' => 'Domicile', 'address' => '1 place des heros arras'])
            ->assertStatus(422);

        $this->assertSame(0, Address::query()->where('user_id', $customer->id)->count());
    }

    public function test_a_legacy_address_without_city_is_refused_not_priced(): void
    {
        // Adresse enregistrée AVANT le géocodage serveur : coordonnées « posées sur le restaurant », ni commune ni code postal.
        $customer = User::factory()->create(['phone' => '0612345678', 'branch_id' => 1]);
        $legacy = Address::query()->create([
            'user_id' => $customer->id, 'label' => 'Ancienne', 'address' => 'N\'importe où', 'latitude' => '50.4215667', 'longitude' => '2.9549060', 'geocode_status' => 'OK',
        ]);

        $this->expectException(\App\Exceptions\Delivery\GeocodeUnavailableException::class);
        app(\App\Services\Delivery\DeliveryQuoteService::class)->quoteForSavedAddress(1, $legacy->id, $customer->id);
    }

    public function test_server_geocoding_is_on_by_default_in_the_shipped_configuration(): void
    {
        // Ce banc force `delivery.geocoder.enabled=false` via phpunit.xml pour les tests historiques ;
        // la configuration LIVRÉE, elle, doit être ACTIVE. Aucun interrupteur de prod ne doit la couper en silence.
        $source = file_get_contents(base_path('config/delivery.php'));
        $this->assertStringContainsString("env('DELIVERY_SERVER_GEOCODING', true)", $source);
        $this->assertFileDoesNotExist(base_path('.env.example.never'));
        $example = (string) @file_get_contents(base_path('.env.example'));
        $this->assertStringNotContainsString('DELIVERY_SERVER_GEOCODING=false', $example);
    }
}
