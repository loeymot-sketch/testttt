<?php

/**
 * [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — DASH-NET-01 + DASH-SEM-03 heal]
 *
 * Owner decision: dashboard/report revenue must be NET realized and agree with
 * the signed Z (exclude CANCELED/REJECTED/RETURNED, net out refund counter-entries).
 *
 * Pre-heal bugs:
 *  - salesSummary/totalSales summed payment_status=PAID with NO status filter →
 *    a cancelled-but-paid order kept its full total in CA forever.
 *  - refund counter-entry mirrors (RETURNED, payment_status=REFUNDED, total<0,
 *    parent_order_id set) were filtered out while the +parent stayed → refunds
 *    never reduced CA.
 *  - placed-order counts (total_order / returned_order) counted the mirror.
 *
 * @group sentinel
 * @group dashboard
 */

namespace Tests\Feature\Dashboard;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemCategory;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class DashboardRevenueNettingSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function actAsAdmin(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');
    }

    private function makeOrder(Branch $branch, int $status, int $payment, float $total, ?int $parentId = null): Order
    {
        return Order::factory()->create([
            'branch_id'        => $branch->id,
            'status'           => $status,
            'payment_status'   => $payment,
            'order_type'       => OrderType::KIOSK,
            'order_datetime'   => '2026-03-15 12:00:00',
            'total'            => $total,
            'total_tax'        => 0,
            'parent_order_id'  => $parentId,
            'is_advance_order' => Ask::NO,
            'source'           => Source::APP,
        ]);
    }

    public function test_revenue_excludes_cancelled_paid_and_nets_refund_mirror(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();

        $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID, 100.0);              // clean +100
        $this->makeOrder($branch, OrderStatus::CANCELED, PaymentStatus::PAID, 50.0);                // cancelled-paid → MUST drop
        $parent = $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID, 30.0);     // refunded +30
        $this->makeOrder($branch, OrderStatus::RETURNED, PaymentStatus::REFUNDED, -30.0, $parent->id); // mirror -30 → nets parent

        $result = app(DashboardService::class)->salesSummary(
            new Request(['first_date' => '2026-03-01', 'last_date' => '2026-03-31'])
        );

        // Net realized = 100 (clean) + 30 (refunded parent) - 30 (mirror) = 100.
        // Buggy gross = 100 + 50 + 30 = 180 (cancelled kept, refund not netted).
        $this->assertStringContainsString('100', (string) $result['total_sales'],
            'Net CA must be 100. Got: ' . $result['total_sales']);
        $this->assertStringNotContainsString('180', (string) $result['total_sales']);
        $this->assertStringNotContainsString('150', (string) $result['total_sales']);
    }

    /**
     * massive-2dot0 #12 — the EOD "Top du jour" widget (topItemsOfDay) must NET
     * refund counter-entries like every other money surface. Pre-heal it filtered
     * payment_status=PAID, so the RETURNED/REFUNDED negative-qty mirror was dropped
     * and a refunded item was over-counted (showed its full sold qty).
     */
    public function test_top_items_of_day_nets_refunded_quantities(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();
        $cat = ItemCategory::forceCreate(['name' => 'NetCat', 'slug' => 'net-cat', 'status' => Status::ACTIVE]);
        $x = Item::forceCreate(['name' => 'Refunded Item X', 'slug' => 'item-x-net', 'price' => 10, 'status' => Status::ACTIVE, 'item_category_id' => $cat->id]);
        $y = Item::forceCreate(['name' => 'Clean Item Y', 'slug' => 'item-y-net', 'price' => 5, 'status' => Status::ACTIVE, 'item_category_id' => $cat->id]);

        $parent = $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID, 30.0);
        $this->makeItem($parent, $branch, $x, 3, 30.0);
        $mirror = $this->makeOrder($branch, OrderStatus::RETURNED, PaymentStatus::REFUNDED, -30.0, $parent->id);
        $this->makeItem($mirror, $branch, $x, -3, -30.0);
        $clean = $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID, 5.0);
        $this->makeItem($clean, $branch, $y, 1, 5.0);

        $top = collect(app(DashboardService::class)->eodSynthesis('2026-03-15')['top_items']);
        $xRow = $top->firstWhere('name', 'Refunded Item X');
        $yRow = $top->firstWhere('name', 'Clean Item Y');

        $this->assertSame(0, (int) ($xRow['qty'] ?? 0), 'Refunded item X must net to 0 in Top du jour (was 3 pre-heal).');
        $this->assertNotNull($yRow, 'Clean item Y must appear.');
        $this->assertSame(1, (int) $yRow['qty']);
    }

    /**
     * massive-2dot0 #13 — the hourly-traffic chart (customerStates) must exclude
     * refund counter-entry mirrors, for parity with orderStatistics/totalOrders.
     * Pre-heal the mirror was counted as a phantom extra customer visit.
     */
    public function test_hourly_traffic_excludes_refund_mirror(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();
        $parent = $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID, 30.0);
        $this->makeOrder($branch, OrderStatus::RETURNED, PaymentStatus::REFUNDED, -30.0, $parent->id);

        $states = app(DashboardService::class)->customerStates(
            new Request(['first_date' => '2026-03-15', 'last_date' => '2026-03-15'])
        );

        $this->assertSame(
            1,
            (int) array_sum($states['total_customers']),
            'Refund counter-entry mirror must not count as a phantom customer visit.'
        );
    }

    /**
     * massive-2dot0 supervisor-R2 (#14 over-deferred) — sales-report total_discounts
     * must NET refunded sales like total_earnings does. Pre-heal: the mirror carries
     * discount=0 (RefundWithCounterEntryService:118) while total is negated, so a
     * refunded sale's full discount lingered in total_discounts though its earnings
     * netted to 0. Z-SAFE read-side fix (no mirror/Z column touched).
     */
    public function test_sales_report_discounts_net_refunded_sales(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();
        // Refunded sale: discount 5 then a RETURNED mirror (discount column = 0).
        $parent = $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID, 30.0);
        $parent->update(['discount' => 5.0, 'delivery_charge' => 4.0]);
        $this->makeOrder($branch, OrderStatus::RETURNED, PaymentStatus::REFUNDED, -30.0, $parent->id);
        // Clean sale: discount 3 + delivery 2 (must remain counted).
        $clean = $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID, 20.0);
        $clean->update(['discount' => 3.0, 'delivery_charge' => 2.0]);

        $report = app(OrderService::class)->salesReportOverview(
            new Request(['first_date' => '2026-03-01', 'last_date' => '2026-03-31'])
        );

        // Net realized discounts = 3 (clean only); the refunded sale's 5 nets out.
        // Pre-heal = 8 (5+3). Currency format uses the integer part.
        $this->assertStringContainsString('3', (string) $report['total_discounts']);
        $this->assertStringNotContainsString('8', (string) $report['total_discounts'], 'Refunded sale discount must net out (was 5+3=8).');
        // #14 sibling: delivery charges net the same way → 2 (clean only), not 6 (4+2).
        $this->assertStringContainsString('2', (string) $report['total_delivery_charges']);
        $this->assertStringNotContainsString('6', (string) $report['total_delivery_charges'], 'Refunded sale delivery charge must net out (was 4+2=6).');
    }

    private function makeItem(Order $order, Branch $branch, Item $item, int $qty, float $totalPrice): void
    {
        OrderItem::forceCreate([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $item->id,
            'quantity' => $qty,
            'discount' => 0,
            'tax_name' => 'TVA 10',
            'tax_rate' => 10,
            'tax_type' => 2,
            'tax_amount' => 0,
            'price' => $item->price,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => $totalPrice,
            'instruction' => null,
        ]);
    }

    public function test_placed_order_counts_exclude_refund_mirror(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();

        $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID, 100.0);
        $this->makeOrder($branch, OrderStatus::CANCELED, PaymentStatus::PAID, 50.0);
        $parent = $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID, 30.0);
        $this->makeOrder($branch, OrderStatus::RETURNED, PaymentStatus::REFUNDED, -30.0, $parent->id);

        $stats = app(DashboardService::class)->orderStatistics(
            new Request(['first_date' => '2026-03-01', 'last_date' => '2026-03-31'])
        );

        // 3 placed orders (clean + cancelled + refunded parent); the mirror is NOT a placed order.
        $this->assertSame(3, (int) $stats['total_order'], 'Refund counter-entry mirror must not count as a placed order.');
        // The mirror (status=RETURNED) must NOT inflate returned_order.
        $this->assertSame(0, (int) $stats['returned_order'], 'Mirror must not be counted as a customer return.');
        $this->assertSame(1, (int) $stats['canceled_order']);
    }
}
