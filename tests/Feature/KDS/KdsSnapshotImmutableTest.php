<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\User;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Http\Resources\KDSOrderItemsResource;
use App\Http\Resources\OrderItemResource;
use App\Services\KitchenDisplaySystemOrderService;
use App\Services\Menu\AvailabilityService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * K-2 ADR-5 — KDS snapshot invariant.
 *
 * A committed order is a contract with the kitchen. Once persisted, a later
 * availability change (POS toggles item 86, branch rupture, service rearm)
 * MUST NOT mutate:
 *   - `order_items.item_id` / pricing snapshot
 *   - `order_items.item_variations` / `item_extras`
 *   - `orders.total` / `subtotal`
 *   - The set of OrderItem rows attached to the order
 *
 * This test proves that calling {@see AvailabilityService::toggle()} after the
 * order is persisted leaves the order unchanged at the DB level and does not
 * delete / soft-delete any OrderItem.
 */
class KdsSnapshotImmutableTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    public function test_toggling_availability_does_not_mutate_existing_order(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $category = ItemCategory::firstOrCreate(
            ['slug' => 'kds-cat'],
            ['name' => 'KDS cat', 'status' => 5]
        );
        $item = Item::forceCreate([
            'name' => 'Persisted burger',
            'slug' => 'persisted-burger',
            'price' => 12,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);

        $order = Order::create([
            'order_serial_no' => '20260418-K2-001',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 12.0,
            'total' => 12.0,
            'order_type' => OrderType::TAKEAWAY,
            'order_datetime' => now(),
            'is_advance_order' => 0,
            'source' => 5,
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 2,
            'price' => 24,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 24,
            'item_variations' => '[]',
            'item_extras' => '[]',
            'instruction' => '',
        ]);

        $snapshotOrder = $order->fresh()->toArray();
        $snapshotItem = $orderItem->fresh()->toArray();

        // Flip the item to unavailable — post-commit.
        app(AvailabilityService::class)->toggle(
            itemId: (int) $item->id,
            branchId: (int) $branch->id,
            available: false,
            reason: 'out_of_stock'
        );

        $this->assertDatabaseHas('item_branch_availability', [
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => 0,
        ]);

        // Verify the order snapshot is byte-for-byte stable (ignoring timestamps).
        $reloadedOrder = $order->fresh()->toArray();
        $reloadedItem = $orderItem->fresh()->toArray();

        foreach (['subtotal', 'total', 'status', 'branch_id', 'order_type'] as $k) {
            $this->assertEquals($snapshotOrder[$k], $reloadedOrder[$k], "Order field {$k} must not mutate");
        }
        foreach (['item_id', 'quantity', 'price', 'total_price', 'item_variations', 'item_extras'] as $k) {
            $this->assertEquals($snapshotItem[$k], $reloadedItem[$k], "OrderItem field {$k} must not mutate");
        }

        // And the row is still attached (no soft delete cascade).
        $this->assertDatabaseHas('order_items', [
            'id' => $orderItem->id,
            'order_id' => $order->id,
            'item_id' => $item->id,
        ]);

        // Event was dispatched (proof the toggle really flipped state).
        Event::assertDispatched(ItemAvailabilityChanged::class);
    }

    public function test_rearming_availability_also_does_not_mutate_orders(): void
    {
        Event::fake([ItemAvailabilityChanged::class]);

        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $category = ItemCategory::firstOrCreate(
            ['slug' => 'kds-cat2'],
            ['name' => 'KDS cat 2', 'status' => 5]
        );
        $item = Item::forceCreate([
            'name' => 'Rearm burger',
            'slug' => 'rearm-burger',
            'price' => 7,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);
        ItemBranchAvailability::forceCreate([
            'item_id' => $item->id,
            'branch_id' => $branch->id,
            'is_available' => false,
            'unavailable_reason' => 'out_of_stock',
            'unavailable_since' => now(),
            'daily_consumed_qty' => 0,
            'daily_reset_at' => now()->toDateString(),
        ]);

        $order = Order::create([
            'order_serial_no' => '20260418-K2-002',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 7.0,
            'total' => 7.0,
            'order_type' => OrderType::TAKEAWAY,
            'order_datetime' => now(),
            'is_advance_order' => 0,
            'source' => 5,
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 7,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 7,
            'item_variations' => '[]',
            'item_extras' => '[]',
            'instruction' => '',
        ]);

        $snapshotOrder = $order->fresh()->toArray();

        app(AvailabilityService::class)->toggle(
            itemId: (int) $item->id,
            branchId: (int) $branch->id,
            available: true,
            reason: null
        );

        $reloadedOrder = $order->fresh()->toArray();
        foreach (['subtotal', 'total', 'status', 'branch_id'] as $k) {
            $this->assertEquals($snapshotOrder[$k], $reloadedOrder[$k], "Order {$k} must not mutate on rearm");
        }
        $this->assertDatabaseHas('order_items', [
            'id' => $orderItem->id,
            'item_id' => $item->id,
        ]);
    }

    public function test_kds_order_item_resource_exposes_composer_addons_from_snapshot(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $category = ItemCategory::firstOrCreate(
            ['slug' => 'kds-addon-cat'],
            ['name' => 'KDS addon cat', 'status' => 5]
        );
        $item = Item::forceCreate([
            'name' => 'Tacos Addon KDS',
            'slug' => 'tacos-addon-kds',
            'price' => 12,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);
        $order = Order::create([
            'order_serial_no' => '20260430-KDS-ADDON',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 12.0,
            'total' => 12.0,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => now(),
            'is_advance_order' => 0,
            'source' => 5,
        ]);
        $orderItem = OrderItem::create([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => 1,
            'price' => 12,
            'discount' => 0,
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 12,
            'item_variations' => '[]',
            'item_extras' => '[]',
            'composition_snapshot' => [
                'schema_version' => 1,
                'addons' => [
                    [
                        'addon_id' => 77,
                        'addon_item_id' => 990,
                        'addon_name' => 'Coca 33cl',
                        'role' => 'drink',
                        'quantity' => 2,
                    ],
                ],
            ],
            'instruction' => '',
        ]);

        $payload = (new KDSOrderItemsResource($orderItem->fresh()))->toArray(request());
        $detailsPayload = (new OrderItemResource($orderItem->fresh()))->toArray(request());

        $this->assertSame('Coca 33cl', $payload['item_addons'][0]['addon_name']);
        $this->assertSame(2, $payload['item_addons'][0]['quantity']);
        $this->assertSame('Coca 33cl', $detailsPayload['item_addons'][0]['addon_name']);
        $this->assertSame(2, $detailsPayload['item_addons'][0]['quantity']);
    }

    public function test_kds_items_board_keeps_distinct_addon_choices_unmerged(): void
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create(['branch_id' => $branch->id]);
        $category = ItemCategory::firstOrCreate(
            ['slug' => 'kds-addon-merge-cat'],
            ['name' => 'KDS addon merge cat', 'status' => 5]
        );
        $item = Item::forceCreate([
            'name' => 'Tacos Addon Merge KDS',
            'slug' => 'tacos-addon-merge-kds',
            'price' => 12,
            'status' => 5,
            'item_category_id' => $category->id,
        ]);
        $order = Order::create([
            'order_serial_no' => '20260430-KDS-ADDON-MERGE',
            'branch_id' => $branch->id,
            'user_id' => $user->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'subtotal' => 24.0,
            'total' => 24.0,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => now(),
            'is_advance_order' => Ask::NO,
            'source' => 5,
        ]);

        foreach ([['Coca 33cl', 77], ['Fanta 33cl', 78]] as [$addonName, $addonId]) {
            OrderItem::create([
                'order_id' => $order->id,
                'branch_id' => $branch->id,
                'item_id' => $item->id,
                'quantity' => 1,
                'price' => 12,
                'discount' => 0,
                'item_variation_total' => 0,
                'item_extra_total' => 0,
                'total_price' => 12,
                'item_variations' => '[]',
                'item_extras' => '[]',
                'composition_snapshot' => [
                    'schema_version' => 1,
                    'addons' => [
                        [
                            'addon_id' => $addonId,
                            'addon_item_id' => 900 + $addonId,
                            'addon_name' => $addonName,
                            'role' => 'drink',
                            'quantity' => 1,
                        ],
                    ],
                ],
                'instruction' => '',
            ]);
        }

        $this->actingAs($user);

        $items = app(KitchenDisplaySystemOrderService::class)->orderItems();
        $matched = $items->where('item_id', $item->id)->values();

        $this->assertCount(2, $matched);
    }
}
