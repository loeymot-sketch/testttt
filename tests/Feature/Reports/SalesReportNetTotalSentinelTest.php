<?php

/**
 * [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — SALES-NET-01 heal]
 *
 * The sales report MONEY total must be NET realized (owner: "agree with the Z"):
 * exclude cancelled-but-paid orders and net out refund counter-entry mirrors —
 * the same semantic as the dashboard (Order::scopeRealizedRevenue) and the Z.
 *
 * Pre-heal: the on-screen overview card summed payment_status=PAID (kept
 * cancelled-paid, dropped negative refund mirrors), and the PDF "Total" row
 * summed ALL rows (incl. unpaid/pending). This sentinel locks the shared
 * Order::isRealizedRevenueRow predicate + the overview card total.
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
use App\Models\Order;
use App\Models\User;
use App\Services\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SalesReportNetTotalSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_realized_row_predicate(): void
    {
        $clean = new Order(['payment_status' => PaymentStatus::PAID, 'status' => OrderStatus::DELIVERED]);
        $cancelledPaid = new Order(['payment_status' => PaymentStatus::PAID, 'status' => OrderStatus::CANCELED]);
        $refundedParent = new Order(['payment_status' => PaymentStatus::PAID, 'status' => OrderStatus::DELIVERED]);
        $mirror = new Order(['payment_status' => PaymentStatus::REFUNDED, 'status' => OrderStatus::RETURNED, 'parent_order_id' => 1]);
        $unpaid = new Order(['payment_status' => PaymentStatus::PENDING_COUNTER, 'status' => OrderStatus::ACCEPT]);

        $this->assertTrue(Order::isRealizedRevenueRow($clean));
        $this->assertFalse(Order::isRealizedRevenueRow($cancelledPaid), 'cancelled-but-paid must be excluded');
        $this->assertTrue(Order::isRealizedRevenueRow($refundedParent));
        $this->assertTrue(Order::isRealizedRevenueRow($mirror), 'refund counter-entry mirror is included (negative total nets)');
        $this->assertFalse(Order::isRealizedRevenueRow($unpaid), 'pending-counter unpaid not realized revenue');
    }

    /**
     * R-NET-PDF-01 (ultra-review 2026-06-14): the PDF "Total" row must net refunded
     * sales' discount + delivery the SAME way the on-screen card does — the refund
     * mirror carries discount=0/delivery=0, so without excluding the refunded parent
     * the PDF over-counted them and diverged from the card. Distinctive amounts make
     * the netted vs buggy totals unambiguous.
     */
    public function test_pdf_total_row_nets_refunded_discount_and_delivery(): void
    {
        $branch = Branch::factory()->create();

        $mk = fn (int $status, int $pay, float $total, float $disc, float $deliv, ?int $parent = null) => Order::factory()->create([
            'branch_id' => $branch->id, 'status' => $status, 'payment_status' => $pay,
            'order_type' => OrderType::KIOSK, 'order_datetime' => '2026-03-15 12:00:00',
            'total' => $total, 'discount' => $disc, 'delivery_charge' => $deliv,
            'parent_order_id' => $parent, 'is_advance_order' => Ask::NO, 'source' => Source::APP,
        ]);

        $clean  = $mk(OrderStatus::DELIVERED, PaymentStatus::PAID, 60.0, 11.0, 13.0);
        $parent = $mk(OrderStatus::DELIVERED, PaymentStatus::PAID, 500.0, 500.0, 700.0);
        $mk(OrderStatus::RETURNED, PaymentStatus::REFUNDED, -500.0, 0.0, 0.0, $parent->id); // refund mirror

        $orders     = Order::query()->whereIn('id', [$clean->id, $parent->id])
            ->orWhere('parent_order_id', $parent->id)->get();
        $company    = ['company_name' => 'Le Cayenne', 'company_address' => ''];
        $copyright  = 'test';
        $theme_logo = '';

        $html = view('pdf.sales_report', compact('orders', 'company', 'copyright', 'theme_logo'))->render();

        // Net discount = 11 (refunded parent's 500 excluded); buggy = 511.
        $this->assertStringNotContainsString('511', $html, 'PDF must NOT count the refunded parent discount (buggy 511).');
        // Net delivery = 13 (refunded parent's 700 excluded); buggy = 713.
        $this->assertStringNotContainsString('713', $html, 'PDF must NOT count the refunded parent delivery (buggy 713).');
    }

    public function test_overview_card_total_is_net_realized(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $mk = fn (int $status, int $pay, float $total, ?int $parent = null) => Order::factory()->create([
            'branch_id' => $branch->id, 'status' => $status, 'payment_status' => $pay,
            'order_type' => OrderType::KIOSK, 'order_datetime' => '2026-03-15 12:00:00',
            'total' => $total, 'discount' => 0, 'delivery_charge' => 0,
            'parent_order_id' => $parent, 'is_advance_order' => Ask::NO, 'source' => Source::APP,
        ]);

        $mk(OrderStatus::DELIVERED, PaymentStatus::PAID, 100.0);
        $mk(OrderStatus::CANCELED, PaymentStatus::PAID, 50.0);                 // must drop
        $parent = $mk(OrderStatus::DELIVERED, PaymentStatus::PAID, 30.0);
        $mk(OrderStatus::RETURNED, PaymentStatus::REFUNDED, -30.0, $parent->id); // nets parent

        $overview = app(OrderService::class)->salesReportOverview(
            new Request(['from_date' => '2026-03-01', 'to_date' => '2026-03-31'])
        );

        // Net = 100 + 30 - 30 = 100 (cancelled 50 dropped, refund netted). Buggy = 180.
        $this->assertStringContainsString('100', (string) $overview['total_earnings']);
        $this->assertStringNotContainsString('180', (string) $overview['total_earnings']);
        $this->assertStringNotContainsString('150', (string) $overview['total_earnings']);
    }
}
