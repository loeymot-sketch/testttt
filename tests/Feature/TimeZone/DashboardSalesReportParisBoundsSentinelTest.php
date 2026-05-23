<?php

/**
 * GOAL-G2-HEAL-04 — Dashboard + Sales Report Paris bounds sentinel.
 * Authority: Phase G.10 finding (TZ-generation drift) + Wave T R5 pattern
 *            (commit 27d95e066, 2026-05-20).
 *
 * INTENT
 * Pin the Wave T R5 Paris bounds contract for the 5C-residual analytics
 * surfaces extended by G2-HEAL-04:
 *   - DashboardService::orderStatistics   (admin dashboard widgets)
 *   - DashboardService::realtimeReport    (CA du jour + ticket moyen)
 *   - DashboardService::channelStatistics (web/kiosk/POS pie chart)
 *   - DashboardService::salesSummary      (month-range total)
 *
 * SibSling existing sentinels (Wave T R5 + Wave 3c-inverted):
 *   - tests/Feature/Services/SisterServicesTzAwareTest.php     KDS+OSS day bounds
 *   - tests/Feature/Services/SisterServicesTzAwareV2Test.php   Dashboard + OrderService + OSS prune
 *
 * This sentinel adds the behavioral boundary-pin: at 23:30 Paris, today's
 * orders MUST be visible; at 00:30 Paris (next day), today's bounds MUST
 * reflect the NEW day (yesterday's orders dropped).
 *
 * SQLite (test driver) has no session-TZ concept. We pin behavior using
 * Carbon::setTestNow + Eloquent's Paris-formatted serialization of
 * order_datetime so the row-level boundary test is meaningful.
 *
 * INVARIANT DEPENDENCY (matches service inline comments):
 * heal assumes session_tz=OS-local (Paris). Future
 * config/database.php connections.mysql.timezone => '+00:00' MUST
 * re-evaluate.
 */

