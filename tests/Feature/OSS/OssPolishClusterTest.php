<?php

namespace Tests\Feature\OSS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Scopes\BranchScope;
use App\Services\OrderStatusScreenOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Sprint H5-B 2026-05-17] OSS polish cluster — Z4-P2-03 stale prune +
 * Z4-P2-04 branch-scoped popularity. Both cases assert the service in
 * isolation (no HTTP layer) so failures point straight at the query
 * change.
 */
class OssPolishClusterTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // [WG-4 TZ-test-drift V1.0.X 2026-05-19] Pin Carbon::setTestNow to a
        // fixed UTC instant inside the Paris business day. WF-2 sibling cluster
        // (Wave 3c heal at OrderStatusScreenOrderService L108 binds
        // `now('UTC')->subHours(N)` as UTC literal against the SQLite-stored
        // Paris-local fixture `now()->subHours(N)` → string comparison drifts
        // by 1-2h depending on wall-clock vs DST). Pinning both to a UTC
        // reference removes the drift. See
        // reports/audit/foundation-2026-05-18/failures/V1_0_X_BACKLOG_KDS_TZ_FIX.md.
        Carbon::setTestNow(Carbon::create(2026, 5, 18, 12, 0, 0, 'UTC'));
        CarbonImmutable::setTestNow(CarbonImmutable::create(2026, 5, 18, 12, 0, 0, 'UTC'));

        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    protected function tearDown(): void
    {
        // [WG-4] Carbon::setTestNow() leaks across tests if not cleared.
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @test */
    public function test_z4_p2_03_stale_prune_excludes_orders_older_than_window(): void
    {
        config(['oss.stale_window_hours' => 8]);
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        // [WG-4 TZ-test-drift V1.0.X 2026-05-19] Fixture `order_datetime`
        // written in UTC so SQLite string-compare against the service's
        // UTC-naive prune bound (`now('UTC')->subHours(N)`, Wave 3c heal
        // c2613cab0) is consistent. Pre-WG-4 the fixture used `now()`
        // (Paris-local) which drifted the comparison by 1-2h depending on
        // DST and accidentally passed only because the 8h gap absorbed the
        // shift. The sibling 1h test exposes the drift below.
        $fresh = Order::factory()->create([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::PREPARED,
            'order_datetime'   => now('UTC')->subHours(2),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '100',
            'token'            => null,
        ]);

        $stale = Order::factory()->create([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::PREPARED,
            'order_datetime'   => now('UTC')->subHours(12),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '101',
            'token'            => null,
        ]);

        $service = app(OrderStatusScreenOrderService::class);
        $orders  = $service->listForBranch($branch->id);

        $ids = $orders->pluck('id')->toArray();
        $this->assertContains($fresh->id, $ids, '2h-old PREPARED order must remain on the wall');
        $this->assertNotContains($stale->id, $ids, '12h-old PREPARED order must be pruned by the 8h window');
    }

    /** @test */
    public function test_z4_p2_03_stale_prune_respects_configured_window(): void
    {
        // Tightening to 1h must prune a 2h-old order that the 8h window kept.
        config(['oss.stale_window_hours' => 1]);
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        // [WG-4 TZ-test-drift V1.0.X 2026-05-19] Same root cause as cluster:
        // fixture in UTC to align with service-side `now('UTC')->subHours(N)`
        // bound. With Paris-local `now()` the 2h Paris↔UTC DST offset exceeds
        // the 1h prune window and the assertion oscillates depending on local
        // wall-clock — i.e. the test was relying on an accidental TZ shift,
        // not on the stale-prune logic actually being correct.
        $twoHourOld = Order::factory()->create([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::PREPARED,
            'order_datetime'   => now('UTC')->subHours(2),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '200',
            'token'            => null,
        ]);

        $orders = app(OrderStatusScreenOrderService::class)->listForBranch($branch->id);
        $this->assertNotContains($twoHourOld->id, $orders->pluck('id')->toArray());
    }

    /** @test */
    public function test_z4_p2_04_most_popular_items_scoped_by_branch(): void
    {
        $branchA = Branch::factory()->create(['status' => Status::ACTIVE]);
        $branchB = Branch::factory()->create(['status' => Status::ACTIVE]);

        // Single shared item — popularity should differ between branches.
        $item = Item::factory()->create(['status' => Status::ACTIVE]);

        // Branch A: 5 distinct order_items referencing this item.
        for ($i = 0; $i < 5; $i++) {
            $orderA = Order::factory()->create([
                'branch_id'  => $branchA->id,
                'order_type' => OrderType::TAKEAWAY,
                'status'     => OrderStatus::DELIVERED,
            ]);
            // OrderItem has BranchScope — create unscoped to avoid global filter.
            OrderItem::withoutGlobalScope(BranchScope::class)->create([
                'order_id'       => $orderA->id,
                'branch_id'      => $branchA->id,
                'item_id'        => $item->id,
                'quantity'       => 1,
                'discount'       => 0,
                'tax_name'       => 'VAT',
                'tax_rate'       => '0',
                'tax_type'       => 1,
                'tax_amount'     => 0,
                'price'          => 10,
                'total_price'    => 10,
            ]);
        }

        // Branch B: 0 OrderItems.
        $service = app(OrderStatusScreenOrderService::class);

        $branchAResult = $service->mostPopularItems($branchA->id);
        $branchBResult = $service->mostPopularItems($branchB->id);

        $branchAItem = $branchAResult->firstWhere('id', $item->id);
        $branchBItem = $branchBResult->firstWhere('id', $item->id);

        $this->assertNotNull($branchAItem, 'Item must appear in branch A popularity list');
        $this->assertSame(5, (int) $branchAItem->orders_count, 'Branch A count must be exactly 5');

        // Branch B sees the same item (status=ACTIVE) but its orders_count
        // for that branch must be 0 — pre-fix this would have been 5.
        $this->assertNotNull($branchBItem, 'Item must still appear (ACTIVE) in branch B list');
        $this->assertSame(0, (int) $branchBItem->orders_count, 'Branch B count must NOT leak branch A orders');
    }

    /** @test */
    public function test_z4_p2_04_most_popular_items_null_branch_returns_global(): void
    {
        // Backward compat: null branch (global admin) keeps legacy unscoped behaviour.
        $branchA = Branch::factory()->create(['status' => Status::ACTIVE]);
        $branchB = Branch::factory()->create(['status' => Status::ACTIVE]);
        $item = Item::factory()->create(['status' => Status::ACTIVE]);

        foreach ([$branchA, $branchB] as $b) {
            $order = Order::factory()->create([
                'branch_id'  => $b->id,
                'order_type' => OrderType::TAKEAWAY,
                'status'     => OrderStatus::DELIVERED,
            ]);
            OrderItem::withoutGlobalScope(BranchScope::class)->create([
                'order_id'    => $order->id,
                'branch_id'   => $b->id,
                'item_id'     => $item->id,
                'quantity'    => 1,
                'discount'    => 0,
                'tax_name'    => 'VAT',
                'tax_rate'    => '0',
                'tax_type'    => 1,
                'tax_amount'  => 0,
                'price'       => 10,
                'total_price' => 10,
            ]);
        }

        // Acting as a fictitious global admin (branch_id=0 user) via direct null param.
        $globalResult = app(OrderStatusScreenOrderService::class)->mostPopularItems(null);

        // No auth user → resolution yields null → unscoped. Both order_items
        // are counted because the BranchScope on OrderItem is irrelevant here
        // (withCount aggregates via raw subquery without the model boot scope
        // when the closure is absent).
        $found = $globalResult->firstWhere('id', $item->id);
        $this->assertNotNull($found);
        $this->assertGreaterThanOrEqual(2, (int) $found->orders_count, 'Global view must aggregate across branches');
    }
}
