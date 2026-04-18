<?php

namespace Tests\Feature\Fiscal;

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\Fiscal\ZReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

/**
 * [POS-9-H.2.6 / F-B3]
 *
 * Z period must be a HALF-OPEN interval (from, to] so that every order
 * appears in EXACTLY one Z. Before this patch the aggregate window
 * was [from, to] (closed on both sides), which caused a boundary
 * instant to be counted twice when two consecutive Z reports shared
 * the same timestamp (e.g. close-at-08:00 immediately followed by
 * open-at-08:00).
 */
class ZReportBoundaryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('fiscal.audit_secret',    'unit-test-secret-boundary-x');
        Config::set('fiscal.z_report_secret', 'unit-test-secret-boundary-z');
    }

    public function test_order_on_lower_boundary_is_excluded(): void
    {
        $branch = Branch::factory()->create();
        $from   = Carbon::parse('2026-04-22 08:00:00');
        $to     = Carbon::parse('2026-04-22 23:59:59');

        // Order created at exactly the lower boundary — must NOT count
        // (belongs to the previous Z).
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'total'              => 10.00,
            'fiscal_sequence_no' => 1,
            'created_at'         => $from,
        ]);

        // Order created 1s after the lower boundary — must count.
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'total'              => 20.00,
            'fiscal_sequence_no' => 2,
            'created_at'         => $from->copy()->addSecond(),
        ]);

        // Order created at exactly the upper boundary — must count.
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'status'             => OrderStatus::DELIVERED,
            'total'              => 30.00,
            'fiscal_sequence_no' => 3,
            'created_at'         => $to,
        ]);

        $totals = app(ZReportService::class)->aggregate($branch->id, $from, $to);

        $this->assertSame(2, $totals['order_count'],
            'Lower-boundary order must be excluded (half-open interval).');
        $this->assertEqualsWithDelta(50.00, (float) $totals['total_ttc'], 0.01);
    }

    public function test_first_z_with_null_from_counts_whole_history(): void
    {
        $branch = Branch::factory()->create();
        $to     = Carbon::parse('2026-04-22 23:59:59');

        // Even orders from well before $to must count when $from is null.
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 5.00,
            'fiscal_sequence_no' => 1,
            'created_at'         => $to->copy()->subDays(30),
        ]);
        Order::factory()->create([
            'branch_id'          => $branch->id,
            'payment_status'     => PaymentStatus::PAID,
            'total'              => 7.00,
            'fiscal_sequence_no' => 2,
            'created_at'         => $to,
        ]);

        $totals = app(ZReportService::class)->aggregate($branch->id, null, $to);

        $this->assertSame(2, $totals['order_count']);
        $this->assertEqualsWithDelta(12.00, (float) $totals['total_ttc'], 0.01);
    }

    public function test_two_back_to_back_z_windows_never_double_count(): void
    {
        $branch  = Branch::factory()->create();
        $tBoundary = Carbon::parse('2026-04-22 23:59:59');

        // Three orders: before, AT, and after the shared boundary.
        Order::factory()->create([
            'branch_id' => $branch->id, 'payment_status' => PaymentStatus::PAID,
            'total' => 1.00, 'fiscal_sequence_no' => 1,
            'created_at' => $tBoundary->copy()->subHour(),
        ]);
        Order::factory()->create([
            'branch_id' => $branch->id, 'payment_status' => PaymentStatus::PAID,
            'total' => 2.00, 'fiscal_sequence_no' => 2,
            'created_at' => $tBoundary,                               // <-- edge
        ]);
        Order::factory()->create([
            'branch_id' => $branch->id, 'payment_status' => PaymentStatus::PAID,
            'total' => 4.00, 'fiscal_sequence_no' => 3,
            'created_at' => $tBoundary->copy()->addSecond(),
        ]);

        $svc = app(ZReportService::class);

        // Z1 window (null, $tBoundary] : counts 1 + 2 = 3.
        $z1 = $svc->aggregate($branch->id, null, $tBoundary);
        $this->assertEqualsWithDelta(3.00, (float) $z1['total_ttc'], 0.01);

        // Z2 window ($tBoundary, +inf) : counts ONLY the post-boundary 4.00.
        $z2 = $svc->aggregate($branch->id, $tBoundary, $tBoundary->copy()->addDay());
        $this->assertEqualsWithDelta(4.00, (float) $z2['total_ttc'], 0.01);

        // Sum of Z1+Z2 equals the whole dataset — no gap, no double-count.
        $this->assertEqualsWithDelta(7.00,
            (float) $z1['total_ttc'] + (float) $z2['total_ttc'],
            0.01,
            'Z1 + Z2 must partition the full history exactly.');
    }
}
