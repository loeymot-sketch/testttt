<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Address;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\OrderQuote;
use App\Models\Tax;
use App\Models\User;
use App\Services\Delivery\DeliveryFeeService;
use App\Services\Pos\WalkInCustomerResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [LIVRAISON 2026-10-20 — réécrit pour le barème propriétaire]
 *
 * Historique : ce sentinelle gardait le câblage de la formule par colonnes de branche
 * (DEL-5, 2026-05-18). Le propriétaire a remplacé cette formule par le barème « commune + rayon »
 * (config/delivery.php, DeliveryTariff). L'invariant à garder est resté le même : les TROIS points
 * d'entrée (service, commande web, devis/commit caisse) doivent produire le MÊME prix, calculé par
 * le SERVEUR depuis l'adresse enregistrée, jamais une valeur client.
 *
 * Géométrie des tests : 1° de latitude ≈ 111,19 km ; +0,054° ≈ 6,0 km (palier « ≤ 8 km » = 5,90 €).
 */
class DeliveryFeeBranchWireupSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_service_signature_prices_by_city_and_radius_and_refuses_beyond(): void
    {
        $service = new DeliveryFeeService();

        $this->assertSame(3.9, $service->fromDistanceKm(4, null, 'Hénin-Beaumont', '62110'));
        $this->assertSame(4.9, $service->fromDistanceKm(4, null, 'Oignies', '62590'));
        $this->assertSame(5.9, $service->fromDistanceKm(6, null, 'Oignies', '62590'));
        $this->assertSame(6.9, $service->fromDistanceKm(9.5, null, 'Lens', '62300'));

        $this->expectException(\App\Exceptions\Delivery\OutsideDeliveryZoneException::class);
        $service->fromDistanceKm(10.5, null, 'Lens', '62300');
    }

    /**
     * Customer saved-address path — DeliveryQuoteService is the gateway and
     * MUST now propagate the branch to the fee calc.
     */
    public function test_customer_saved_address_quote_uses_branch_configured_fee(): void
    {
        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        $branch = Branch::factory()->create([
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);
        $customer = User::factory()->create([
            'branch_id' => $branch->id,
            'status' => Status::ACTIVE,
        ]);
        $address = Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Home',
            'address' => '10 Rue de Test',
            'latitude' => '48.9106',   // ≈ 6,0 km du restaurant
            'longitude' => '2.3522',
            'city' => 'Oignies',
            'postal_code' => '62590',
            'geocode_status' => 'OK',
        ]);
        $item = $this->activeItem();

        $response = $this
            ->actingAs($customer, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', [
                'branch_id' => $branch->id,
                'subtotal' => 10,
                'discount' => 0,
                'delivery_charge' => 999, // forged — backend recomputes
                'delivery_distance_km' => 0,
                'total' => 999,
                'order_type' => (string) OrderType::DELIVERY,
                'is_advance_order' => Ask::NO,
                'address_id' => $address->id,
                'delivery_time' => now()->addHour()->format('Y-m-d H:i:s'),
                'source' => Source::WEB,
                'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'item_variations' => [],
                    'item_extras' => [],
                ]]),
            ]);

        $this->assertContains($response->status(), [200, 201], $response->getContent());

        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $response->json('data.id'));
        // Barème propriétaire : autre commune à ≈ 6 km → palier « ≤ 8 km » = 5,90 €, jamais le 999 forgé.
        $this->assertSame(5.9, (float) $order->delivery_charge,
            'La commande web DOIT être facturée par le barème serveur (commune + rayon), pas par le client.');
    }

    /**
     * POS walk-in path — PosOrderRequest::prepareForValidation is the gateway.
     */
    public function test_pos_quote_prices_from_the_customers_saved_address(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        // Client de la caisse avec adresse enregistrée à ≈ 6 km, commune hors Hénin-Beaumont.
        $branch = Branch::factory()->create([
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);
        $customer = User::factory()->create(['branch_id' => $branch->id, 'status' => Status::ACTIVE]);
        $address = Address::query()->create([
            'user_id' => $customer->id,
            'label' => 'Domicile',
            'address' => '10 Rue de Test',
            'latitude' => '48.9106',
            'longitude' => '2.3522',
            'city' => 'Oignies',
            'postal_code' => '62590',
            'geocode_status' => 'OK',
        ]);
        $operator = User::factory()->create(['branch_id' => $branch->id]);
        $operator->assignRole('POS Operator');
        $operator->givePermissionTo('pos');

        $tax = Tax::factory()->create([
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);

        $response = $this->actingAs($operator, 'sanctum')
            ->postJson('/api/admin/pos/quote', [
                'branch_id' => $branch->id,
                'discount' => 0,
                'delivery_charge' => 999, // forged
                'delivery_distance_km' => 0.1, // forgé : ignoré
                'customer_id' => $customer->id,
                'address_id' => $address->id,
                'order_type' => OrderType::DELIVERY,
                'source' => 1,
                'pos_payment_method' => PosPaymentMethod::CASH,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'item_variations' => [],
                    'item_extras' => [],
                ]]),
            ]);

        $response->assertOk();
        // Barème serveur : ≈ 6 km hors commune = 5,90 €, malgré 999 € et 0,1 km envoyés par le client.
        $this->assertSame(5.9, (float) $response->json('data.delivery_charge'),
            'La caisse DOIT facturer par le barème serveur depuis l\'adresse du client.');

        $quote = OrderQuote::firstOrFail();
        $this->assertSame(5.9, (float) $quote->canonical_payload['fees']['delivery_charge']);
    }

    /** Sans commune connue, on tarife hors commune (jamais le forfait Hénin-Beaumont par défaut). */
    public function test_unknown_city_never_gets_the_home_flat_fee(): void
    {
        $this->assertSame(4.9, (new DeliveryFeeService())->fromDistanceKm(3, null));
    }

    /**
     * Active-item factory copying the DeliveryFeeForgeWebTest convention.
     */
    private function activeItem(): Item
    {
        $tax = Tax::factory()->create([
            'type' => TaxType::PERCENTAGE,
            'tax_rate' => 0,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);

        return Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);
    }
}
