<?php

/**
 * [GOAL_V1_LOCAL_GOLIVE W2.1 — DASH-01 heal 2026-06-01]
 *
 * The "Total commandes" KPI counted status=DELIVERED only (e.g. 3 vs 1755 placed),
 * misleading under that label. It must reflect REAL placed-order volume, excluding
 * refund counter-entry mirrors.
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
use Tests\TestCase;

class TotalOrdersRealVolumeSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_total_orders_counts_all_placed_excluding_mirrors(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');
        $branch = Branch::factory()->create();

        $mk = fn (int $status, int $pay, ?int $parent = null) => Order::factory()->create([
            'branch_id' => $branch->id, 'status' => $status, 'payment_status' => $pay,
            'order_type' => OrderType::KIOSK, 'order_datetime' => '2026-03-15 12:00:00', 'total' => 10,
            'parent_order_id' => $parent, 'is_advance_order' => Ask::NO, 'source' => Source::APP,
        ]);

        $delivered = $mk(OrderStatus::DELIVERED, PaymentStatus::PAID);
        $mk(OrderStatus::ACCEPT, PaymentStatus::PENDING_COUNTER);   // placed, not delivered
        $mk(OrderStatus::CANCELED, PaymentStatus::PAID);            // placed, then cancelled
        $mk(OrderStatus::RETURNED, PaymentStatus::REFUNDED, $delivered->id); // refund mirror → excluded

        // Real volume = 3 placed orders (delivered + pending + cancelled); mirror excluded.
        // Pre-heal (DELIVERED-only) would have returned 1.
        $this->assertSame(3, (int) app(DashboardService::class)->totalOrders(),
            '"Total commandes" must count all placed orders (not DELIVERED-only), excluding refund mirrors.');
    }
}
