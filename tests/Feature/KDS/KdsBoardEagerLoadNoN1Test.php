<?php

/**
 * [abuse-e2e B4 2026-06-16] N+1 regression guard for the KDS board.
 *
 * KDSOrderDetailsResource accesses two relations that were NOT eager-loaded by the board query:
 *   - line 50: $this->orderItems->loadMissing('orderItem')  → 1 SELECT on the catalog Item PER order
 *   - line 51: $this->diningTable?->name                    → 1 SELECT on dining_tables PER order
 * On the 5s WS-down poll (list + sync, both rendering this resource) that is 2 lazy SELECTs per order,
 * per poll. Fix: the board/sync/history queries now eager-load `orderItems.orderItem` + `diningTable`.
 *
 * This test EAGER-loads via the real service, then renders the resource inside a query log and asserts
 * ZERO new queries fire — scale-independent and mutation-resistant (revert the with() → render lazy-loads
 * → this goes RED).
 */

namespace Tests\Feature\KDS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Http\Resources\KDSOrderDetailsResource;
use App\Models\Branch;
use App\Models\DiningTable;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\KitchenDisplaySystemOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KdsBoardEagerLoadNoN1Test extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();
        $this->seedSpatieRoles();
    }

    private function seedBoardOrders(int $branchId, int $count): void
    {
        $category = ItemCategory::firstOrCreate(['slug' => 'n1-cat'], ['name' => 'N1 cat', 'status' => 5]);
        $item = Item::forceCreate([
            'name' => 'N1 burger', 'slug' => 'n1-burger', 'price' => 10, 'status' => 5,
            'item_category_id' => $category->id,
        ]);
        $customer = User::factory()->create(['branch_id' => $branchId]);

        for ($i = 0; $i < $count; $i++) {
            $table = DiningTable::factory()->create(['branch_id' => $branchId]);
            $order = Order::create([
                'order_serial_no' => '20260616-N1-' . str_pad((string) $i, 3, '0', STR_PAD_LEFT),
                'branch_id' => $branchId,
                'user_id' => $customer->id,
                'dining_table_id' => $table->id,
                'status' => OrderStatus::PREPARING,
                'payment_status' => PaymentStatus::PAID,
                'subtotal' => 10.0,
                'total' => 10.0,
                'order_type' => OrderType::TAKEAWAY,
                'order_datetime' => now(),
                'is_advance_order' => Ask::NO,
                'source' => 5,
            ]);
            // two lines per order so the catalog-Item N+1 would compound if present
            foreach ([0, 1] as $line) {
                OrderItem::create([
                    'order_id' => $order->id,
                    'branch_id' => $branchId,
                    'item_id' => $item->id,
                    'quantity' => 1,
                    'price' => 10,
                    'discount' => 0,
                    'item_variation_total' => 0,
                    'item_extra_total' => 0,
                    'total_price' => 10,
                    'item_variations' => '[]',
                    'item_extras' => '[]',
                    'instruction' => '',
                ]);
            }
        }
    }

    /** @test */
    public function rendering_the_kds_board_resource_fires_zero_lazy_queries(): void
    {
        $branch = Branch::factory()->create();
        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');
        $this->actingAs($chef, 'sanctum');

        $this->seedBoardOrders($branch->id, 6);

        // 1. Eager-load through the REAL service (this is the code path under test).
        $orders = app(KitchenDisplaySystemOrderService::class)->list(new Request());
        $this->assertNotEmpty($orders, 'Seeded board orders must be visible — else the 0-query check is vacuous.');

        // 2. Relations must already be loaded by the service.
        $first = $orders->first();
        $this->assertTrue($first->relationLoaded('orderItems'), 'orderItems must be eager-loaded');
        $this->assertTrue($first->relationLoaded('diningTable'), 'diningTable must be eager-loaded (was the N+1 at resource :51)');
        $this->assertTrue(
            $first->orderItems->first()->relationLoaded('orderItem'),
            'nested catalog orderItem must be eager-loaded (was the N+1 at resource :50)'
        );

        // 3. Rendering the resource (which touches loadMissing(orderItem) + diningTable?->name) must
        //    fire ZERO additional queries. With the old lazy-load this is ~2 per order.
        DB::enableQueryLog();
        KDSOrderDetailsResource::collection($orders)->toArray(request());
        $lazyQueries = DB::getQueryLog();
        DB::disableQueryLog();

        $this->assertCount(
            0,
            $lazyQueries,
            'KDS resource render must not lazy-load (N+1). Fired: '
                . implode(' | ', array_map(fn ($q) => $q['query'], $lazyQueries))
        );
    }
}
