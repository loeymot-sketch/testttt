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
        // The report routes are gated by permission:sales-report / :items-report,
        // which the shared seedSpatieRoles() helper does not include — grant them here.
        foreach (['sales-report', 'items-report'] as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->givePermissionTo(['sales-report', 'items-report']);
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
}
