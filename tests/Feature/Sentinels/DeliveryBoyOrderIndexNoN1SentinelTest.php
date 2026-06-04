<?php

namespace Tests\Feature\Sentinels;

use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Role as EnumRole;
use App\Http\Requests\PaginateRequest;
use App\Http\Resources\SimpleDeliveryBoyOrderResource;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * @FK-ID WAVE-H-2026-05-19 / bug_005 — Heal SimpleDeliveryBoyOrderResource N+1.
 * @source reports/audit/wave-h-2026-05-19/WH-4-bug005-N1-DELIVERY-RESOURCE/STATUS.md
 *
 * BACKGROUND
 * ----------
 * `SimpleDeliveryBoyOrderResource::resolveItemsForDriver()` claims (via its
 * docstring) to be N+1-protected because it guards on
 * `relationLoaded('orderItems')`. However the inner `map` closure accesses
 * `$line->orderItem?->name` — and `OrderItem::orderItem` is a SECOND-level
 * `belongsTo(Item)` relation that the caller never eager-loads.
 *
 * Therefore, every render of the `/api/frontend/delivery-boy-order` (index)
 * payload fires one extra `SELECT * FROM items WHERE id = ?` per order_item
 * line — i.e. ~50 lazy queries for a 10-orders × 5-items page.
 *
 * The fix lives in `OrderService::deliveryBoyOrder()` line ~283:
 *   - BEFORE: `Order::with('transaction', 'orderItems', 'branch', 'user')`
 *   - AFTER : `Order::with(['transaction', 'orderItems.orderItem', 'branch', 'user'])`
 *
 * That single dotted-eager-load instruction collapses N item lookups into
 * one batched `SELECT * FROM items WHERE id IN (?, ?, …)`.
 *
 * WHY A DEDICATED SENTINEL
 * ------------------------
 * The existing `DeliveryBoyHardeningSentinelTest::test_p1_liv_01_*` proves
 * that the resource SHAPE includes items, but it only loads `orderItems`
 * (no `.orderItem`) and never counts queries — so it would have continued
 * to pass even with this regression in place. This sentinel adds the
 * missing PERFORMANCE invariant.
 *
 * INVARIANT GUARDED
 * -----------------
 * Materializing the livreur index payload — i.e. the full pipeline
 * `OrderService::deliveryBoyOrder` → `SimpleDeliveryBoyOrderResource::collection`
 * → `toArray()` — for `N` orders with `K` items each must execute a
 * BOUNDED number of database queries (no growth proportional to N*K).
 *
 * The threshold (≤ 25) is comfortably above the eager-loaded query budget
 * (~5–10 queries: orders + the four eager `with()` relations + minor
 * framework lookups) and comfortably below the broken-state query budget
 * (~55+: 1 orders + 1 orderItems batch + ~50 lazy item lookups).
 */
