<?php

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Status;
use App\Http\Resources\KDSOrderItemsResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Tax;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

/**
 * PK-3 (Intersection POS×KDS Wave 1 2026-05-18) — sentinel for the Z-1 KDS
 * deeper-audit finding: KDSOrderItemsResource silently omitted
 * `allergens_snapshot` while OrderItemResource (the canonical OrderItem
 * serializer used by KDSOrderDetailsResource :: order_items) already exposed
 * it. This created contract drift between the two chef-facing KDS surfaces:
 *
 *   - today-orders cards     (via KDSOrderDetailsResource → OrderItemResource) → had allergens
 *   - items-board column     (via KDSOrderItemsResource standalone)            → MISSING
 *
 * Food-safety risk is partial today (Vue template doesn't currently render
 * allergens on items-board column), but the SERIALIZED PAYLOAD must carry the
 * field as defense-in-depth — any future Vue change that adopts the unified
 * `renderItem()` helper (resources/js/helpers/kdsCustomization.js) implicitly
 * depends on the wire shape carrying allergens_snapshot to set hasAllergen.
 * This test locks the contract.
 *
 * Heal commit lives on branch v1-0-1-hardening-2026-05-17.
 */
class KdsOrderItemsResourceAllergenExposureTest extends TestCase
{
    use RefreshDatabase;

    private Branch $branch;
    private User $chef;
    private Item $burgerItem;
    private Order $order;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $this->branch = Branch::forceCreate([
            'name' => 'PK-3 Sentinel Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue contract',
            'status' => 1,
        ]);

        $this->chef = User::forceCreate([
            'name' => 'Chef PK3',
            'email' => 'chef-pk3@test.local',
            'username' => 'chef_pk3',
            'phone' => '0600000077',
            'password' => bcrypt('password123'),
            'branch_id' => $this->branch->id,
            'status' => 1,
        ]);
        $this->chef->assignRole('Chef');

        $tax = Tax::create([
            'name' => 'TVA 10 PK3',
            'code' => 'TVA10PK3',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Burgers PK3',
            'slug' => 'burgers-pk3',
            'status' => Status::ACTIVE,
        ]);

        $this->burgerItem = Item::forceCreate([
            'name' => 'PK3 Burger',
            'slug' => 'pk3-burger',
            'price' => 9.50,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $customer = User::factory()->create(['branch_id' => $this->branch->id]);

        $this->order = Order::forceCreate([
            'order_serial_no' => 'PK3-CONTRACT-001',
            'user_id' => $customer->id,
            'branch_id' => $this->branch->id,
            'status' => OrderStatus::ACCEPT,
            'payment_status' => PaymentStatus::PAID,
            'payment_method' => 1,
            'order_type' => OrderType::TAKEAWAY,
            'is_advance_order' => Ask::NO,
            'subtotal' => 9.50,
            'total' => 9.50,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
        ]);
    }

    /**
     * Primary contract assertion — when an OrderItem carries a non-empty
     * allergens_snapshot, the KDSOrderItemsResource toArray() MUST include
     * the field with the exact (sorted, normalized) value.
     */
    public function test_kds_order_items_resource_exposes_allergens_snapshot(): void
    {
        $orderItem = $this->makeBurger(['gluten', 'arachides']);

        $payload = (new KDSOrderItemsResource($orderItem))->toArray(Request::create('/'));

        $this->assertArrayHasKey(
            'allergens_snapshot',
            $payload,
            'PK-3 contract: KDSOrderItemsResource MUST ship allergens_snapshot to align with OrderItemResource.'
        );
        $this->assertIsArray($payload['allergens_snapshot']);
        $this->assertEqualsCanonicalizing(
            ['gluten', 'arachides'],
            $payload['allergens_snapshot'],
            'Allergen codes must round-trip from DB snapshot to wire payload without loss.'
        );
    }

    /**
     * Defensive — null allergens (legacy pre-backfill rows) MUST collapse to
     * an empty array on the wire, NEVER null. Frontend Array.isArray() guards
     * in admin-kds.js / KitchenDisplaySystemComponent.vue line 912 depend on
     * this shape.
     */
    public function test_kds_order_items_resource_handles_null_allergens_as_empty_array(): void
    {
        $orderItem = $this->makeBurger(null);

        $payload = (new KDSOrderItemsResource($orderItem))->toArray(Request::create('/'));

        $this->assertArrayHasKey('allergens_snapshot', $payload);
        $this->assertSame(
            [],
            $payload['allergens_snapshot'],
            'NULL allergens_snapshot MUST serialize to [] for frontend Array.isArray() compatibility.'
        );
    }

    /**
     * Wire-shape integration — KDSOrderItemsResource::collection over a raw
     * OrderItem collection (bypassing the service-layer TZ-bound query, which
     * has a known pre-existing failure mode tracked separately as V1.0.X
     * regression) MUST carry allergens_snapshot for every merged line.
     *
     * Note: we deliberately do NOT invoke the service's orderItems() merge
     * path here — that path is sentinel-covered by KdsAllergenAggregationSplitTest
     * (currently failing for unrelated TZ reasons listed in the task brief).
     * This test focuses on the contract from RAW OrderItem → wire payload.
     */
    public function test_resource_collection_over_raw_items_carries_allergens(): void
    {
        $itemA = $this->makeBurger(['gluten']);
        $itemB = $this->makeBurger(['arachides']);

        $collection = KDSOrderItemsResource::collection(collect([$itemA, $itemB]));
        $resolved = $collection->resolve(Request::create('/'));

        $this->assertCount(2, $resolved);

        $allergenSets = collect($resolved)->pluck('allergens_snapshot')->all();
        foreach ($allergenSets as $set) {
            $this->assertIsArray(
                $set,
                'Each KDS line MUST carry an array allergens_snapshot on the wire.'
            );
        }

        $allergens = collect($allergenSets)->flatten()->unique()->values()->all();
        $this->assertEqualsCanonicalizing(
            ['gluten', 'arachides'],
            $allergens,
            'Both allergen groups must round-trip through resource collection serialization.'
        );
    }

    /**
     * FOOD-SAFETY (massive-2dot0 #9 display leg) — a DOUBLE-ENCODED allergens_snapshot
     * (JSON string of a JSON array, a legacy-write artifact) MUST still expose the real
     * codes on the wire so the chef's displayed allergen list is not silently blanked.
     * Pre-heal safeJsonDecodeArray decoded once -> a string -> [] -> empty display.
     */
    public function test_kds_order_items_resource_unwraps_double_encoded_allergens(): void
    {
        $orderItem = $this->makeBurger(['gluten', 'arachides']);
        \Illuminate\Support\Facades\DB::table('order_items')
            ->where('id', $orderItem->id)
            ->update(['allergens_snapshot' => json_encode(json_encode(['gluten', 'arachides']))]);
        $fresh = OrderItem::find($orderItem->id);

        $payload = (new KDSOrderItemsResource($fresh))->toArray(Request::create('/'));

        $this->assertEqualsCanonicalizing(
            ['gluten', 'arachides'],
            $payload['allergens_snapshot'],
            'Double-encoded allergens_snapshot MUST still expose the real codes (food-safety display), not blank to [].'
        );
    }

    private function makeBurger(?array $allergens): OrderItem
    {
        return OrderItem::forceCreate([
            'order_id' => $this->order->id,
            'branch_id' => $this->branch->id,
            'item_id' => $this->burgerItem->id,
            'quantity' => 1,
            'discount' => 0,
            'tax_name' => 'TVA 10 PK3',
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
}
