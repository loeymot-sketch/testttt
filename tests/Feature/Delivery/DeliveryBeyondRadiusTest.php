<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Exceptions\Delivery\OutsideDeliveryZoneException;
use App\Models\Address;
use App\Models\Branch;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [LIVRAISON 2026-10-20] Au-delà de 10 km (hors Hénin-Beaumont) la livraison est REFUSÉE : 422
 * OUTSIDE_DELIVERY_ZONE + suggest_uber_eats, et AUCUNE commande n'est créée. Une adresse de
 * Hénin-Beaumont reste servie au forfait de 3,90 € quelle que soit sa distance au restaurant.
 *
 * Géométrie : 1° de latitude ≈ 111,19 km ; +0,108° ≈ 12,0 km.
 *
 * @group delivery
 */
class DeliveryBeyondRadiusTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $customer;
    private Item $item;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedMinimalSettings();
        config(['app.api_key' => 'test-api-key']);
        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        $this->branch = Branch::factory()->create(['latitude' => '48.8566', 'longitude' => '2.3522']);
        $this->customer = User::factory()->create(['branch_id' => $this->branch->id, 'status' => Status::ACTIVE]);

        $tax = Tax::factory()->create(['tax_rate' => 0, 'type' => TaxType::PERCENTAGE, 'status' => Status::ACTIVE]);
        $category = ItemCategory::factory()->create(['status' => Status::ACTIVE]);
        $this->item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);
    }

    public function test_address_twelve_km_away_in_another_commune_is_refused_and_creates_no_order(): void
    {
        $address = $this->addressTwelveKmAway('Lens', '62300');

        $this->postWebDelivery($address)
            ->assertStatus(422)
            ->assertJsonPath('code', OutsideDeliveryZoneException::ERROR_CODE)
            ->assertJsonPath('suggest_uber_eats', true);

        $this->assertSame(0, FrontendOrder::withoutGlobalScopes()->count(), 'Aucune commande ne doit être créée au-delà de 10 km.');
    }

    public function test_same_address_in_henin_beaumont_is_accepted_at_flat_3_90(): void
    {
        $address = $this->addressTwelveKmAway('Hénin-Beaumont', '62110');

        $response = $this->postWebDelivery($address);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $response->json('data.id'));
        $this->assertSame(3.9, (float) $order->delivery_charge);
        $this->assertSame(13.9, (float) $order->total);
    }

    private function addressTwelveKmAway(string $city, string $postalCode): Address
    {
        return Address::query()->create([
            'user_id' => $this->customer->id,
            'label' => 'Home',
            'address' => '10 Rue de Test',
            'latitude' => '48.9646',   // +0,108° ≈ 12,0 km
            'longitude' => '2.3522',
            'city' => $city,
            'postal_code' => $postalCode,
            'geocode_status' => 'OK',
        ]);
    }

    private function postWebDelivery(Address $address)
    {
        return $this
            ->actingAs($this->customer, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', [
                'branch_id' => $this->branch->id,
                'subtotal' => 10,
                'discount' => 0,
                'delivery_charge' => 0,        // forgé : ignoré
                'delivery_distance_km' => 1,   // forgé : ignoré
                'total' => 10,
                'order_type' => (string) OrderType::DELIVERY,
                'is_advance_order' => Ask::NO,
                'address_id' => $address->id,
                'delivery_time' => now()->addHour()->format('Y-m-d H:i:s'),
                'source' => Source::WEB,
                'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'items' => json_encode([[
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'item_variations' => [],
                    'item_extras' => [],
                ]]),
            ]);
    }
}
