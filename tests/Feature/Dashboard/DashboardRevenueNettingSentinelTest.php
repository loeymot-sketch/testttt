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
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
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