class DeliveryBoyOrderIndexNoN1SentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedMinimalSettings();

        // [WH-4 isolation] We deliberately DO NOT call $this->seedSpatieRoles()
        // here, because that helper seeds 6 roles via `Role::firstOrCreate
        // (['name' => …])` and lets sqlite AUTO_INCREMENT pick their ids
        // (1..6). The follow-up `Role::firstOrCreate(['id' => 3], ['name' =>
        // 'Delivery Boy', …])` then becomes a no-op — id=3 is already taken
        // by Branch Manager, so 'Delivery Boy' is NEVER inserted and the
        // assigned role on the test driver is actually Branch Manager.
        //
        // More importantly, when that helper is invoked from THIS test class
        // it leaves Spatie's PermissionRegistrar cache holding a non-driver
        // mapping for 'Delivery Boy' for the remainder of the PHP process;
        // a downstream test class that performs a fresh `DB::table('roles')
        // ->insert(['id' => 73, 'name' => 'Delivery Boy', …])` (see
        // SelectDeliveryBoyRoleByNameSentinelTest::setUp) then resolves the
        // cached lookup to the wrong id even though the DB has the new row.
        // RefreshDatabase rolls back the DB transaction but does NOT clear
        // static caches.
        //
        // The minimal-and-correct setup is: ONE canonical 'Delivery Boy' row
        // at the stable enum id, nothing else, and forget cached permissions
        // so the registrar reads back the literal row.
        $rolesTable = config('permission.table_names.roles', 'roles');
        DB::table($rolesTable)->insert([
            'id'         => EnumRole::DELIVERY_BOY,
            'name'       => 'Delivery Boy',
            'guard_name' => 'sanctum',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    private function makeDeliveryBoy(int $branchId): User
    {
        $user = User::forceCreate([
            'name'              => 'Driver ' . uniqid(),
            'email'             => 'driver-' . uniqid() . '@sentinel.test',
            'username'          => 'driver_' . uniqid(),
            'password'          => bcrypt('secret-passwd'),
            'branch_id'         => $branchId,
            'email_verified_at' => now(),
            'status'            => 1,
        ]);
        $user->assignRole(EnumRole::DELIVERY_BOY);
        return $user->fresh();
    }

    /**
     * Seed the live data shape that the livreur `/index` endpoint receives in
     * production: a single driver in one branch, with `$orderCount` orders
     * each carrying `$itemsPerOrder` distinct items (real `items` rows so the
     * inner `belongsTo(Item)` actually resolves and would otherwise fire).
     *
     * @return array{0: User, 1: Branch}
     */
    private function seedDriverWithOrders(int $orderCount, int $itemsPerOrder): array
    {
        $branch = Branch::factory()->create();
        $driver = $this->makeDeliveryBoy($branch->id);

        // Real Item rows, ONE per line — guarantees the `belongsTo(Item)`
        // resolves to a row and would fire a SELECT in the broken state.
        // If items were missing the `?->name` short-circuits without a query
        // and the sentinel would silently pass.
        $totalItems = $orderCount * $itemsPerOrder;
        $items = [];
        for ($i = 0; $i < $totalItems; $i++) {
            $items[] = Item::factory()->create();
        }

        $orderItemRows = [];
        for ($o = 0; $o < $orderCount; $o++) {
            $order = Order::factory()->create([
                'branch_id'       => $branch->id,
                'order_type'      => OrderType::DELIVERY,
                'delivery_boy_id' => $driver->id,
                'status'          => OrderStatus::OUT_FOR_DELIVERY,
                'payment_method'  => 1,
                'payment_status'  => PaymentStatus::UNPAID,
            ]);

            for ($k = 0; $k < $itemsPerOrder; $k++) {
                $item = $items[$o * $itemsPerOrder + $k];
                $orderItemRows[] = [
                    'order_id'      => $order->id,
                    'item_id'       => $item->id,
                    'branch_id'     => $branch->id,
                    'price'         => 5.0,
                    'quantity'      => 1,
                    'discount'      => 0.0,
                    'total_price'   => 5.0,
                    'created_at'    => now(),
                    'updated_at'    => now(),
                ];
            }
        }
        OrderItem::query()->insert($orderItemRows);

        return [$driver, $branch];
    }

    /**
     * Render the live livreur index pipeline end-to-end exactly the way the
     * controller does: list query through `OrderService::deliveryBoyOrder`,
     * then `SimpleDeliveryBoyOrderResource::collection(...)->toArray($request)`.
     *
     * The Resource pass is non-optional: that is where the lazy
     * `$line->orderItem?->name` access actually fires the per-line SELECT.
     * Counting queries only on the service call would NOT discriminate broken
     * vs fixed states — the lazy belongsTo only awakens on iteration.
     */
    private function countQueriesForLivreurIndexRender(int $orderCount, int $itemsPerOrder): int
    {
        [$driver] = $this->seedDriverWithOrders($orderCount, $itemsPerOrder);

        $this->actingAs($driver, 'sanctum');

        // Build a PaginateRequest with paginate=0 so we get a flat Collection
        // (not a paginator wrapper) — keeps the assertion narrow on the
        // resource render itself.
        $request = PaginateRequest::create('/api/frontend/delivery-boy-order', 'GET', [
            'paginate' => 0,
        ]);
        $request->headers->set('Accept', 'application/json');

        // Settings / role / scope reads happen BEFORE the query log opens so
        // we measure ONLY the orders pipeline. This makes the threshold
        // robust to future framework / Spatie / settings warmup churn.
        DB::flushQueryLog();
        DB::enableQueryLog();

        $orders = app(OrderService::class)->deliveryBoyOrder($request);

        // CRITICAL: this is the line that fires the lazy load in the broken
        // state. Without it, a sentinel that only calls the service would
        // see ~5 queries on both broken & fixed code and pass silently.
        $payload = SimpleDeliveryBoyOrderResource::collection($orders)
            ->toArray(Request::create('/'));

        $count = count(DB::getQueryLog());
        DB::disableQueryLog();

        // Sanity: payload must actually contain the seeded orders, otherwise
        // an empty list would trivially fire 0 lazy queries and the sentinel
        // would silently pass on a regression that broke the listing query.
        $this->assertCount(
            $orderCount,
            $payload,
            'Sentinel pre-condition: payload must contain all seeded orders, '
            . 'otherwise the query-count assertion is meaningless.'
        );
        foreach ($payload as $entry) {
            $this->assertArrayHasKey('items', $entry);
            $this->assertCount(
                $itemsPerOrder,
                $entry['items'],
                'Sentinel pre-condition: each order must surface its items, '
                . 'otherwise the inner orderItem belongsTo is never touched.'
            );
        }

        return $count;
    }

    /**
     * SENTINEL — main invariant.
     *
     * 10 orders × 5 items each. Broken state historically runs ~55 queries
     * (orders + orderItems batch + ~50 lazy items). Fixed state runs ~5–7
     * queries (orders + 4 batched eager-load relations + framework lookups).
     *
     * Threshold = 25:
     *   • generous enough that minor framework / settings churn won't flake
     *   • tight enough that the 55-query broken state cannot squeak through
     *   • a future regression that adds 1 lazy load per line gets caught
     */
    public function test_livreur_index_render_is_bounded_no_n1_on_order_item_dot_item(): void
    {
        $count = $this->countQueriesForLivreurIndexRender(10, 5);

        $this->assertLessThanOrEqual(
            25,
            $count,
            sprintf(
                'Livreur index render (10 orders × 5 items) executed %d DB queries. '
                . 'Expected ≤ 25 (bounded eager-load budget). A count near 55 '
                . 'indicates the SimpleDeliveryBoyOrderResource N+1 has resurfaced — '
                . 'confirm OrderService::deliveryBoyOrder still eager-loads '
                . '`orderItems.orderItem` (dotted), not just `orderItems`.',
                $count
            )
        );
    }

    /**
     * SENTINEL — scaling invariant.
     *
     * Re-runs the same pipeline at a smaller cardinality (5 orders × 3 items)
     * and asserts the query count delta is BOUNDED — i.e. it does NOT scale
     * with N*K. With the fix in place both runs should land in the same
     * ~5–10 query band. Without the fix, the small run is ~16 and the big
     * run is ~55+, a delta of >30.
     *
     * This protects against a future agent that adds a different lazy load
     * (e.g. `orderItem.category`) which would re-introduce N+1 even if the
     * literal threshold of 25 is still met for tiny payloads.
     */
    public function test_livreur_index_render_query_count_does_not_scale_with_payload(): void
    {
        $small = $this->countQueriesForLivreurIndexRender(5, 3);

        // Reset DB state between renders — each call to
        // countQueriesForLivreurIndexRender seeds fresh data inside the same
        // transaction (RefreshDatabase). The two figures are independent
        // counts of disjoint query bursts.
        $big = $this->countQueriesForLivreurIndexRender(10, 5);

        $this->assertLessThanOrEqual(
            10,
            $big - $small,
            sprintf(
                'Livreur index render scales unbounded: small=%d, big=%d, '
                . 'delta=%d. Expected delta ≤ 10 (eager-loaded relations are '
                . 'batched per call). A larger delta indicates a per-line '
                . 'lazy load — see bug_005 in WAVE-H-2026-05-19.',
                $small,
                $big,
                $big - $small
            )
        );
    }
}
