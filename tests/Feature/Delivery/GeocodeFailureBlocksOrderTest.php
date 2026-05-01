<?php

namespace Tests\Feature\Delivery;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
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

class GeocodeFailureBlocksOrderTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_delivery_with_non_geocoded_address_returns_geocode_failed(): void
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
            'address' => 'Adresse impossible',
            'latitude' => '',
            'longitude' => '',
        ]);
        $item = $this->activeItem();

        $this
            ->actingAs($customer, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            ->postJson('/api/frontend/order', [
                'branch_id' => $branch->id,
                'subtotal' => 10,
                'discount' => 0,
                'delivery_charge' => 5,
                'delivery_distance_km' => 1,
                'total' => 15,
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
            ])
            ->assertStatus(422)
            ->assertJsonPath('code', 'GEOCODE_FAILED')
            ->assertJsonPath('message', 'Adresse invalide. Veuillez en saisir une autre.');

        $this->assertSame(0, FrontendOrder::withoutGlobalScopes()->count());
    }

    private function activeItem(): Item
    {
        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create([
            'status' => Status::ACTIVE,
        ]);

        return Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);
    }
}
