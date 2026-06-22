<?php

namespace Tests\Feature\KDS;

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
use App\Models\Tax;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * CHARACTERIZATION (verify-before-report) — board-release gate leak on
 * KitchenDisplaySystemOrderService::historyToday() (GET /api/admin/kds-order/history-today).
 *
 * KitchenReleaseRule::applyBoardReleaseFilter() (PAID | PENDING_COUNTER |
 * POS-cash) is applied by list() (line 81), orderItems() (line 524) and
 * KdsSyncService::sync() (line 118 — both healed this turn as KDS-ABUSE-01/02).
 * historyToday() (line 235) is the remaining sibling read path: it filters by
 * STATUS only (PREPARED / OUT_FOR_DELIVERY / DELIVERED) and never applies the
 * board-release filter.
 *
 * Reachability of UNPAID + PREPARED: OrderService::changeStatus() (the general
 * admin/staff order-status endpoint) gates transitions on OrderStateMachine
 * (payment-blind, frozen §7) ONLY — no payment-release guard — so an UNPAID
 * delivery order can be advanced ACCEPT→PREPARING→PREPARED while still UNPAID.
 * Such an order is hidden from the live board (list()) but leaks its full
 * payload (incl. customer address/phone via KDSOrderDetailsResource) into the
 * history view.
 *
 * Control: the same order is correctly ABSENT from list().
 */
class KdsHistoryTodayBoardReleaseLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_unpaid_prepared_order_leaks_into_history_today(): void
    {
        $branch = Branch::factory()->create();

        $chef = User::factory()->create(['branch_id' => $branch->id]);
        $chef->assignRole('Chef');
        $this->actingAs($chef, 'sanctum');

        $tax = Tax::create([
            'name' => 'TVA 10 HIST',
            'code' => 'TVA10HIST',
            'tax_rate' => 10,
            'type' => 2,
            'status' => 1,
        ]);

        $category = ItemCategory::forceCreate([
            'name' => 'Burgers HIST',
            'slug' => 'burgers-hist',
            'status' => Status::ACTIVE,
        ]);

        $secret = Item::forceCreate([
            'name' => 'SECRET_UNPAID_HISTORY_BURGER',
            'slug' => 'secret-unpaid-history-burger',
            'price' => 9.50,
            'status' => Status::ACTIVE,
            'item_category_id' => $category->id,
            'tax_id' => $tax->id,
        ]);

        // UNPAID DELIVERY order at status=PREPARED (reachable via
        // OrderService::changeStatus, which has no payment-release guard).
        // NOT released to the board: UNPAID, DELIVERY, no POS-cash exemption.
        $order = Order::forceCreate([
            'order_serial_no' => 'HIST-UNPAID-001',
            'user_id' => $chef->id,
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARED,
            'payment_status' => PaymentStatus::UNPAID,
            'payment_method' => 1,
            'order_type' => OrderType::DELIVERY,
            'source' => Source::WEB,
            'is_advance_order' => Ask::NO,
            'subtotal' => 28.50,
            'total' => 28.50,
            'discount' => 0,
            'delivery_charge' => 0,
            'order_datetime' => now(),
            'updated_at' => now(),
        ]);

        OrderItem::forceCreate([
            'order_id' => $order->id,
            'branch_id' => $branch->id,
            'item_id' => $secret->id,
            'quantity' => 3,
            'discount' => 0,
            'tax_name' => 'TVA 10 HIST',
            'tax_rate' => 10,
            'tax_type' => 2,
            'tax_amount' => 0.95,
            'price' => 9.50,
            'item_variations' => json_encode([]),
            'item_extras' => json_encode([]),
            'item_variation_total' => 0,
            'item_extra_total' => 0,
            'total_price' => 28.50,
            'instruction' => null,
            'allergens_snapshot' => null,
        ]);

        $apiKey = config('app.api_key');

        // CONTROL — list() must NOT surface the unreleased order.
        $listResp = $this->withHeader('x-api-key', $apiKey)
            ->getJson('/api/admin/kds-order');
        $listResp->assertOk();
        $listIds = collect(data_get($listResp->json(), 'data', []))->pluck('id')->all();
        $this->assertNotContains(
            $order->id,
            $listIds,
            'CONTROL: unreleased UNPAID PREPARED order must be ABSENT from list() (board cards).'
        );

        // SUBJECT — history-today endpoint.
        $histResp = $this->withHeader('x-api-key', $apiKey)
            ->getJson('/api/admin/kds-order/history-today');
        $histResp->assertOk();
        $histIds = collect(data_get($histResp->json(), 'data', []))->pluck('id')->all();

        // SENTINEL (post-heal KDS-ABUSE-03): the UNPAID PREPARED order must be ABSENT from the
        // history view — board-release parity with list() restored via applyBoardReleaseFilter().
        $this->assertNotContains(
            $order->id,
            $histIds,
            'historyToday() must NOT leak the UNPAID PREPARED order — board-release parity with list().'
        );
    }
}
