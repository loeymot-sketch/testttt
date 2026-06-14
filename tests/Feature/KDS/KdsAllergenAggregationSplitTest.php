<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Lot 2.I — G-5 (split lines by allergens snapshot).
 *
 * Two order_items with the same item_id+variations+extras+instruction MUST
 * appear as 2 distinct KDS lines if their allergens_snapshot differ.
 * Otherwise the chef sees an aggregated card with the FIRST item's allergens
 * only — masking the second customer's declared allergy. Food safety bug.
 */
class KdsAllergenAggregationSplitTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $chef;
    private Item $burgerItem;
    private Tax $tax;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        // [WG-4 TZ-test-drift V1.0.X 2026-05-19] Pin Carbon::setTestNow to a
        // fixed UTC instant inside the Paris business day so test fixtures
        // `now()` and the KitchenDisplaySystemOrderService::orderItems()
        // Paris→UTC bound conversion (Wave 3b heal c2613cab0) resolve
        // deterministically across the day. See
        // reports/audit/foundation-2026-05-18/failures/V1_0_X_BACKLOG_KDS_TZ_FIX.md.
        Carbon::setTestNow(Carbon::create(2026, 5, 18, 12, 0, 0, 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 5, 18, 12, 0, 0, 'UTC'));

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::forceCreate([
            'name' => 'KDS Allergen Split Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '12 rue split',
            'status' => 1,
        ]);

        $this->chef = User::forceCreate([
            'name' => 'Chef Split',
            'email' => 'chef-split@test.local',
            'username' => 'chef_split',
            'phone' => '0600000050',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => 1,
        ]);
        $this->chef->assignRole('Chef');

        $this->tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Burgers',
            'slug' => 'burgers-split',
            'status' => Status::ACTIVE,
        ]);

        $this->burgerItem = Item::forceCreate([
            'name' => 'Cheeseburger',
            'slug' => 'cheeseburger-split',
            'price' => 9.50,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $this->tax->id,
        ]);

        $customer = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->order = Order::forceCreate([
            'order_serial_no' => 'KDS-SPLIT-001',
            'user_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => 1,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'subtotal' => 19.0,
            'total' => 19.0,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
        ]);
    }

    protected function tearDown(): void
    {
        // [WG-4] Carbon::setTestNow() leaks across tests if not cleared.
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * G-5 PRIMARY GUARANTEE — DIFFERENT allergens MUST split.
     */
    public function test_two_items_same_id_same_extras_DIFFERENT_allergens_are_NOT_merged(): void
    {
        $this->makeBurger(['arachides']);
        $this->makeBurger(['gluten']);

        $items = $this->actingAsChefAndCallOrderItems();

        $matching = $items->where('item_id', $this->burgerItem->id);
        $this->assertCount(2, $matching, 'Items with different allergens MUST appear as 2 distinct lines');

        $quantities = $matching->pluck('quantity')->all();
        $this->assertEquals([1, 1], $quantities, 'Quantities MUST NOT be summed across allergen groups');
    }

    /**
     * Sanity / regression — SAME allergens MUST still merge (quantity=2).
     */
    public function test_two_items_same_id_same_extras_SAME_allergens_ARE_merged(): void
    {
        $this->makeBurger(['arachides']);
        $this->makeBurger(['arachides']);

        $items = $this->actingAsChefAndCallOrderItems();

        $matching = $items->where('item_id', $this->burgerItem->id);
        $this->assertCount(1, $matching, 'Identical allergens MUST collapse to a single KDS line');
        $this->assertSame(2, (int) $matching->first()['quantity']);
    }

    /**
     * Defensive — null and empty array MUST hash to the same value (merge).
     */
    public function test_items_with_NULL_allergens_snapshot_merge_with_items_with_EMPTY_array(): void
    {
        $this->makeBurger(null);
        $this->makeBurger([]);

        $items = $this->actingAsChefAndCallOrderItems();

        $matching = $items->where('item_id', $this->burgerItem->id);
        $this->assertCount(1, $matching, 'null and [] allergens MUST collapse together');
        $this->assertSame(2, (int) $matching->first()['quantity']);
    }

    /**
     * Determinism — input order of allergens MUST NOT affect the hash (sort).
     */
    public function test_items_with_unsorted_allergens_arrays_merge_correctly(): void
    {
        $this->makeBurger(['arachides', 'gluten']);
        $this->makeBurger(['gluten', 'arachides']);

        $items = $this->actingAsChefAndCallOrderItems();

        $matching = $items->where('item_id', $this->burgerItem->id);
        $this->assertCount(1, $matching, 'Allergens with different ordering MUST hash to the same key');
        $this->assertSame(2, (int) $matching->first()['quantity']);
    }

    /**
     * G-5 — deux *commandes* distinctes, même item + extras, allergènes différents
     * → le chef voit 2 lignes sur l’items board (pluck global), pas 1 ligne fusionnée.
     */
    public function test_two_orders_same_item_different_allergens_are_NOT_merged_across_orders(): void
    {
        $this->makeBurger(['arachides']);
        $otherCustomer = User::factory()->create(['branch_id' => $this->branch->id]);
        $order2 = Order::forceCreate([
            'order_serial_no' => 'KDS-SPLIT-002',
            'user_id' => $otherCustomer->id,
            'branch_id' => $this->branch->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => 1,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'subtotal' => 9.5,
            'total' => 9.5,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
        ]);
        OrderItem::forceCreate([
            'order_id' => $order2->id,
            'branch_id' => $this->branch->id,
            'item_id' => $this->burgerItem->id,
            'quantity' => 1,
            'discount' => 0,
            'tax_name' => 'TVA 10',
            'tax_rate' => 10,
            'tax_type' => 2,
            'tax_amount' => 0.95,
            'price' => 9.50,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 9.50,
            'instruction' => null,
            'allergens_snapshot' => ['gluten'],
        ]);

        $items = $this->actingAsChefAndCallOrderItems();
        $matching = $items->where('item_id', $this->burgerItem->id);
        $this->assertCount(2, $matching, 'Inter-order allergen groups MUST NOT merge into one line');
    }

    /**
     * FOOD-SAFETY (massive-2dot0 #9) — a DOUBLE-ENCODED allergens_snapshot (a JSON
     * *string* of a JSON array, a legacy-write artifact) MUST normalize to the same
     * hash as the correctly-encoded identical allergens, so the two items merge.
     * Before the heal the Eloquent 'array' cast decoded only once → the value
     * arrived at normalizeAllergensForHash as a string → collapsed to [] → the two
     * identical-allergen items split into 2 lines AND the allergen-aware merge key
     * silently lost the codes (chef could miss a declared allergy).
     */
    public function test_double_encoded_allergens_snapshot_merges_with_correctly_encoded(): void
    {
        $this->makeBurger(['gluten']);            // correctly stored ["gluten"]
        $this->makeBurger(['gluten']);            // will be corrupted to double-encoded below
        $second = OrderItem::where('order_id', $this->order->id)->latest('id')->first();
        // Raw column becomes "[\"gluten\"]" — a JSON string of the array. The
        // 'array' cast decodes once on read → a string reaches the hash function.
        \Illuminate\Support\Facades\DB::table('order_items')
            ->where('id', $second->id)
            ->update(['allergens_snapshot' => json_encode(json_encode(['gluten']))]);

        $items = $this->actingAsChefAndCallOrderItems();
        $matching = $items->where('item_id', $this->burgerItem->id);

        $this->assertCount(1, $matching, 'Double-encoded allergens MUST normalize and merge with the identical correctly-encoded allergens');
        $this->assertSame(2, (int) $matching->first()['quantity']);
    }

    private function makeBurger(?array $allergens): void
    {
        OrderItem::forceCreate([
            'order_id' => $this->order->id,
            'branch_id' => $this->branch->id,
            'item_id' => $this->burgerItem->id,
            'quantity' => 1,
            'discount' => 0,
            'tax_name' => 'TVA 10',
            'tax_rate' => 10,
            'tax_type' => 2,
            'tax_amount' => 0.95,
            'price' => 9.50,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 9.50,
            'instruction' => null,
            'allergens_snapshot' => $allergens,
        ]);
    }

    private function actingAsChefAndCallOrderItems(): \Illuminate\Support\Collection
    {
        $this->actingAs($this->chef, 'sanctum');
        return collect(app(KitchenDisplaySystemOrderService::class)->orderItems());
    }
}
