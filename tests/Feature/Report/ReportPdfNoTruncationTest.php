<?php

/**
 * [ULTRA-LOOP R1 2026-07-07 — PDF de rapport tronqué à la 1re page]
 *
 * Les endpoints PDF `admin/sales-report/pdf` et `admin/items-report/pdf`
 * appelaient le service SANS forcer `paginate=0`. L'UI envoie
 * paginate=1&per_page=10 → le service renvoyait un paginator de 10 lignes ;
 * le blade PDF n'itère (et n'agrège le "Total") QUE cette collection → CA/
 * unités massivement sous-déclarés (prouvé : 38 522,62 € réels affichés 6,70 €).
 *
 * Les jumeaux Excel (SalesReportExport:30 / ItemsReportExport:27) sont déjà
 * guéris via `$request->merge(['paginate' => 0])`. Ces sentinelles verrouillent
 * le même merge côté controller PDF : la collection passée au blade (donc le
 * Total qui en dérive) contient TOUTES les lignes, jamais 10.
 *
 * @group sentinel
 * @group reports
 */

namespace Tests\Feature\Report;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\Transaction;
use App\Models\User;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class ReportPdfNoTruncationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    private function actingAsReportAdmin(): User
    {
        // The report routes are gated by permission:sales-report / :items-report /
        // :online-orders, which the shared seedSpatieRoles() helper does not include —
        // grant them here. (online-orders added R2 for the OnlineOrderController::pdf twin.)
        foreach (['sales-report', 'items-report', 'online-orders'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->givePermissionTo(['sales-report', 'items-report', 'online-orders']);
        $this->actingAs($admin, 'sanctum');

        return $admin;
    }

    /**
     * Mock the DomPDF facade so we can inspect the exact data array the
     * controller passes to the blade (impossible to assert on the binary PDF).
     * Returns a ref-captured array the caller reads after hitting the route.
     */
    private function captureBladeData(array &$captured): void
    {
        Pdf::shouldReceive('loadView')
            ->once()
            ->andReturnUsing(function ($view, $data) use (&$captured) {
                $captured = $data;
                // Must satisfy loadView()'s declared return type Barryvdh\DomPDF\PDF.
                $stub = Mockery::mock(\Barryvdh\DomPDF\PDF::class);
                $stub->shouldReceive('setPaper')->andReturnSelf();
                $stub->shouldReceive('output')->andReturn('%PDF-1.4 stub');

                return $stub;
            });
    }

    /** FIX 1 (P1) — sales report PDF must contain EVERY order, not the first page of 10. */
    public function test_sales_report_pdf_is_not_truncated_to_ten_rows(): void
    {
        $this->actingAsReportAdmin();
        $branch = Branch::factory()->create();

        // 14 realized (PAID + DELIVERED) orders, distinct totals → full sum unambiguous.
        $expectedTotal = 0.0;
        for ($i = 1; $i <= 14; $i++) {
            $total = 100 + $i; // 101 … 114
            $expectedTotal += $total;
            Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::DELIVERED,
                'payment_status' => PaymentStatus::PAID,
                'order_type' => OrderType::KIOSK,
                'order_datetime' => '2026-03-15 12:00:00',
                'total' => $total,
                'discount' => 0,
                'delivery_charge' => 0,
                'is_advance_order' => Ask::NO,
                'source' => Source::APP,
            ]);
        }

        $captured = [];
        $this->captureBladeData($captured);

        // Mimic the real UI query: paginate=1&per_page=10 (the truncating payload).
        $this->get('/api/admin/sales-report/pdf?paginate=1&per_page=10&from_date=2026-03-01&to_date=2026-03-31')
            ->assertOk();

        $this->assertArrayHasKey('orders', $captured);
        $orders = $captured['orders'];

        // Bug repro (pre-fix): a LengthAwarePaginator with 10 rows → count() == 10.
        $this->assertCount(14, $orders, 'PDF must receive ALL 14 orders, not the paginated first page of 10.');

        // The blade computes the "Total" row by iterating THIS collection over
        // realized rows — so a complete collection == a complete total.
        $sum = 0.0;
        foreach ($orders as $o) {
            if (Order::isRealizedRevenueRow($o)) {
                $sum += (float) $o->total;
            }
        }
        $this->assertEqualsWithDelta($expectedTotal, $sum, 0.001,
            'PDF Total must aggregate every realized order (full sum), not the first-page subset.');
    }

    /** FIX 2 (P2) — items report PDF must contain EVERY catalog item, not 10. */
    public function test_items_report_pdf_is_not_truncated_to_ten_items(): void
    {
        $this->actingAsReportAdmin();

        // 14 catalog items (V1 catalogue = 45; 10-row page hid the rest).
        for ($i = 1; $i <= 14; $i++) {
            Item::factory()->create(['name' => 'Produit '.$i]);
        }

        $captured = [];
        $this->captureBladeData($captured);

        $this->get('/api/admin/items-report/pdf?paginate=1&per_page=10')
            ->assertOk();

        $this->assertArrayHasKey('items', $captured);
        // Pre-fix: paginator of 10 → count() == 10. Post-fix: full collection of 14.
        $this->assertCount(14, $captured['items'],
            'PDF must receive ALL catalog items, not the paginated first page of 10.');
    }

    /**
     * FIX 1 (P1 R2 2026-07-07) — OnlineOrderController::pdf is the 3rd truncation twin,
     * missed at R1. It must merge paginate=0 so the online-order PDF (and its blade Total,
     * which sums $order->total over EVERY row) covers the full result, not the first page of 10.
     */
    public function test_online_order_pdf_is_not_truncated_to_ten_rows(): void
    {
        $this->actingAsReportAdmin();
        $branch = Branch::factory()->create();

        // 14 orders, distinct totals → the full sum is unambiguous.
        $expectedTotal = 0.0;
        for ($i = 1; $i <= 14; $i++) {
            $total = 100 + $i; // 101 … 114
            $expectedTotal += $total;
            Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::DELIVERED,
                'payment_status' => PaymentStatus::PAID,
                'order_type' => OrderType::KIOSK,
                'order_datetime' => '2026-03-15 12:00:00',
                'total' => $total,
                'discount' => 0,
                'delivery_charge' => 0,
                'is_advance_order' => Ask::NO,
                'source' => Source::APP,
            ]);
        }

        $captured = [];
        $this->captureBladeData($captured);

        // Mimic the real UI query: paginate=1&per_page=10 (the truncating payload).
        $this->get('/api/admin/online-order/pdf?paginate=1&per_page=10')
            ->assertOk();

        $this->assertArrayHasKey('orders', $captured);
        $orders = $captured['orders'];

        // Bug repro (pre-fix): a LengthAwarePaginator with 10 rows → count() == 10.
        $this->assertCount(14, $orders, 'Online-order PDF must receive ALL 14 orders, not the first page of 10.');

        // online_orders.blade sums EVERY listed row ($total += $order->total) → a complete
        // collection == a complete printable Total.
        $sum = 0.0;
        foreach ($orders as $o) {
            $sum += (float) $o->total;
        }
        $this->assertEqualsWithDelta($expectedTotal, $sum, 0.001,
            'Online-order PDF Total must aggregate every order, not the first-page subset.');
    }

    /**
     * FIX 2 (P2 R2 2026-07-07) — the R1 paginate=0 fix, applied with NO date filter, would push
     * ~3000 rows into dompdf and blow up with an uncaught fatal PHP Error (HTTP 500 bypassing the
     * controller's catch(Exception)). The anti-OOM guard must instead short-circuit BEFORE render
     * with a CLEAN 422 when the row count exceeds the cap — while dated reports UNDER the cap keep
     * their exact, untruncated total.
     */
    public function test_pdf_export_over_row_cap_returns_clean_422_not_fatal_500(): void
    {
        $this->actingAsReportAdmin();
        $branch = Branch::factory()->create();

        // Lower the cap so the test stays fast (production default is 2000).
        config(['report.pdf_max_rows' => 3]);

        $expectedTotal = 0.0;
        for ($i = 1; $i <= 5; $i++) {
            $total = 10 + $i; // 11 … 15
            $expectedTotal += $total;
            Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::DELIVERED,
                'payment_status' => PaymentStatus::PAID,
                'order_type' => OrderType::KIOSK,
                'order_datetime' => '2026-03-15 12:00:00',
                'total' => $total,
                'discount' => 0,
                'delivery_charge' => 0,
                'is_advance_order' => Ask::NO,
                'source' => Source::APP,
            ]);
        }

        // 5 rows > cap 3 → clean 422, NOT a dompdf fatal 500. No Pdf mock here on purpose:
        // the guard must return BEFORE Pdf::loadView is ever reached.
        $over = $this->get('/api/admin/online-order/pdf');
        $over->assertStatus(422)->assertJson(['status' => false]);
        $this->assertStringContainsString('Trop de lignes', (string) $over->json('message'));
        $this->assertStringContainsString('Affinez la période', (string) $over->json('message'));

        // Under the cap: same data, higher cap → full render, total stays EXACT (no truncation).
        config(['report.pdf_max_rows' => 100]);
        $captured = [];
        $this->captureBladeData($captured);
        $this->get('/api/admin/online-order/pdf')->assertOk();

        $this->assertCount(5, $captured['orders'], 'Under the cap every row must render.');
        $sum = 0.0;
        foreach ($captured['orders'] as $o) {
            $sum += (float) $o->total;
        }
        $this->assertEqualsWithDelta($expectedTotal, $sum, 0.001,
            'Under the cap the printable Total must remain exact (guard must not truncate).');
    }

    /**
     * FIX 3 (P3 R2 2026-07-07) — the sales-report PDF blade rendered a raw machine enum
     * (strtoupper($order->transaction->payment_method) => "COUNTER_CASH") on a distinct render
     * path from the Transactions screen. The blade must now map it to a FR label ("Espèces
     * (Caisse)") and print FR column headers, never the raw constant.
     */
    public function test_sales_report_pdf_blade_localizes_counter_payment_enum_and_headers(): void
    {
        $branch = Branch::factory()->create();
        $order = Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => '2026-03-15 12:00:00',
            'total' => 12.50,
            'discount' => 0,
            'delivery_charge' => 0,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
        ]);
        Transaction::create([
            'order_id' => $order->id,
            'transaction_no' => 'T-'.$order->id,
            'amount' => 12.50,
            'payment_method' => 'counter_cash', // stored lowercase; blade used to strtoupper() it
        ]);

        $html = view('pdf.sales_report', [
            'company' => [],
            'theme_logo' => null,
            'orders' => Order::with('transaction')->whereKey($order->id)->get(),
            'copyright' => '© Le Cayenne',
        ])->render();

        // The raw enum must be gone; the FR label must be present.
        $this->assertStringNotContainsString('COUNTER_CASH', $html,
            'PDF must not leak the raw payment enum.');
        $this->assertStringContainsString('Espèces (Caisse)', $html,
            'PDF must map counter_cash to the FR label.');

        // Headers translated to FR (default locale) instead of the forced English trans(..., "en").
        $this->assertStringContainsString('Type de paiement', $html, 'PDF header must be FR.');
        $this->assertStringNotContainsString('Payment Type', $html, 'PDF must not keep EN headers.');
    }
}
