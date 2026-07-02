<?php

namespace Tests\Feature\Fiscal;

use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Models\ZReport;
use App\Services\Fiscal\XReportService;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [POS-9.4.8 / POS-GA-F-02]
 *
 * Intraday X snapshot must:
 *  - never write;
 *  - reflect exactly what Z would produce over the same window;
 *  - start its window at the last closed Z (or opened Z if no close yet).
 */
class XReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.z_report_secret', 'unit-test-z-secret');
    }

    public function test_snapshot_matches_orders_since_last_close(): void
    {
        $branch = Branch::factory()->create();

        Carbon::setTestNow(Carbon::create(2026, 4, 22, 8, 0, 0));
        $zs = app(ZReportService::class);
        $zs->open($branch->id);
        $zs->close($branch->id);

        // After the Z close, a PAID order comes in.
        Carbon::setTestNow(Carbon::create(2026, 4, 22, 10, 15, 0));
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'total'              => 12.34,
            'payment_status'     => PaymentStatus::PAID,
            'fiscal_sequence_no' => 1,
        ]);
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'total'              => 8.00,
            'payment_status'     => PaymentStatus::PAID,
            'fiscal_sequence_no' => 2,
        ]);
        // UNPAID must not count.
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'total'              => 5.00,
            'payment_status'     => PaymentStatus::UNPAID,
            'fiscal_sequence_no' => 3,
        ]);

        Carbon::setTestNow(Carbon::create(2026, 4, 22, 14, 0, 0));
        $snap = app(XReportService::class)->snapshot($branch->id);

        $this->assertSame($branch->id, $snap['branch_id']);
        $this->assertSame(2, $snap['totals']['order_count']);
        $this->assertEqualsWithDelta(20.34, $snap['totals']['total_ttc'], 0.001);

        // Snapshot does not write anywhere new.
        $this->assertSame(1, ZReport::query()->where('branch_id', $branch->id)->count());

        Carbon::setTestNow(null);
    }

    public function test_snapshot_handles_no_prior_z(): void
    {
        $branch = Branch::factory()->create();

        Carbon::setTestNow(Carbon::create(2026, 4, 22, 9, 0, 0));
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'total'              => 4.20,
            'payment_status'     => PaymentStatus::PAID,
            'fiscal_sequence_no' => 1,
        ]);

        Carbon::setTestNow(Carbon::create(2026, 4, 22, 13, 0, 0));
        $snap = app(XReportService::class)->snapshot($branch->id);

        // No open or closed Z → service falls back to null $from (whole history).
        $this->assertNull($snap['period']['from']);
        $this->assertSame(1, $snap['totals']['order_count']);

        Carbon::setTestNow(null);
    }

    /**
     * [ULTRA-AUDIT 2026-07-02] GET /x-report avec une date malformée doit renvoyer 422
     * (validation), PAS 500 (InvalidFormatException non catchée = fuite de trace si APP_DEBUG).
     * Cohérent avec DashboardController::eodPdf.
     */
    public function test_bad_date_param_returns_422_not_500(): void
    {
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();

        $branch = Branch::factory()->create();
        $manager = User::factory()->create(['branch_id' => $branch->id]);
        $manager->givePermissionTo('pos-manage-fiscal');

        $this->actingAs($manager, 'sanctum')
            ->withHeaders(['x-api-key' => config('app.api_key')])
            ->getJson('/api/admin/fiscal/x-report?from=garbagenotadate')
            ->assertStatus(422);
    }

    public function test_snapshot_is_idempotent(): void
    {
        $branch = Branch::factory()->create();
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'total'              => 9.99,
            'payment_status'     => PaymentStatus::PAID,
            'fiscal_sequence_no' => 1,
        ]);

        $service = app(XReportService::class);

        $a = $service->snapshot($branch->id);
        $b = $service->snapshot($branch->id);

        $this->assertSame($a['totals']['order_count'], $b['totals']['order_count']);
        $this->assertSame($a['totals']['total_ttc'],   $b['totals']['total_ttc']);
    }
}
