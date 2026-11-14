<?php

namespace Tests\Feature\Delivery;

use App\Enums\Activity;
use App\Models\Address;
use App\Models\Branch;
use App\Models\User;
use App\Services\Delivery\DeliveryAvailability;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * La livraison se pilote depuis la caisse : suspendre / remettre, client par téléphone, adresse
 * enregistrée une seule fois avec son prix.
 *
 * @group delivery
 */
class PosDeliveryControllerTest extends TestCase
{
    use RefreshDatabase;

    private User $cashier;
    private Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        Cache::flush();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        $this->branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $this->cashier = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->cashier->assignRole('POS Operator');
        $this->cashier->givePermissionTo('pos');
        Settings::group('order_setup')->set(['order_setup_delivery' => Activity::ENABLE]);
    }

    private function pos(string $method, string $uri, array $data = [])
    {
        return $this->actingAs($this->cashier, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->withHeader('X-Idempotency-Key', (string) \Illuminate\Support\Str::uuid())
            ->json($method, "/api/admin/pos-delivery/{$uri}", $data);
    }

    private function fakeGeocode(string $city, string $postcode, float $lat, float $lng): void
    {
        config(['delivery.geocoder.enabled' => true]);
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [$lng, $lat]],
            'properties' => ['label' => "10 Rue Test {$postcode} {$city}", 'postcode' => $postcode, 'city' => $city, 'score' => 0.95, 'housenumber' => '10', 'street' => 'Rue Test'],
        ]]])]);
    }

    public function test_cashier_can_suspend_and_resume_delivery(): void
    {
        $this->pos('POST', 'toggle', ['enabled' => false])->assertOk()->assertJsonPath('enabled', false);
        $this->assertFalse(app(DeliveryAvailability::class)->isEnabled());

        $this->pos('GET', 'status')->assertOk()->assertJsonPath('enabled', false);

        $this->pos('POST', 'toggle', ['enabled' => true])->assertOk()->assertJsonPath('enabled', true);
        $this->assertTrue(app(DeliveryAvailability::class)->isEnabled());
    }

    public function test_toggle_requires_the_pos_permission(): void
    {
        $nobody = User::factory()->create(['branch_id' => $this->branch->id]);
        $this->actingAs($nobody, 'sanctum')->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/admin/pos-delivery/toggle', ['enabled' => false])
            ->assertStatus(403);
        $this->assertTrue(app(DeliveryAvailability::class)->isEnabled());
    }

    public function test_a_suspended_delivery_refuses_the_web_order_type_server_side(): void
    {
        app(DeliveryAvailability::class)->set(false);
        $this->assertSame(Activity::DISABLE, (int) Settings::group('order_setup')->get('order_setup_delivery'));
    }

    public function test_customer_by_phone_is_created_once_then_found(): void
    {
        $first = $this->pos('POST', 'customer', ['phone' => '06 12 34 56 78', 'name' => 'Karim'])->assertOk();
        $this->assertTrue($first->json('created'));
        $id = $first->json('data.id');

        $second = $this->pos('POST', 'customer', ['phone' => '0612345678'])->assertOk();
        $this->assertFalse($second->json('created'));
        $this->assertSame($id, $second->json('data.id'), 'Même téléphone = même compte, jamais un doublon.');
    }

    public function test_the_caisse_has_its_own_authenticated_suggest_and_quote(): void
    {
        $this->fakeGeocode('Hénin-Beaumont', '62110', 50.4300, 2.9600);

        $this->pos('GET', 'suggest?q=10 rue test')->assertOk()->assertJsonPath('data.0.city', 'Hénin-Beaumont');
        $q = $this->pos('POST', 'quote', ['q' => '10 rue test henin beaumont'])->assertOk();
        $this->assertTrue($q->json('allowed'));
        $this->assertEquals(3.9, $q->json('fee'));
        $this->assertNotEmpty($q->json('waves'));
    }

    /**
     * [Supervision E2E 2026-10-21 · F-ADV-4] `throttle:N,M` nus : un seul compteur PAR UTILISATEUR pour toutes les routes
     * de la caisse. 30 suggestions d'adresse suffisaient à faire répondre 429 à la bascule et à l'enregistrement d'adresse
     * sans qu'ils aient été appelés. Un seau par route.
     */
    public function test_heavy_suggest_use_does_not_exhaust_the_toggle_bucket(): void
    {
        $this->fakeGeocode('Hénin-Beaumont', '62110', 50.4300, 2.9600);

        for ($i = 0; $i < 35; $i++) {
            $this->pos('GET', 'suggest?q=10 rue test')->assertOk();
        }

        $this->pos('POST', 'toggle', ['enabled' => false])->assertOk();
        $this->pos('POST', 'toggle', ['enabled' => true])->assertOk();
    }

    public function test_the_caisse_quote_and_suggest_require_the_pos_permission(): void
    {
        $nobody = User::factory()->create(['branch_id' => $this->branch->id]);
        $call = fn (string $m, string $u, array $d = []) => $this->actingAs($nobody, 'sanctum')->withHeader('x-api-key', 'test-api-key')->json($m, "/api/admin/pos-delivery/{$u}", $d);

        $call('GET', 'suggest?q=10 rue test')->assertStatus(403);
        $call('POST', 'quote', ['q' => '10 rue test henin beaumont'])->assertStatus(403);
    }

    public function test_lookup_finds_a_known_customer_with_addresses_and_never_creates_one(): void
    {
        $this->fakeGeocode('Hénin-Beaumont', '62110', 50.4300, 2.9600);
        $id = $this->pos('POST', 'customer', ['phone' => '0612345678', 'name' => 'Karim'])->json('data.id');
        $this->pos('POST', 'address', ['customer_id' => $id, 'address' => '10 rue test henin beaumont']);

        $found = $this->pos('GET', 'lookup?phone=06%2012%2034%2056%2078')->assertOk();
        $this->assertTrue($found->json('found'));
        $this->assertSame($id, $found->json('data.id'));
        $this->assertCount(1, $found->json('data.addresses'));

        $before = User::query()->count();
        $this->pos('GET', 'lookup?phone=0699999999')->assertOk()->assertJsonPath('found', false);
        $this->assertSame($before, User::query()->count(), 'Une recherche ne crée jamais de compte.');
    }

    public function test_lookup_never_offers_a_staff_account_as_a_customer(): void
    {
        $this->cashier->forceFill(['phone' => '0677889900'])->save();
        $this->pos('GET', 'lookup?phone=0677889900')->assertOk()->assertJsonPath('found', false);
    }

    public function test_address_is_geocoded_by_the_server_saved_once_and_priced(): void
    {
        $this->fakeGeocode('Hénin-Beaumont', '62110', 50.4300, 2.9600);
        $customer = $this->pos('POST', 'customer', ['phone' => '0612345678', 'name' => 'Karim'])->json('data.id');

        $a = $this->pos('POST', 'address', ['customer_id' => $customer, 'address' => '10 rue test henin beaumont', 'apartment' => '2B'])->assertOk();
        $this->assertTrue($a->json('data.allowed'));
        $this->assertEquals(3.9, $a->json('data.fee'));
        $this->assertSame('Hénin-Beaumont', $a->json('data.city'));

        // Même adresse ressaisie = la même ligne (enregistrée une seule fois).
        $b = $this->pos('POST', 'address', ['customer_id' => $customer, 'address' => '10 rue test henin beaumont', 'apartment' => '2B'])->assertOk();
        $this->assertSame($a->json('data.id'), $b->json('data.id'));
        $this->assertSame(1, Address::query()->where('user_id', $customer)->count());

        // Et le client retrouvé par téléphone arrive avec son adresse.
        $again = $this->pos('POST', 'customer', ['phone' => '0612345678'])->assertOk();
        $this->assertCount(1, $again->json('data.addresses'));
    }

    public function test_an_address_beyond_ten_km_is_saved_but_flagged_uber_eats(): void
    {
        $this->fakeGeocode('Arras', '62000', 50.2900, 2.7800);
        $customer = $this->pos('POST', 'customer', ['phone' => '0698765432'])->json('data.id');

        $a = $this->pos('POST', 'address', ['customer_id' => $customer, 'address' => '10 rue test arras'])->assertOk();
        $this->assertFalse($a->json('data.allowed'));
        $this->assertTrue($a->json('data.suggest_uber_eats'));
    }

    public function test_client_supplied_coordinates_are_ignored_by_the_server(): void
    {
        $this->fakeGeocode('Arras', '62000', 50.2900, 2.7800);
        $customer = $this->pos('POST', 'customer', ['phone' => '0611223344'])->json('data.id');

        // Le caissier (ou un client malin) envoie des coordonnées « proches » : elles sont ignorées.
        $a = $this->pos('POST', 'address', [
            'customer_id' => $customer, 'address' => '10 rue test arras', 'latitude' => '50.4215', 'longitude' => '2.9549',
        ])->assertOk();

        $this->assertFalse($a->json('data.allowed'), 'Le prix suit la position géocodée par le serveur, pas celle envoyée.');
        $this->assertEqualsWithDelta(2.78, (float) Address::query()->where('user_id', $customer)->value('longitude'), 0.01);
    }

    public function test_the_address_label_never_collides_after_a_deletion(): void
    {
        $id = $this->pos('POST', 'customer', ['phone' => '0612345678'])->json('data.id');
        foreach (['Livraison', 'Livraison 2'] as $label) {
            Address::query()->create(['user_id' => $id, 'label' => $label, 'address' => $label, 'latitude' => '50.1', 'longitude' => '2.1', 'geocode_status' => 'OK']);
        }
        Address::query()->where('user_id', $id)->where('label', 'Livraison')->delete(); // ne reste que « Livraison 2 » : count()+1 = 2 collisionnerait

        $this->fakeGeocode('Hénin-Beaumont', '62110', 50.4300, 2.9600);
        $this->pos('POST', 'address', ['customer_id' => $id, 'address' => '10 rue test henin beaumont'])->assertOk();

        $this->assertEqualsCanonicalizing(['Livraison 2', 'Livraison'], Address::query()->where('user_id', $id)->pluck('label')->all());
    }

    public function test_an_address_is_never_attached_to_the_shared_walk_in_or_to_staff(): void
    {
        $this->fakeGeocode('Hénin-Beaumont', '62110', 50.4300, 2.9600);
        $walkIn = app(\App\Services\Pos\WalkInCustomerResolver::class)->resolve();

        $this->pos('POST', 'address', ['customer_id' => $walkIn->id, 'address' => '10 rue test henin beaumont'])->assertStatus(422);
        $this->pos('POST', 'address', ['customer_id' => $this->cashier->id, 'address' => '10 rue test henin beaumont'])->assertStatus(422);
        $this->assertSame(0, Address::query()->count());
    }

    public function test_unresolvable_address_is_refused_cleanly(): void
    {
        config(['delivery.geocoder.enabled' => true]);
        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response('down', 503)]);
        $customer = $this->pos('POST', 'customer', ['phone' => '0655443322'])->json('data.id');

        $this->pos('POST', 'address', ['customer_id' => $customer, 'address' => '10 rue test'])->assertStatus(422);
        $this->assertSame(0, Address::query()->where('user_id', $customer)->count());
    }
}
