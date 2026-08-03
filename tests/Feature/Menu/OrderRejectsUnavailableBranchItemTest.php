<?php

namespace Tests\Feature\Menu;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\KioskMachine;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * [P1] Commande kiosk / frontend rejetée si article en rupture branche (item_branch_availability).
 */
class OrderRejectsUnavailableBranchItemTest extends TestCase
{
    use RefreshDatabase;

    public function test_frontend_order_rejects_item_marked_unavailable_for_branch(): void
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
            'name' => 'P1 Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue test',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10P1',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'P1 Cat',
            'slug' => 'p1-cat',
            'status' => Status::ACTIVE,
        ]);

        $item = Item::forceCreate([
            'name' => 'P1 Burger',
            'slug' => 'p1-burger',
            'price' => 8.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $kioskUser = User::factory()->create([
            'username' => 'kiosk_p1_rupture',
            'branch_id' => $branch->id,
        ]);

        KioskMachine::create([
            'machine_id' => 'p1-machine',
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
            'username' => 'p1-k',
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
                'quantity' => 1,
                'instruction' => '',
                'item_variations' => [],
                'item_extras' => [],
            ]]),
        ];

        Sanctum::actingAs($kioskUser, ['kiosk:order']);

        $quote = $this
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        ItemBranchAvailability::query()->create([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'stock_rupture',
            'unavailable_since' => now(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
            'max_daily_qty' => null,
        ]);

        $response = $this
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
            ]);

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('disponible', /* [UX-86] msg nommé « n'est plus disponible » */ (string) $response->json('message'));
    }

    public function test_frontend_quote_rejects_item_globally_marked_unavailable(): void
    {
        $fixture = $this->makeKioskQuoteFixture([
            'is_available' => false,
        ]);

        $response = $this
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order/quote', $fixture['payload']);

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('disponible', /* [UX-86] msg nommé « n'est plus disponible » */ (string) $response->json('message'));
    }

    public function test_frontend_quote_rejects_inactive_supplement_id(): void
    {
        $fixture = $this->makeKioskQuoteFixture();

        $extra = ItemExtra::create([
            'item_id' => $fixture['item']->id,
            'name' => 'Cheddar rupture',
            'price' => 1.50,
            'status' => Status::INACTIVE,
        ]);

        $payload = $fixture['payload'];
        $payload['items'] = json_encode([[
            'item_id' => $fixture['item']->id,
            'quantity' => 1,
            'instruction' => '',
            'item_variations' => [],
            'item_extras' => [
                ['id' => $extra->id],
            ],
        ]]);

        $response = $this
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order/quote', $payload);

        $response->assertStatus(422);
        $this->assertStringContainsStringIgnoringCase('supplément', (string) $response->json('message'));
    }

    private function makeKioskQuoteFixture(array $itemOverrides = []): array
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
            'name' => 'P1 Branch Quote',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '2 rue test',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10 Quote',
            'code' => 'TVA10Q' . uniqid(),
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'P1 Cat Quote',
            'slug' => 'p1-cat-quote-' . uniqid(),
            'status' => Status::ACTIVE,
        ]);

        $item = Item::forceCreate(array_merge([
            'name' => 'P1 Burger Quote',
            'slug' => 'p1-burger-quote-' . uniqid(),
            'price' => 8.00,
            'status' => Status::ACTIVE,
            'is_available' => true,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ], $itemOverrides));

        $kioskUser = User::factory()->create([
            'username' => 'kiosk_p1_quote_' . uniqid(),
            'branch_id' => $branch->id,
        ]);

        KioskMachine::create([
            'machine_id' => 'p1-machine-quote-' . uniqid(),
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
            'username' => 'p1-k-' . uniqid(),
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);

        return [
            'branch' => $branch,
            'item' => $item,
            'payload' => [
                'order_type' => OrderType::TAKEAWAY,
                'is_advance_order' => Ask::NO,
                'source' => Source::APP,
                'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'items' => json_encode([[
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'instruction' => '',
                    'item_variations' => [],
                    'item_extras' => [],
                ]]),
            ],
        ];
    }
}
