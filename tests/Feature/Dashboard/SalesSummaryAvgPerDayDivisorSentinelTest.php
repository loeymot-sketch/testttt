<?php

/**
 * [GOAL_SECOND_DEGREE_INDIRECT 2026-06-01 — DASH-SEM-02 heal]
 *
 * DashboardService::salesSummary computed the daily average as
 *   total_sales / date_diff
 * where date_diff = DateInterval %a = the number of days BETWEEN first_date
 * and last_date (an N-day inclusive range yields date_diff = N-1). So a
 * 7-day range divided the total by 6, OVERSTATING avg_per_day by ~16%.
 *
 * The inclusive day count is count($dateRangeArray) (= date_diff + 1), which
 * the per-day chart already iterates. Heal: divide by the inclusive day count.
 *
 * This sentinel uses ONLY PAID, non-cancelled orders so total_sales is exact
 * and the test isolates the DENOMINATOR (independent of the DASH-NET-01
 * cancel/refund-netting semantic, which is gated separately).
 *
 * @group sentinel
 * @group dashboard
 */

namespace Tests\Feature\Dashboard;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\TestCase;

class SalesSummaryAvgPerDayDivisorSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
    }

    public function test_avg_per_day_divides_by_inclusive_day_count(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        // One PAID order of 700 inside a 7-day inclusive range (Jan 1..Jan 7).
        Order::factory()->create([
            'branch_id'        => $branch->id,
            'status'           => OrderStatus::DELIVERED,
            'payment_status'   => PaymentStatus::PAID,
            'order_type'       => OrderType::KIOSK,
            'order_datetime'   => '2026-01-03 12:00:00',
            'total'            => 700,
            'is_advance_order' => Ask::NO,
            'source'           => Source::APP,
        ]);

        $result = app(DashboardService::class)->salesSummary(
            new Request(['first_date' => '2026-01-01', 'last_date' => '2026-01-07'])
        );

        // Correct: 700 / 7 inclusive days = 100.00 per day.
        // Buggy (÷ date_diff = 6): 116.67 — must NOT appear.
        $this->assertStringContainsString('100', (string) $result['avg_per_day'],
            'avg_per_day must divide the total by the INCLUSIVE day count (7), giving 100.00. Got: ' . $result['avg_per_day']);
        $this->assertStringNotContainsString('116', (string) $result['avg_per_day'],
            'avg_per_day must NOT divide by date_diff (6 → 116.67) — that overstates the daily average.');
    }

    public function test_single_day_range_avg_equals_total(): void
    {
        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        Order::factory()->create([
            'branch_id'        => $branch->id,
            'status'           => OrderStatus::DELIVERED,
            'payment_status'   => PaymentStatus::PAID,
            'order_type'       => OrderType::KIOSK,
            'order_datetime'   => '2026-01-03 12:00:00',
            'total'            => 250,
            'is_advance_order' => Ask::NO,
            'source'           => Source::APP,
        ]);

        // Single-day range: avg_per_day == total_sales (divisor 1), no div-by-zero.
        $result = app(DashboardService::class)->salesSummary(
            new Request(['first_date' => '2026-01-03', 'last_date' => '2026-01-03'])
        );

        $this->assertStringContainsString('250', (string) $result['avg_per_day']);
    }
}
