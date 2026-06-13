<?php

/**
 * [CDASH-DONUT-01 / CDV-05 — W-VAL c1 2026-06-12 — P1 dashboard regression]
 *
 * The order-summary donut center label ("Total <n> commandes") reads
 * `total_orders` from GET /api/admin/dashboard/order-summary. DashboardService
 * computes it (DashboardService::orderSummary → $orderSummaryArray["total_orders"]),
 * but OrderSummaryResource::toArray() previously emitted ONLY the four percentage
 * series (delivered/returned/canceled/rejected) and dropped total_orders, so the
 * donut center showed "Total 0".
 *
 * This test pins the contract: the API response MUST expose `total_orders` equal
 * to the real placed-order count (percentage series excluded from that number).
 * It goes RED on the pre-heal Resource (key absent) and GREEN now.
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
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderSummaryTotalOrdersTest extends TestCase
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

    private function makeOrder(Branch $branch, int $status, int $payment, ?int $parentId = null): Order
    {
        return Order::factory()->create([
            'branch_id'        => $branch->id,
            'status'           => $status,
            'payment_status'   => $payment,
            'order_type'       => OrderType::KIOSK,
            'order_datetime'   => '2026-03-15 12:00:00',
            'total'            => 10.0,
            'total_tax'        => 0,
            'parent_order_id'  => $parentId,
            'is_advance_order' => Ask::NO,
            'source'           => Source::APP,
        ]);
    }

    public function test_order_summary_response_exposes_total_orders_with_real_count(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();

        // 4 placed orders across statuses; refund mirror must NOT inflate the count.
        $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID);
        $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID);
        $this->makeOrder($branch, OrderStatus::CANCELED, PaymentStatus::PAID);
        $parent = $this->makeOrder($branch, OrderStatus::DELIVERED, PaymentStatus::PAID);
        $this->makeOrder($branch, OrderStatus::RETURNED, PaymentStatus::REFUNDED, $parent->id); // mirror

        $response = $this->getJson('/api/admin/dashboard/order-summary?first_date=2026-03-01&last_date=2026-03-31');

        $response->assertOk();
        // The key must be present (pre-heal Resource dropped it entirely).
        $response->assertJsonStructure(['data' => ['total_orders']]);
        // 4 placed orders (the RETURNED refund mirror with parent_order_id is excluded).
        $response->assertJsonPath('data.total_orders', 4);
    }

    public function test_order_summary_total_orders_is_zero_when_no_orders(): void
    {
        $this->actAsAdmin();
        Branch::factory()->create();

        $response = $this->getJson('/api/admin/dashboard/order-summary?first_date=2026-03-01&last_date=2026-03-31');

        $response->assertOk();
        $response->assertJsonStructure(['data' => ['total_orders']]);
        $response->assertJsonPath('data.total_orders', 0);
    }
}
