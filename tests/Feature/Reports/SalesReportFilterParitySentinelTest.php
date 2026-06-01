<?php

/**
 * [GOAL_V1_LOCAL_GOLIVE W1.5 — SALES-PAR-03 + SALES-PAR-05 heal 2026-06-01]
 *
 * The sales-report table (list) and overview cards diverged on filters:
 *  - PAR-03: `source` was LIKE-matched in list() (over-match risk on an int enum) but
 *    exact-matched in overview → different rows for the same source filter.
 *  - PAR-05: `exceptSource` was honoured by list() but ignored by overview.
 * Both now use source EXACT match + honour exceptSource → table and cards agree.
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
use App\Http\Requests\PaginateRequest;
use Tests\TestCase;

class SalesReportFilterParitySentinelTest extends TestCase
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

    private function mk(Branch $b, int $source): void
    {
        Order::factory()->create([
            'branch_id' => $b->id, 'status' => OrderStatus::DELIVERED, 'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK, 'order_datetime' => '2026-03-15 12:00:00', 'total' => 10,
            'source' => $source, 'is_advance_order' => Ask::NO,
        ]);
    }

    public function test_source_exact_match_and_exceptSource_parity(): void
    {
        $this->actAsAdmin();
        $branch = Branch::factory()->create();
        $this->mk($branch, Source::WEB);  // 5
        $this->mk($branch, Source::WEB);  // 5
        $this->mk($branch, Source::APP);  // 10

        $svc = app(OrderService::class);
        $range = ['from_date' => '2026-03-01', 'to_date' => '2026-03-31'];

        // exceptSource = APP(10): both surfaces must exclude it → 2 WEB orders.
        $listExcept = $svc->list(PaginateRequest::create('/', 'GET', $range + ['exceptSource' => Source::APP]));
        $ovExcept = $svc->salesReportOverview(PaginateRequest::create('/', 'GET', $range + ['exceptSource' => Source::APP]));
        $this->assertSame(2, $listExcept->count(), 'list() must exclude exceptSource=APP → 2 WEB orders.');
        $this->assertSame(2, (int) $ovExcept['total_orders'], 'overview must honour exceptSource (parity with list).');

        // source = WEB(5): exact match → exactly 2 (not LIKE-broadened).
        $listSrc = $svc->list(PaginateRequest::create('/', 'GET', $range + ['source' => Source::WEB]));
        $this->assertSame(2, $listSrc->count(), 'list() source must be exact-matched.');
    }
}
