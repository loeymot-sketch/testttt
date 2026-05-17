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
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    /** @test */
    public function test_z4_p2_03_stale_prune_excludes_orders_older_than_window(): void
    {
        config(['oss.stale_window_hours' => 8]);
        $branch = Branch::factory()->create(['status' => Status::ACTIVE]);

        $fresh = Order::factory()->create([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::PREPARED,
            'order_datetime'   => now()->subHours(2),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '100',
            'token'            => null,
        ]);

        $stale = Order::factory()->create([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::PREPARED,
            'order_datetime'   => now()->subHours(12),
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

        $twoHourOld = Order::factory()->create([
            'branch_id'        => $branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::PREPARED,
            'order_datetime'   => now()->subHours(2),
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
