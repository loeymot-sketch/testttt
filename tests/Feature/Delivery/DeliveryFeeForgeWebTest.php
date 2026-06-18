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
use Illuminate\Support\Str;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class DeliveryFeeForgeWebTest extends TestCase
{
    use RefreshDatabase;

    public function test_web_delivery_fee_is_recomputed_from_saved_address_coordinates(): void
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
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);
        $item = $this->activeItem();

        $response = $this
            ->actingAs($customer, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', [
                'branch_id' => $branch->id,
                'subtotal' => 10,
                'discount' => 0,
                'delivery_charge' => 999,
                'delivery_distance_km' => 100,
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
        $this->assertSame(5.0, (float) $order->delivery_charge);
        $this->assertSame(15.0, (float) $order->total);
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