namespace Tests\Feature\TimeZone;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Enums\Source;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DashboardSalesReportParisBoundsSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedSpatieRoles();
        $this->seedMinimalSettings();
        Cache::flush();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * Pin "now" to 23:30 Paris (winter, no DST = UTC+1). Returns the
     * authenticated admin context + freshly created branch.
     *
     * @return array{0:Branch,1:User,2:CarbonImmutable}
     */
    private function pinParisNightAndAuth(string $parisWallTime): array
    {
        // Convert Paris wall time to UTC for the Carbon test-now (Eloquent
        // stores TIMESTAMP columns in UTC at write time but serializes in
        // app.timezone on read, so we use Paris CarbonImmutable for sanity).
        $parisNow = CarbonImmutable::parse($parisWallTime, 'Europe/Paris');
        $utcNow = $parisNow->setTimezone('UTC');
        Carbon::setTestNow($utcNow);
        CarbonImmutable::setTestNow($utcNow);

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        return [$branch, $admin, $parisNow];
    }

    /**
     * Capture compiled SQL + bindings for queries inside $callback.
     *
     * @return array<int, array{sql:string,bindings:array<int,string>}>
     */
    private function captureOrderQueries(callable $callback): array
    {
        $captured = [];
        DB::listen(function ($query) use (&$captured) {
            $captured[] = [
                'sql' => strtolower($query->sql),
                'bindings' => array_map(
                    static fn ($b) => is_object($b) && method_exists($b, 'format')
                        ? $b->format('Y-m-d H:i:s')
                        : (string) $b,
                    $query->bindings
                ),
            ];
        });

        $callback();

        $normalize = static fn (string $sql): string => str_replace(['`', '"'], '', $sql);
        return array_values(array_filter(
            $captured,
            function (array $q) use ($normalize): bool {
                $n = $normalize($q['sql']);
                return str_contains($n, 'from orders');
            }
        ));
    }

    /**
     * GREEN: at 23:30 Paris on 2026-05-23, DashboardService::orderStatistics
     *        bounds use today-Paris [00:00, 24:00). An order placed at
     *        23:30 Paris MUST be counted.
     *
     * RED (regression test): if anyone reintroduces the Wave 3c UTC-shift,
     *      bounds become [22:00, 22:00) and the 23:30 order is dropped.
     */
    public function test_dashboard_orderStatistics_includes_23h30_paris_order(): void
    {
        // Pin: 23:30 Paris winter, which is 22:30 UTC.
        [$branch, , $parisNow] = $this->pinParisNightAndAuth('2026-01-23 23:30:00');

        // Order placed 5 minutes earlier (still today-Paris).
        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => $parisNow->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
        ]);

        $stats = app(DashboardService::class)->orderStatistics(new Request());

        $this->assertSame(
            1,
            (int) $stats['total_order'],
            'Order placed at 23:25 Paris MUST be counted by orderStatistics at '
            . '23:30 Paris (Wave T R5 / GOAL-G2-HEAL-04 Paris bounds contract).'
        );
        $this->assertSame(1, (int) $stats['preparing_order']);
    }

    /**
     * GREEN: at 00:30 Paris on 2026-05-24 (next day), bounds reflect the
     *        NEW Paris day [2026-05-24 00:00, 2026-05-25 00:00). An order
     *        placed at 23:30 Paris on 2026-05-23 MUST NOT appear.
     */
    public function test_dashboard_orderStatistics_excludes_yesterday_paris_order(): void
    {
        // Pin: 00:30 Paris winter on day-N+1, which is 23:30 UTC on day-N.
        [$branch, , $parisNow] = $this->pinParisNightAndAuth('2026-01-24 00:30:00');

        // Yesterday's order at 23:30 Paris = 1h earlier in wall time.
        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => $parisNow->subHour(),
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
        ]);

        $stats = app(DashboardService::class)->orderStatistics(new Request());

        $this->assertSame(
            0,
            (int) $stats['total_order'],
            'Yesterday-Paris order at 23:30 MUST NOT appear in today-Paris '
            . 'window at 00:30 Paris (Wave T R5 / GOAL-G2-HEAL-04 boundary).'
        );
    }

    /**
     * Behavioral pin for realtimeReport — the CA du jour widget displayed
     * on the admin landing screen MUST include orders placed in the last
     * 1-2h of Paris day (22:00-24:00 Paris).
     */
    public function test_dashboard_realtimeReport_includes_late_evening_paris_order(): void
    {
        [$branch, , $parisNow] = $this->pinParisNightAndAuth('2026-01-23 23:45:00');

        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::DELIVERED,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => $parisNow->subMinutes(10),
            'total' => 42.50,
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
        ]);

        $realtime = app(DashboardService::class)->realtimeReport();

        $this->assertSame(
            1,
            (int) $realtime['daily_orders'],
            'realtimeReport MUST count order placed at 23:35 Paris when '
            . 'queried at 23:45 Paris.'
        );

        // total_sales is currency-formatted; assert it contains 42 (not 0).
        $this->assertStringContainsString(
            '42',
            (string) $realtime['daily_sales'],
            'daily_sales MUST reflect the 42.50€ paid order.'
        );
    }

    /**
     * SQL binding pin (cross-driver compatible): the bound literal MUST be
     * Paris-local Y-m-d H:i:s, NOT the UTC-shifted equivalent.
     */
    public function test_dashboard_binds_paris_local_literal_not_utc_shifted(): void
    {
        // 23:30 Paris winter = 22:30 UTC. UTC-shifted today-start would be
        // '2026-01-22 23:00:00' (yesterday Paris evening as UTC). Paris-
        // local today-start is '2026-01-23 00:00:00'.
        [$branch, , $parisNow] = $this->pinParisNightAndAuth('2026-01-23 23:30:00');

        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => $parisNow->subMinutes(5),
            'is_advance_order' => Ask::NO,
            'source' => Source::APP,
        ]);

        $queries = $this->captureOrderQueries(function () {
            app(DashboardService::class)->orderStatistics(new Request());
        });

        $allBindings = [];
        foreach ($queries as $q) {
            foreach ($q['bindings'] as $b) {
                $allBindings[] = (string) $b;
            }
        }
        $joined = implode(' | ', $allBindings);

        $this->assertStringContainsString(
            '2026-01-23 00:00:00',
            $joined,
            "DashboardService MUST bind Paris-local today-start '2026-01-23 00:00:00' "
            . "(Wave T R5 / GOAL-G2-HEAL-04 contract).\n"
            . "Captured: $joined"
        );

        $this->assertStringNotContainsString(
            '2026-01-22 23:00:00',
            $joined,
            "DashboardService MUST NOT bind UTC-shifted '2026-01-22 23:00:00' "
            . "(Wave 3c regression).\n"
            . "Captured: $joined"
        );
    }
}
