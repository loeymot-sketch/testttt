<?php

namespace Tests\Feature\Orders;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemAttribute;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\KioskMachine;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use App\Services\CouponService;
use App\Services\Pricing\PricingRequest;
use App\Services\Pricing\PricingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

class KioskIdsOnlyPayloadTest extends TestCase
{
    use RefreshDatabase;

    public function test_kiosk_ids_only_payload_is_accepted_and_total_is_recomputed_server_side(): void
    {
        $this->seedMinimalSettings();
        config(['app.api_key' => '123456']);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        $branch = Branch::forceCreate([
            'name' => 'IDs Only Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '50 rue ssot',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'SSOT Category',
            'slug' => 'ssot-category',
            'status' => Status::ACTIVE,
        ]);

        $attribute = ItemAttribute::query()->create([
            'name' => 'Taille',
            'item_category_id' => $category->id,
            'is_required' => 0,
            'max_selection' => 1,
        ]);

        $item = Item::forceCreate([
            'name' => 'SSOT Burger',
            'slug' => 'ssot-burger',
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $variation = ItemVariation::query()->create([
            'item_id' => $item->id,
            'item_attribute_id' => $attribute->id,
            'name' => 'XL',
            'price' => 1.50,
            'status' => Status::ACTIVE,
        ]);

        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Cheddar',
            'price' => 2.00,
            'status' => Status::ACTIVE,
        ]);

        $kioskUser = User::factory()->create([
            'username' => 'kiosk_ids_only',
            'branch_id' => $branch->id,
        ]);

        KioskMachine::create([
            'machine_id' => 'ids-only-machine-01',
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
            'username' => 'kiosk-ids-only',
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        $payload = [
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 2,
                'instruction' => 'sans sauce',
                'item_variations' => [['id' => $variation->id]],
                'item_extras' => [['id' => $extra->id]],
            ]]),
        ];

        $pricingService = new PricingService();
        $expectedPricing = $pricingService->calculateOrder(
            PricingRequest::forKiosk(
                0,
                $branch->id,
                json_decode($payload['items']) ?: [],
                0,
                (int) $kioskUser->id,
                0.0
            ),
            app(CouponService::class)
        );

        Event::fake([OrderCreated::class, OrderStatusChanged::class]);
        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        $quote = $this
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        // [prod-finale 2026-06-17] idempotency-guarded route requires X-Idempotency-Key (frozen middleware; live UI sends it).
        $response = $this
            ->withHeader('x-api-key', '123456')
            ->withHeader('X-Idempotency-Key', (string) Str::uuid())
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
            ]);

        $this->assertContains($response->status(), [200, 201], json_encode($response->json()));

        $orderId = (int) ($response->json('data.id') ?? $response->json('id'));
        $order = Order::withoutGlobalScopes()->findOrFail($orderId);

        $this->assertSame($branch->id, (int) $order->branch_id);
        $this->assertEqualsWithDelta($expectedPricing->total, (float) $order->total, 0.0001);
        $this->assertEqualsWithDelta($expectedPricing->subtotal, (float) $order->subtotal, 0.0001);
        $this->assertDatabaseHas('order_items', [
            'order_id' => $order->id,
            'item_id' => $item->id,
            'quantity' => 2,
        ]);
    }
}
