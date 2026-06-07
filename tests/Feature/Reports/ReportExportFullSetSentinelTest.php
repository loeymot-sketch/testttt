<?php

/**
 * [REP-EXP-01 FIX 2026-06-06 — P1 data-integrity]
 *
 * The Excel/PDF exports for Sales, Items and Transactions reused the screen's
 * paginated request (`paginate:1, per_page:10, page:N`) and therefore exported
 * ONLY the current page (10 rows); the PDF "Total" summed only those 10 rows.
 *
 * Heal: the Export classes force `paginate => 0` so `OrderService::list /
 * TransactionService::list / ItemService::itemReport` take the `get()` branch
 * (full filtered dataset) regardless of what the front-end forwards. This
 * sentinel drives each Export's collection() with a paginated request and
 * proves the FULL set comes back (header row(s) aside).
 *
 * Defense in depth: the front-end ALSO strips pagination before dispatching
 * (Vitest), and the pdf() controllers force paginate=0 (covered indirectly via
 * the same service `get()` branch the Export relies on).
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
use App\Exports\ItemsReportExport;
use App\Exports\SalesReportExport;
use App\Exports\TransactionExport;
use App\Http\Requests\PaginateRequest;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Transaction;
use App\Models\User;
use App\Services\ItemService;
use App\Services\OrderService;
use App\Services\TransactionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ReportExportFullSetSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');
    }

    /** A paginated request (paginate:1, per_page:10) — exactly what the screen sends. */
    private function paginatedRequest(array $extra = []): PaginateRequest
    {
        return PaginateRequest::create('/', 'GET', array_merge([
            'paginate'  => 1,
            'per_page'  => 10,
            'page'      => 1,
            'order_column' => 'id',
        ], $extra));
    }

    public function test_sales_report_export_returns_all_rows_not_just_one_page(): void
    {
        $branch = Branch::factory()->create();

        // 23 orders > 1 page of 10.
        for ($i = 0; $i < 23; $i++) {
            Order::factory()->create([
                'branch_id'       => $branch->id,
                'status'          => OrderStatus::DELIVERED,
                'payment_status'  => PaymentStatus::PAID,
                'order_type'      => OrderType::KIOSK,
                'order_datetime'  => '2026-03-15 12:00:00',
                'total'           => 10,
                'discount'        => 0,
                'delivery_charge' => 0,
                'is_advance_order' => Ask::NO,
                'source'          => Source::APP,
            ]);
        }

        $export = new SalesReportExport(app(OrderService::class), $this->paginatedRequest());
        $rows   = $export->collection();

        // FULL set = 23 rows (Maatwebsite WithHeadings adds the header separately,
        // so collection() itself holds only data rows). Buggy export returned 10.
        $this->assertCount(23, $rows, 'Sales export must contain ALL 23 filtered orders, not one page of 10.');
    }

    public function test_transaction_export_returns_all_rows_not_just_one_page(): void
    {
        $branch = Branch::factory()->create();

        // 15 transactions > 1 page of 10. Transaction has no factory — fill directly.
        for ($i = 0; $i < 15; $i++) {
            $order = Order::factory()->create([
                'branch_id'      => $branch->id,
                'status'         => OrderStatus::DELIVERED,
                'payment_status' => PaymentStatus::PAID,
                'order_type'     => OrderType::KIOSK,
                'order_datetime' => '2026-03-15 12:00:00',
                'total'          => 10,
                'is_advance_order' => Ask::NO,
                'source'         => Source::APP,
            ]);
            Transaction::create([
                'order_id'       => $order->id,
                'transaction_no' => 'TX-' . str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'amount'         => 10,
                'payment_method' => 'cash',
                'type'           => 1,
                'sign'           => '+',
            ]);
        }

        $export = new TransactionExport(app(TransactionService::class), $this->paginatedRequest(['branch_id' => $branch->id]));
        $rows   = $export->collection();

        $this->assertCount(15, $rows, 'Transaction export must contain ALL 15 transactions, not one page of 10.');
    }

    public function test_items_report_export_returns_all_items_with_correct_grand_total(): void
    {
        $branch = Branch::factory()->create();

        // 12 distinct items each sold once (1 unit) within range. A paginated
        // request would truncate to 10. Grand total units = 12.
        $items = [];
        for ($i = 0; $i < 12; $i++) {
            $items[] = Item::factory()->create(['name' => 'Item-' . $i]);
        }
        foreach ($items as $item) {
            $order = Order::factory()->create([
                'branch_id'      => $branch->id,
                'status'         => OrderStatus::DELIVERED,
                'payment_status' => PaymentStatus::PAID,
                'order_type'     => OrderType::KIOSK,
                'order_datetime' => '2026-03-15 12:00:00',
                'total'          => 10,
                'is_advance_order' => Ask::NO,
                'source'         => Source::APP,
            ]);
            OrderItem::create([
                'order_id'     => $order->id,
                'item_id'      => $item->id,
                'quantity'     => 1,
                'branch_id'    => $branch->id,
                'released_qty' => 0,
                'discount'     => 0,
                'price'        => 10,
            ]);
        }

        $export = new ItemsReportExport(app(ItemService::class), $this->paginatedRequest());
        $rows   = $export->collection()->values();

        // collection() = 12 item data rows + 1 appended TOTAL row = 13.
        $this->assertCount(13, $rows, 'Items export must list ALL 12 items + 1 total row.');

        // Last row is the grand total — units column = 12 (full set, not 1 page).
        $totalRow = $rows->last();
        $this->assertSame(12, (int) $totalRow[3], 'Items export grand total must sum ALL 12 items (full set), not one page.');
    }
}
