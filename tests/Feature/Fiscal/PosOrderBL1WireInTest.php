<?php

namespace Tests\Feature\Fiscal;

use App\Enums\Ask;
use App\Enums\OrderType;
use App\Enums\PosPaymentMethod;
use App\Enums\Source;
use App\Enums\Status;
use App\Enums\TaxType;
use App\Models\Allergen;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Smartisan\Settings\Facades\Settings;
use Tests\Feature\Concerns\HasPosQuoteBinding;
use Tests\TestCase;

/**
 * POS-9.4.BL.1 — Fiscal sequence + allergen snapshot wire-in on
 * OrderService::posOrderStore.
 *
 * Covers:
 *  1) `orders.fiscal_sequence_no` is populated atomically, strictly monotonic,
 *     gap-free, per branch_id.
 *  2) `order_items.allergens_snapshot` is persisted at order-time and
 *     remains immutable after the item's pivot allergens change.
 */
class PosOrderBL1WireInTest extends TestCase
{
    use RefreshDatabase;
    use HasPosQuoteBinding;

    protected Branch $branch;
    protected User $admin;
    protected Item $item;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();

        config([
            'app.api_key' => 'test-api-key',
            'broadcasting.default' => 'log',
            'pricing.use_ssot_service' => true,
        ]);

        Settings::group('order_setup')->set([
            'order_setup_food_preparation_time' => 30,
            'order_setup_schedule_order_slot_duration' => 30,
        ]);

        $this->branch = Branch::forceCreate([
            'name' => 'POS BL1 Branch',
            'city' => 'Paris',
            'state' => 'IDF',
            'zip_code' => '75000',
            'address' => '1 rue BL1',
            'status' => 1,
        ]);

        $tax = Tax::create([
            'name' => 'TVA 10',
            'code' => 'TVA10BL1',
            'tax_rate' => 10,
            'type' => TaxType::PERCENTAGE,
            'status' => Status::ACTIVE,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'BL1 Cat',
            'slug' => 'bl1-cat',
            'status' => Status::ACTIVE,
        ]);

        $this->item = Item::forceCreate([
            'name' => 'BL1 Burger',
            'slug' => 'bl1-burger',
            'price' => 10.00,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        $this->admin = \Database\Factories\UserFactory::new()->create([
            'branch_id' => $this->branch->id,
        ]);
        $this->admin->assignRole('Admin');
    }

    public function test_pos_order_receives_fiscal_sequence_no_monotonic_per_branch(): void
    {
        $first  = $this->placePosOrder();
        $second = $this->placePosOrder();
        $third  = $this->placePosOrder();

        $this->assertNotNull($first->fiscal_sequence_no, 'First POS order must receive a fiscal sequence number.');
        $this->assertSame(1, (int) $first->fiscal_sequence_no);
        $this->assertSame(2, (int) $second->fiscal_sequence_no);
        $this->assertSame(3, (int) $third->fiscal_sequence_no);

        // No gap, strictly monotonic.
        $persistedNumbers = Order::where('branch_id', $this->branch->id)
            ->orderBy('id')
            ->pluck('fiscal_sequence_no')
            ->map(fn ($n) => (int) $n)
            ->all();
        $this->assertSame([1, 2, 3], $persistedNumbers);
    }

    public function test_fiscal_sequence_is_scoped_per_branch(): void
    {
        $otherBranch = Branch::forceCreate([
            'name' => 'Other branch',
            'city' => 'Lyon',
            'state' => 'ARA',
            'zip_code' => '69000',
            'address' => '2 rue other',
            'status' => 1,
        ]);

        $otherAdmin = \Database\Factories\UserFactory::new()->create(['branch_id' => $otherBranch->id]);
        $otherAdmin->assignRole('Admin');

        $ownA = $this->placePosOrder();
        $ownB = $this->placePosOrder();
        $this->assertSame(1, (int) $ownA->fiscal_sequence_no);
        $this->assertSame(2, (int) $ownB->fiscal_sequence_no);

        // Order for other branch must start its own sequence at 1.
        $otherOrder = $this->placePosOrder($otherAdmin, $otherBranch);
        $this->assertSame(1, (int) $otherOrder->fiscal_sequence_no);
    }

    public function test_pos_order_stores_allergens_snapshot_from_pivot(): void
    {
        $gluten  = Allergen::forceCreate(['code' => 'gluten',  'name_key' => 'allergens.gluten',  'sort' => 1]);
        $lactose = Allergen::forceCreate(['code' => 'lactose', 'name_key' => 'allergens.lactose', 'sort' => 2]);

        $this->item->allergens()->sync([
            $gluten->id  => ['is_trace' => false],
            $lactose->id => ['is_trace' => false],
        ]);

        $order = $this->placePosOrder();

        $snapshotRaw = DB::table('order_items')
            ->where('order_id', $order->id)
            ->value('allergens_snapshot');

        $snapshot = json_decode((string) $snapshotRaw, true);
        $this->assertIsArray($snapshot, 'Snapshot must be JSON-encoded array.');
        $this->assertEqualsCanonicalizing(['gluten', 'lactose'], $snapshot);
    }

    public function test_snapshot_remains_immutable_after_item_allergens_change(): void
    {
        $gluten  = Allergen::forceCreate(['code' => 'gluten',  'name_key' => 'allergens.gluten',  'sort' => 1]);
        $lactose = Allergen::forceCreate(['code' => 'lactose', 'name_key' => 'allergens.lactose', 'sort' => 2]);
        $oeufs   = Allergen::forceCreate(['code' => 'oeufs',   'name_key' => 'allergens.oeufs',   'sort' => 3]);

        $this->item->allergens()->sync([
            $gluten->id  => ['is_trace' => false],
            $lactose->id => ['is_trace' => false],
        ]);

        $order = $this->placePosOrder();

        // Mutate post-order pivots; the snapshot must stay frozen.
        $this->item->allergens()->sync([$oeufs->id => ['is_trace' => false]]);

        $snapshot = json_decode(
            (string) DB::table('order_items')->where('order_id', $order->id)->value('allergens_snapshot'),
            true
        );
        $this->assertEqualsCanonicalizing(['gluten', 'lactose'], $snapshot);
    }

    private function placePosOrder(?User $admin = null, ?Branch $branch = null): Order
    {
        $admin  ??= $this->admin;
        $branch ??= $this->branch;

        $payload = [
            'customer_id'         => $admin->id,
            'branch_id'           => $branch->id,
            'subtotal'            => 10.00,
            'total'               => 11.00,
            'order_type'          => OrderType::TAKEAWAY,
            'is_advance_order'    => Ask::NO,
            'source'              => Source::POS,
            'pos_payment_method'  => PosPaymentMethod::CASH,
            'pos_received_amount' => 11.00,
            'items' => json_encode([
                [
                    'item_id'         => $this->item->id,
                    'quantity'        => 1,
                    'item_variations' => [],
                    'item_extras'     => [],
                ],
            ]),
        ];

        $response = $this->actingAs($admin)
            ->withHeader('x-api-key', config('app.api_key'))
            ->postJson('/api/admin/pos', $this->payloadWithPosQuote($admin, $payload));

        $this->assertContains(
            $response->status(),
            [200, 201],
            'POS order must succeed. Body: ' . json_encode($response->json())
        );

        $orderId = (int) $response->json('data.id');
        return Order::findOrFail($orderId);
    }
}
