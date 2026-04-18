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

class OrderAllergenSnapshotTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;
    protected User $kioskUser;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();

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

        $this->branch = Branch::forceCreate([
            'name' => 'Allergen Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue allergene',
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
            'name' => 'Burgers',
            'slug' => 'burgers-allergen-test',
            'status' => Status::ACTIVE,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'Snapshot Burger',
            'slug' => 'snapshot-burger',
            'price' => 12.50,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $this->kioskUser = User::factory()->create([
            'username' => 'kiosk_allergen',
            'branch_id' => $this->branch->id,
        ]);

        KioskMachine::create([
            'machine_id' => 'allergen-machine-01',
            'branch_id' => $this->branch->id,
            'user_id' => $this->kioskUser->id,
            'username' => 'kiosk-allergen',
            'password' => bcrypt('secret'),
            'is_login' => Ask::NO,
            'status' => Status::ACTIVE,
        ]);
    }

    public function test_kiosk_order_stores_allergens_snapshot_from_pivot(): void
    {
        $gluten = Allergen::forceCreate(['code' => 'gluten', 'name_key' => 'allergens.gluten', 'sort' => 1]);
        $lactose = Allergen::forceCreate(['code' => 'lactose', 'name_key' => 'allergens.lactose', 'sort' => 2]);
        $this->item->allergens()->sync([
            $gluten->id => ['is_trace' => false],
            $lactose->id => ['is_trace' => false],
        ]);

        $orderItem = $this->placeKioskOrder();
        $storedSnapshot = json_decode((string) DB::table('order_items')->where('id', $orderItem->id)->value('allergens_snapshot'), true);

        $this->assertEqualsCanonicalizing(['gluten', 'lactose'], $storedSnapshot);
        $this->assertEqualsCanonicalizing(['gluten', 'lactose'], $orderItem->fresh()->allergens_snapshot ?? []);
    }

    public function test_snapshot_remains_immutable_after_item_allergens_change(): void
    {
        $gluten = Allergen::forceCreate(['code' => 'gluten', 'name_key' => 'allergens.gluten', 'sort' => 1]);
        $lactose = Allergen::forceCreate(['code' => 'lactose', 'name_key' => 'allergens.lactose', 'sort' => 2]);
        $oeufs = Allergen::forceCreate(['code' => 'oeufs', 'name_key' => 'allergens.oeufs', 'sort' => 3]);

        $this->item->allergens()->sync([
            $gluten->id => ['is_trace' => false],
            $lactose->id => ['is_trace' => false],
        ]);

        $orderItem = $this->placeKioskOrder();

        $this->item->allergens()->sync([
            $oeufs->id => ['is_trace' => false],
        ]);

        $this->assertEqualsCanonicalizing(['gluten', 'lactose'], $orderItem->fresh()->allergens_snapshot ?? []);
    }

    private function placeKioskOrder(): OrderItem
    {
        Sanctum::actingAs($this->kioskUser, ['kiosk:order']);
        Event::fake([OrderCreated::class, OrderStatusChanged::class]);

        $response = $this
            ->withHeader('x-api-key', '123456')
            ->postJson('/api/frontend/order', [
                'branch_id' => $this->branch->id,
                'subtotal' => 12.50,
                'discount' => 0,
                'delivery_charge' => 0,
                'total' => 12.50,
                'order_type' => OrderType::TAKEAWAY,
                'is_advance_order' => Ask::NO,
                'source' => Source::APP,
                'payment_method' => PaymentGateway::CASH_ON_DELIVERY,
                'items' => json_encode([[
                    'item_id' => $this->item->id,
                    'quantity' => 1,
                    'item_variations' => [],
                    'item_extras' => [],
                ]]),
            ]);

        $this->assertContains($response->status(), [200, 201], json_encode($response->json()));

        $orderId = (int) $response->json('data.id');

        return OrderItem::query()->where('order_id', $orderId)->sole();
    }
}
