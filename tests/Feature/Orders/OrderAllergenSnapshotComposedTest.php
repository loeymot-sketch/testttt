<?php

namespace Tests\Feature\Orders;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PaymentGateway;
use App\Enums\Source;
use App\Enums\Status;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Allergen;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\ItemExtra;
use App\Models\KioskMachine;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Laravel\Sanctum\Sanctum;
use Smartisan\Settings\Facades\Settings;
use Tests\TestCase;

/**
 * Sentinel W3.A — documente le gap backend : snapshot commandé devrait inclure
 * les allergènes des extras composés. Peut rester rouge (FINDING_BACK_DEFERRED).
 */
class OrderAllergenSnapshotComposedTest extends TestCase
{
    use RefreshDatabase;

    /*
     * [W8.5 HOTFIX] Le bloc setUp/tearDown qui faisait Schema::create() puis
     * Schema::dropIfExists() sur item_extra_allergens a été retiré : sous
     * MySQL 8.0, ce DDL runtime déclenchait un commit implicite qui cassait
     * la transaction RefreshDatabase et faisait fuir les fixtures du test
     * vers les classes suivantes (cause des 11 fails MenuProjectionServiceTest
     * en CI).
     *
     * La table est désormais matérialisée par la migration permanente
     * 2026_04_22_300000_create_item_extra_allergens_table.php.
     */

    public function test_sentinel_base_item_plus_extra_with_milk_should_snapshot_lait(): void
    {
        $this->seedMinimalSettings();
        config([
            'app.api_key' => '123456',
            'broadcasting.default' => 'log',
        ]);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
            'order_setup_delivery' => 5,
            'order_setup_takeaway' => 5,
        ]);

        $branch = Branch::forceCreate([
            'name' => 'Composed Allergen Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue composed',
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
            'name' => 'Tacos Test',
            'slug' => 'tacos-composed-allergen',
            'status' => Status::ACTIVE,
        ]);

        $item = Item::forceCreate([
            'name' => 'Tacos bœuf sentinel',
            'slug' => 'tacos-sentinel-allergen',
            'price' => 8.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $extra = ItemExtra::query()->create([
            'item_id' => $item->id,
            'name' => 'Fromage',
            'price' => 1.00,
            'status' => Status::ACTIVE,
        ]);

        $lait = Allergen::query()->firstOrCreate(
            ['code' => 'lait'],
            ['name_key' => 'allergens.lait', 'icon' => '🥛', 'sort' => 7],
        );
        DB::table('item_extra_allergens')->insert([
            'item_extra_id' => $extra->id,
            'allergen_id' => $lait->id,
        ]);

        $kioskUser = User::factory()->create([
            'username' => 'kiosk_composed_allergen',
            'branch_id' => $branch->id,
        ]);

        KioskMachine::create([
            'machine_id' => 'composed-allergen-machine-01',
            'branch_id' => $branch->id,
            'user_id' => $kioskUser->id,
            'username' => 'kiosk-composed-allergen',
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);

        Sanctum::actingAs($kioskUser, ['kiosk:order']);
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $base = 8.00;
        $extraPrice = 1.00;
        $total = $base + $extraPrice;

        $payload = [
            'branch_id' => $branch->id,
            'subtotal' => $total,
            'discount' => 0,
            'delivery_charge' => 0,
            'total' => $total,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
            'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
            'items' => json_encode([[
                'item_id' => $item->id,
                'quantity' => 1,
                'item_variations' => [],
                'item_extras' => [['id' => $extra->id]],
            ]]),
        ];
        $quote = $this
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order/quote', $payload)
            ->assertOk()
            ->json('data');

        $response = $this
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', $payload + [
                'quote_token' => $quote['quote_token'],
                'quote_signature' => $quote['signature'],
            ]);

        $this->assertContains($response->status(), [200, 201], json_encode($response->json()));

        $orderId = (int) $response->json('data.id');
        $orderItem = OrderItem::query()->where('order_id', $orderId)->sole();

        $this->assertSame(
            ['lait'],
            $orderItem->allergens_snapshot ?? [],
            'FINDING_BACK_DEFERRED: OrderItemAllergenSnapshot::resolveSnapshot ne fusionne pas encore les allergènes des extras.',
        );
    }
}
