<?php

namespace Tests\Feature\Frontend;

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

class OrderRequestDeliveryFeeAuthorityTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $customer;
    private Address $address;
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

        $this->branch = Branch::factory()->create([
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);
        $this->customer = User::factory()->create([
            'branch_id' => $this->branch->id,
            'status' => Status::ACTIVE,
        ]);
        $this->address = Address::query()->create([
            'user_id' => $this->customer->id,
            'label' => 'Home',
            'address' => '10 Rue de Test',
            'latitude' => '48.8566',
            'longitude' => '2.3522',
        ]);

        $tax = Tax::factory()->create([
            'tax_rate' => 0,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);
        $category = ItemCategory::factory()->create([
            'status' => Status::ACTIVE,
        ]);
        $this->item = Item::factory()->create([
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
            'price' => 10.00,
            'status' => Status::ACTIVE,
        ]);
    }

    public function test_frontend_delivery_recomputes_forged_delivery_charge_from_saved_address_coordinates(): void
    {
        $response = $this->postFrontendOrder($this->basePayload([
            'order_type' => (string) OrderType::DELIVERY,
            'delivery_charge' => 999,
            'delivery_distance_km' => 5.01,
        ]));

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $response->json('data.id'));

        $this->assertSame(5.0, (float) $order->delivery_charge);
        $this->assertSame(15.0, (float) $order->total);
    }

    public function test_frontend_delivery_zero_distance_keeps_minimum_delivery_charge(): void
    {
        $response = $this->postFrontendOrder($this->basePayload([
            'order_type' => (string) OrderType::DELIVERY,
            'delivery_charge' => 0.01,
            'delivery_distance_km' => 0,
        ]));

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $response->json('data.id'));

        $this->assertSame(5.0, (float) $order->delivery_charge);
        $this->assertSame(15.0, (float) $order->total);
    }

    public function test_frontend_delivery_requires_distance_instead_of_silent_minimum_fallback(): void
    {
        $payload = $this->basePayload([
            'order_type' => (string) OrderType::DELIVERY,
            'delivery_charge' => 5,
        ]);
        unset($payload['delivery_distance_km']);

        $this->postFrontendOrder($payload)
            ->assertStatus(422)
            ->assertJsonValidationErrors(['delivery_distance_km']);
    }

    public function test_frontend_takeaway_does_not_require_delivery_distance(): void
    {
        $payload = $this->basePayload([
            'order_type' => (string) OrderType::TAKEAWAY,
            'delivery_charge' => 0,
        ]);
        unset($payload['address_id'], $payload['delivery_time'], $payload['delivery_distance_km']);

        $response = $this->postFrontendOrder($payload);

        $this->assertContains($response->status(), [200, 201], $response->getContent());
        $order = FrontendOrder::withoutGlobalScopes()->findOrFail((int) $response->json('data.id'));

        $this->assertSame(0.0, (float) $order->delivery_charge);
        $this->assertSame(10.0, (float) $order->total);
    }

    private function postFrontendOrder(array $payload)
    {
        return $this
            ->actingAs($this->customer, 'sanctum')
            ->withHeader('x-api-key', 'test-api-key')
            // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload);
    }

    private function basePayload(array $overrides = []): array
    {
        return array_merge([
            'branch_id' => $this->branch->id,
            'subtotal' => 10,
            'discount' => 0,
            'delivery_charge' => 999,
            'delivery_distance_km' => 5.01,
            'total' => 999,
            'order_type' => (string) OrderType::DELIVERY,
            'is_advance_order' => Ask::NO,
            'address_id' => $this->address->id,
            'delivery_time' => now()->addHour()->format('Y-m-d H:i:s'),
            'source' => Source::WEB,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $this->item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ], $overrides);
    }
}
