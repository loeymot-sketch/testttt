<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\DeliveryStop;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use App\Services\Pos\WalkInCustomerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Smartisan\Settings\Facades\Settings;
use Tests\Feature\Pos\Traits\SeedsOpenCashDrawerSession;
use Tests\TestCase;

/**
 * BOUT EN BOUT, par l'API exactement comme l'écran de caisse l'enchaîne : un client INCONNU appelle, le caissier
 * saisit son téléphone et son adresse, le serveur géocode et chiffre, la caisse demande le devis puis valide.
 * Le client « de passage » (compte partagé) ne doit jamais hériter de l'adresse ni de la commande.
 *
 * @group delivery
 */
class PosNewCustomerDeliveryCheckoutTest extends TestCase
{
    use RefreshDatabase;
    use SeedsOpenCashDrawerSession;

    public function test_unknown_customer_phone_to_priced_committed_order_in_a_wave(): void
    {
        Cache::flush();
        config(['app.api_key' => 'test-api-key', 'pricing.tax_inclusive_prices' => true, 'pricing.use_ssot_service' => true, 'delivery.geocoder.enabled' => true]);
        Settings::group('order_setup')->set(['order_setup_delivery' => \App\Enums\Activity::ENABLE]);
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create(['latitude' => '50.4215667', 'longitude' => '2.9549060']);
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');
        $this->seedOpenSessionFor($operator, $branch);
        $walkIn = app(WalkInCustomerResolver::class)->resolve(); // déjà dans le formulaire de la caisse au démarrage

        Http::fake(['api-adresse.data.gouv.fr/*' => Http::response(['features' => [[
            'geometry' => ['coordinates' => [2.9600, 50.4300]],
            'properties' => ['label' => '10 Rue Test 62110 Hénin-Beaumont', 'postcode' => '62110', 'city' => 'Hénin-Beaumont', 'score' => 0.96, 'housenumber' => '10', 'street' => 'Rue Test', 'type' => 'housenumber'],
        ]]])]);

        $call = fn (string $m, string $uri, array $d = []) => $this->actingAs($operator, 'sanctum')->withHeader('x-api-key', 'test-api-key')->json($m, $uri, $d);

        // 1-2. Le caissier saisit le téléphone puis l'adresse choisie dans la liste.
        $customerId = $call('POST', '/api/admin/pos-delivery/customer', ['phone' => '06 12 34 56 78', 'name' => 'Karim'])->assertOk()->json('data.id');
        $this->assertNotSame($walkIn->id, $customerId, 'Un nouveau client, jamais le compte de passage.');
        $address = $call('POST', '/api/admin/pos-delivery/address', ['customer_id' => $customerId, 'address' => '10 rue test henin beaumont', 'apartment' => '2B'])->assertOk()->json('data');
        $this->assertTrue($address['allowed']);
        $this->assertEquals(3.9, $address['fee']);

        // 3. Devis de caisse puis validation, avec les champs que l'écran envoie (et un prix forgé qui doit être ignoré).
        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $item = Item::factory()->create(['item_category_id' => ItemCategory::factory()->create(['status' => Status::ACTIVE])->id, 'tax_id' => $tax->id, 'price' => 8.00, 'status' => Status::ACTIVE]);
        $payload = [
            'token' => null, 'customer_id' => $customerId, 'branch_id' => $branch->id, 'subtotal' => 0, 'discount' => 0, 'coupon_id' => 0, 'total' => 0,
            'order_type' => OrderType::DELIVERY, 'address_id' => $address['id'], 'delivery_charge' => 0.01, 'delivery_distance_km' => 0,
            'is_advance_order' => Ask::NO, 'source' => Source::POS, 'pos_payment_method' => PosPaymentMethod::CASH, 'pos_received_amount' => 0,
            'items' => json_encode([['item_id' => $item->id, 'quantity' => 1, 'item_variations' => [], 'item_extras' => []]]),
        ];
        $quote = $call('POST', '/api/admin/pos/quote', $payload)->assertOk()->json('data');
        $this->assertEquals(3.9, (float) $quote['delivery_charge'], 'Le serveur chiffre depuis l\'adresse, pas depuis 0,01 €.');
        $this->assertEquals(11.9, (float) $quote['total_ttc']);

        $r = $call('POST', '/api/admin/pos', array_merge($payload, ['quote_token' => $quote['quote_token'], 'quote_signature' => $quote['signature'], 'total' => $quote['total_ttc'], 'pos_received_amount' => $quote['total_ttc']]));
        $this->assertContains($r->status(), [200, 201], $r->getContent());

        // 4. La commande appartient au VRAI client, au bon prix, avec son palier, et elle est dans une tournée.
        $order = Order::query()->withoutGlobalScopes()->latest('id')->firstOrFail();
        $this->assertSame($customerId, (int) $order->user_id);
        $this->assertNotSame($walkIn->id, (int) $order->user_id);
        $this->assertEquals(3.9, (float) $order->delivery_charge);
        $this->assertEquals(11.9, (float) $order->total);
        $this->assertSame('home', $order->delivery_tier);
        $this->assertNotNull(DeliveryStop::query()->where('order_id', $order->id)->first(), 'Entrée dans une tournée de 30 minutes.');
        $this->assertSame(1, Address::query()->where('user_id', $customerId)->count(), 'Adresse enregistrée une seule fois.');
        $this->assertSame(0, Address::query()->where('user_id', $walkIn->id)->count(), 'Rien sur le compte de passage.');
    }
}
