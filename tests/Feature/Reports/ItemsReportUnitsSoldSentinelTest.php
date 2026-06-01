<?php

/**
 * [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — ITEMS-SEM-01/02/NET-03/SEM-04 heal]
 *
 * Items report "quantity sold" was COUNT of order lines (not SUM of units),
 * date-filtered on Item.created_at (catalog creation, not the sale), and counted
 * cancelled/refunded/unpaid lines. Heal: SUM(order_items.quantity) over NET
 * realized orders (Order::scopeRealizedRevenue) scoped to the SALE's order_datetime.
 *
 * @group sentinel
 * @group reports
 */

namespace Tests\Feature\Reports;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\User;
use App\Http\Requests\PaginateRequest;
use App\Services\ItemService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ItemsReportUnitsSoldSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_units_sold_sums_quantity_excludes_cancelled_and_out_of_range(): void
    {
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $branch = Branch::factory()->create();
        $itemA = Item::factory()->create(['name' => 'Tacos']);
        $itemB = Item::factory()->create(['name' => 'Frites']);
        $itemC = Item::factory()->create(['name' => 'NeverSold']);

        $order = function (int $status, int $pay, string $dt) use ($branch) {
            return Order::factory()->create([
                'branch_id' => $branch->id, 'status' => $status, 'payment_status' => $pay,
                'order_type' => OrderType::KIOSK, 'order_datetime' => $dt, 'total' => 10,
                'is_advance_order' => Ask::NO, 'source' => Source::APP,
            ]);
        };
        $line = fn (Order $o, Item $i, int $qty) => OrderItem::create([
            'order_id' => $o->id, 'item_id' => $i->id, 'quantity' => $qty, 'branch_id' => $o->branch_id,
            'released_qty' => 0, 'discount' => 0, 'price' => 10,
        ]);

        // In-range realized sales of A: 3 + 2 = 5 units across 2 lines.
        $o1 = $order(OrderStatus::DELIVERED, PaymentStatus::PAID, '2026-03-10 12:00:00');
        $line($o1, $itemA, 3);
        $line($o1, $itemB, 1);
        $o2 = $order(OrderStatus::PREPARED, PaymentStatus::PAID, '2026-03-12 12:00:00');
        $line($o2, $itemA, 2);
        // Cancelled-paid order with 10 units of A → MUST be excluded.
        $o3 = $order(OrderStatus::CANCELED, PaymentStatus::PAID, '2026-03-13 12:00:00');
        $line($o3, $itemA, 10);
        // Realized B sale OUTSIDE the date range → excluded when filtered.
        $o4 = $order(OrderStatus::DELIVERED, PaymentStatus::PAID, '2026-02-01 12:00:00');
        $line($o4, $itemB, 5);

        $report = app(ItemService::class)->itemReport(
            PaginateRequest::create('/', 'GET', ['from_date' => '2026-03-01', 'to_date' => '2026-03-31'])
        );
        $byId = $report->keyBy('id');

        // A = 3 + 2 = 5 (SUM units, NOT 2 lines, NOT 15 incl cancelled).
        $this->assertSame(5, (int) ($byId[$itemA->id]->units_sold ?? 0), 'A must be 5 units sold (sum, realized, in-range).');
        // B = 1 (the 5-unit Feb sale is out of range).
        $this->assertSame(1, (int) ($byId[$itemB->id]->units_sold ?? 0), 'B must be 1 (out-of-range Feb sale excluded).');
        // C never sold → 0/null.
        $this->assertSame(0, (int) ($byId[$itemC->id]->units_sold ?? 0));
    }
}
