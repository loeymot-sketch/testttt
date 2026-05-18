<?php

namespace Tests\Feature\OSS;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Order;
use App\Services\OrderStatusScreenOrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [2026-05-18 F-5] OSS customer screen filter — owner spec:
 *   The customer-facing wall (`/admin/order-status-screen`) shows ONLY
 *   orders in PREPARING (orange) or PREPARED (green). DELIVERY orders
 *   never appear (they ride the staff PosOrdersTracker workflow today,
 *   auto-dispatch is V1.0.2 backlog per DEFERRED_AUTO_DISPATCH_V1_0_2.md).
 *
 * Before the fix, a DELIVERY order with a non-null token leaked through
 * the `whereNotNull('token').orWhere(KIOSK).orWhere(TAKEAWAY+queue)` OR-
 * branch in `OrderStatusScreenOrderService::list()` + `::listForBranch()`.
 * The fix adds `where('order_type','!=', OrderType::DELIVERY)` to both
 * methods (byte-identical query bodies per service docstring line 144).
 *
 * Convergence filter: `php artisan test --filter='OssCustomerScreenFilter'`.
 */
class OssCustomerScreenFilterTest extends TestCase
{
    use RefreshDatabase;

    protected Branch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        config(['oss.stale_window_hours' => 8]);
        $this->branch = Branch::factory()->create(['status' => Status::ACTIVE]);
    }

    /** A DELIVERY order WITH a token must NOT surface on the customer wall. */
    public function test_delivery_order_with_token_is_excluded_from_oss_wall(): void
    {
        $delivery = Order::factory()->create([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::DELIVERY,
            'status'           => OrderStatus::PREPARED,
            'order_datetime'   => now()->subMinutes(15),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '200',
            'token'            => 'OSS-DELIVERY-TOKEN-LEAK-TEST',
        ]);

        $service = app(OrderStatusScreenOrderService::class);
        $orders  = $service->listForBranch($this->branch->id);

        $ids = $orders->pluck('id')->toArray();
        $this->assertNotContains(
            $delivery->id,
            $ids,
            'DELIVERY orders with tokens previously leaked onto the customer wall — must now be hidden.',
        );
    }

    /** TAKEAWAY (kiosk à-emporter) MUST still appear when PREPARING/PREPARED with queue_number. */
    public function test_takeaway_with_queue_number_still_appears(): void
    {
        $takeaway = Order::factory()->create([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::PREPARING,
            'order_datetime'   => now()->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '201',
            'token'            => null,
        ]);

        $service = app(OrderStatusScreenOrderService::class);
        $orders  = $service->listForBranch($this->branch->id);

        $ids = $orders->pluck('id')->toArray();
        $this->assertContains(
            $takeaway->id,
            $ids,
            'TAKEAWAY (kiosk à-emporter) orders with queue_number must still appear on OSS.',
        );
    }

    /** KIOSK sur-place orders MUST still appear. */
    public function test_kiosk_sur_place_still_appears(): void
    {
        $kiosk = Order::factory()->create([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::KIOSK,
            'status'           => OrderStatus::PREPARED,
            'order_datetime'   => now()->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '202',
            'token'            => 'KIOSK-TOKEN-OK',
        ]);

        $service = app(OrderStatusScreenOrderService::class);
        $orders  = $service->listForBranch($this->branch->id);

        $ids = $orders->pluck('id')->toArray();
        $this->assertContains(
            $kiosk->id,
            $ids,
            'KIOSK sur-place orders must continue to appear on OSS.',
        );
    }

    /** PENDING / ACCEPT (not-yet-validated) orders MUST be hidden. */
    public function test_pending_and_accept_orders_are_hidden(): void
    {
        $pending = Order::factory()->create([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::PENDING,
            'order_datetime'   => now()->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '203',
            'token'            => null,
        ]);
        $accept = Order::factory()->create([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::ACCEPT,
            'order_datetime'   => now()->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '204',
            'token'            => null,
        ]);

        $service = app(OrderStatusScreenOrderService::class);
        $orders  = $service->listForBranch($this->branch->id);

        $ids = $orders->pluck('id')->toArray();
        $this->assertNotContains($pending->id, $ids, 'PENDING orders must NOT appear on customer wall.');
        $this->assertNotContains($accept->id, $ids, 'ACCEPT orders must NOT appear on customer wall.');
    }

    /** Transition PREPARED → DELIVERED removes the order from the wall. */
    public function test_delivered_transition_removes_from_oss(): void
    {
        $order = Order::factory()->create([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::TAKEAWAY,
            'status'           => OrderStatus::PREPARED,
            'order_datetime'   => now()->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '205',
            'token'            => null,
        ]);

        $service = app(OrderStatusScreenOrderService::class);
        $before  = $service->listForBranch($this->branch->id)->pluck('id')->toArray();
        $this->assertContains($order->id, $before, 'PREPARED order must be on the wall before delivery.');

        $order->update(['status' => OrderStatus::DELIVERED]);
        $after = $service->listForBranch($this->branch->id)->pluck('id')->toArray();
        $this->assertNotContains($order->id, $after, 'After cashier validates delivery the order must vanish.');
    }

    /** RED R-3 heal: POS (15) orders with tokens MUST be excluded by the allowlist. */
    public function test_pos_order_with_token_is_excluded(): void
    {
        $pos = Order::factory()->create([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::POS,
            'status'           => OrderStatus::PREPARING,
            'order_datetime'   => now()->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '207',
            'token'            => 'OSS-POS-LEAK-TEST',
        ]);

        $service = app(OrderStatusScreenOrderService::class);
        $ids = $service->listForBranch($this->branch->id)->pluck('id')->toArray();
        $this->assertNotContains(
            $pos->id,
            $ids,
            'POS-type orders must never surface on the customer wall (allowlist enforces KIOSK + TAKEAWAY only).',
        );
    }

    /** RED R-3 heal: DINING_TABLE (20) orders with tokens MUST be excluded by the allowlist. */
    public function test_dining_table_order_with_token_is_excluded(): void
    {
        $dt = Order::factory()->create([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::DINING_TABLE,
            'status'           => OrderStatus::PREPARED,
            'order_datetime'   => now()->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '208',
            'token'            => 'OSS-DINING-LEAK-TEST',
        ]);

        $service = app(OrderStatusScreenOrderService::class);
        $ids = $service->listForBranch($this->branch->id)->pluck('id')->toArray();
        $this->assertNotContains(
            $dt->id,
            $ids,
            'DINING_TABLE-type orders must never surface on the customer wall (V1 dine-in disabled anyway).',
        );
    }

    /** Sister coverage: list() (authed admin path) mirrors listForBranch() filter. */
    public function test_authed_list_also_excludes_delivery(): void
    {
        $delivery = Order::factory()->create([
            'branch_id'        => $this->branch->id,
            'order_type'       => OrderType::DELIVERY,
            'status'           => OrderStatus::PREPARING,
            'order_datetime'   => now()->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'queue_number'     => '206',
            'token'            => 'AUTHED-DELIVERY-LEAK',
        ]);

        $admin = \App\Models\User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin);
        request()->query->set('branch_id', $this->branch->id);

        $service = app(OrderStatusScreenOrderService::class);
        $orders  = $service->list();

        $ids = $orders->pluck('id')->toArray();
        $this->assertNotContains(
            $delivery->id,
            $ids,
            'list() (admin-authed path) must also exclude DELIVERY orders — byte-identical with listForBranch().',
        );
    }
}
