<?php

/**
 * Wave 3c — KDS-ADV3C-01 / KDS-ADV3C-02 / KDS-ADV3C-03 / KDS-ADV3C-04 sentinel.
 * Authority: reports/audit/critical-focus-2026-05-18/wave-3c/adv-1-kds-heals-r3.md
 *
 * Wave 2c heals KdsSyncService + KitchenDisplaySystemOrderService + the two
 * OSS list paths. Wave 3c adversarial flagged that the SAME Paris-vs-UTC
 * bug-class survives in adjacent services:
 *
 *   - DashboardService (every admin widget)               KDS-ADV3C-01 P0
 *   - OrderService::list + salesReportOverview (PaginateRequest from_date) KDS-ADV3C-02 P1
 *   - ResetStaleDailyQuotaCommand + AvailabilityService daily reset        KDS-ADV3C-03 P1
 *   - OrderStatusScreenOrderService now()->subHours(N) prune line          KDS-ADV3C-04 P0
 *
 * Bug: `Carbon::today()` and bare `now()` resolve in app.timezone='Europe/Paris'.
 * The mysql connection in config/database.php has NO `timezone` key — PDO's
 * MySQL session TZ defaults to UTC. Eloquent serializes the Carbon to
 * 'Y-m-d H:i:s' Paris-local and binds raw to UTC-stored TIMESTAMP columns →
 * window shift of 1-2h every day (DST-dependent).
 *
 * Heal: convert Paris-local boundary → UTC before binding (KdsSyncService /
 * KitchenDisplaySystemOrderService pattern) OR call `now('UTC')` for raw
 * subHours predicates.
 *
 * SQLite (test driver) has no session-TZ concept, so a row-count behavioral
 * test would pass both pre- and post-heal. These sentinels pin the compiled
 * SQL bindings: they assert the bound literals are UTC representations of
 * Paris-local boundaries, NOT the Paris-local literal.
 */

namespace Tests\Feature\Services;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Enums\OrderType;
use App\Enums\PaymentStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardService;
use App\Services\OrderService;
use App\Services\OrderStatusScreenOrderService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class SisterServicesTzAwareV2Test extends TestCase
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
     * Pin "now" to a Paris-winter timestamp (no DST ambiguity, Paris = UTC+1).
     * Returns the expected UTC literals for assertions.
     *
     * @return array{0:string,1:string,2:string,3:CarbonImmutable}
     */
    private function pinParisWinterNow(): array
    {
        $now = CarbonImmutable::parse('2026-01-15 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $appTz = config('app.timezone');
        $expectedStartUtc = Carbon::today($appTz)->setTimezone('UTC')->format('Y-m-d H:i:s');
        $expectedEndUtc = Carbon::today($appTz)->endOfDay()->setTimezone('UTC')->format('Y-m-d H:i:s');
        $expectedTomorrowUtc = Carbon::tomorrow($appTz)->setTimezone('UTC')->format('Y-m-d H:i:s');

        // Sanity: Paris winter = UTC+1.
        $this->assertSame('2026-01-14 23:00:00', $expectedStartUtc);
        $this->assertSame('2026-01-15 22:59:59', $expectedEndUtc);
        $this->assertSame('2026-01-15 23:00:00', $expectedTomorrowUtc);

        return [$expectedStartUtc, $expectedEndUtc, $expectedTomorrowUtc, $now];
    }

    /**
     * Capture compiled SQL + bindings for queries inside $callback.
     *
     * @return array<int, array{sql:string,bindings:array<int,string>}>
     */
    private function captureOrderQueries(callable $callback, string $tableNeedle = 'orders'): array
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
            function (array $q) use ($normalize, $tableNeedle): bool {
                $n = $normalize($q['sql']);
                return str_contains($n, 'from ' . $tableNeedle);
            }
        ));
    }

    /**
     * Assert at least one binding contains the UTC literal AND no binding
     * carries the Paris-local midnight literal (which would indicate the
     * pre-heal Paris-bound regression).
     */
    private function assertBindingsContainUtcNotParisLocal(
        array $queries,
        string $expectedUtcLiteral,
        string $parisLocalLiteral,
        string $service
    ): void {
        $this->assertNotEmpty(
            $queries,
            "Expected at least one query for $service."
        );

        $allBindings = [];
        foreach ($queries as $q) {
            foreach ($q['bindings'] as $b) {
                $allBindings[] = (string) $b;
            }
        }
        $joined = implode(' | ', $allBindings);

        $this->assertStringNotContainsString(
            $parisLocalLiteral,
            $joined,
            "$service MUST NOT bind Paris-local literal '$parisLocalLiteral' (pre-heal regression).\n"
            . "Captured: $joined"
        );

        $this->assertStringContainsString(
            $expectedUtcLiteral,
            $joined,
            "$service MUST bind UTC literal '$expectedUtcLiteral'.\n"
            . "Captured: $joined"
        );
    }

    // ---------------------------------------------------------------------
    // KDS-ADV3C-01 P0 — DashboardService TZ-aware
    // ---------------------------------------------------------------------

    /**
     * RED: fails when DashboardService::orderStatistics binds Paris-local
     *      midnight literal ('2026-01-15 00:00:00') to MySQL UTC session.
     * GREEN: post-heal binds Paris-today start in UTC ('2026-01-14 23:00:00')
     *        and Paris-tomorrow start in UTC ('2026-01-15 23:00:00').
     */
    public function test_dashboard_orderStatistics_binds_utc_converted_today(): void
    {
        [, , , $now] = $this->pinParisWinterNow();

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        // Seed a couple of orders so the SELECT emits.
        Order::factory()->count(2)->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => $now->subHour(),
        ]);

        $queries = $this->captureOrderQueries(function () {
            app(DashboardService::class)->orderStatistics(new Request());
        });

        $this->assertBindingsContainUtcNotParisLocal(
            $queries,
            '2026-01-14 23:00:00',        // expected UTC lower bound
            '2026-01-15 00:00:00',        // forbidden Paris-local midnight
            'DashboardService::orderStatistics'
        );
    }

    /**
     * RED: fails when DashboardService::realtimeReport binds Paris-local
     *      midnight literal to MySQL UTC session.
     * GREEN: post-heal binds the UTC TIMESTAMP boundary pair.
     */
    public function test_dashboard_realtimeReport_binds_utc_converted_today(): void
    {
        [, , , $now] = $this->pinParisWinterNow();

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        Order::factory()->count(2)->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => $now->subHour(),
        ]);

        $queries = $this->captureOrderQueries(function () {
            app(DashboardService::class)->realtimeReport();
        });

        $this->assertBindingsContainUtcNotParisLocal(
            $queries,
            '2026-01-14 23:00:00',
            '2026-01-15 00:00:00',
            'DashboardService::realtimeReport'
        );
    }

    // ---------------------------------------------------------------------
    // KDS-ADV3C-04 P0 — OSS stale-prune `now()->subHours()` UTC binding
    // ---------------------------------------------------------------------

    /**
     * RED: fails when OrderStatusScreenOrderService::list binds
     *      Paris-local 'now()->subHours(8)' literal to MySQL UTC.
     * GREEN: passes when service uses now('UTC')->subHours(N) so the bound
     *        literal matches the UTC-stored TIMESTAMP column.
     *
     * Paris-winter now = 2026-01-15 12:00 UTC = 2026-01-15 13:00 Paris.
     * Pre-heal: now() in Paris → 13:00 - 8h = 05:00 (Paris-local '2026-01-15 05:00:00').
     * Post-heal: now('UTC') → 12:00 - 8h = 04:00 (UTC '2026-01-15 04:00:00').
     */
    public function test_oss_list_stale_prune_binds_utc_now(): void
    {
        [, , , $now] = $this->pinParisWinterNow();

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        // Seed at least one order for the prune predicate to emit.
        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => $now->subHour(),
            'is_advance_order' => Ask::NO,
            'queue_number' => 42,
            'token' => 'tok-prune',
        ]);

        $queries = $this->captureOrderQueries(function () use ($branch) {
            request()->query->set('branch_id', $branch->id);
            app(OrderStatusScreenOrderService::class)->list();
        });

        $allBindings = [];
        foreach ($queries as $q) {
            foreach ($q['bindings'] as $b) {
                $allBindings[] = (string) $b;
            }
        }
        $joined = implode(' | ', $allBindings);

        // Heal: now('UTC') = 2026-01-15 12:00 - 8h = 2026-01-15 04:00 UTC.
        $this->assertStringContainsString(
            '2026-01-15 04:00:00',
            $joined,
            "OSS::list MUST bind now('UTC')->subHours(8) = '2026-01-15 04:00:00'.\n"
            . "Captured: $joined"
        );

        // Negative: Paris-local would have been 2026-01-15 05:00:00 (13:00 Paris - 8h).
        $this->assertStringNotContainsString(
            '2026-01-15 05:00:00',
            $joined,
            "OSS::list MUST NOT bind Paris-local now()->subHours(8) = '2026-01-15 05:00:00' (pre-heal regression).\n"
            . "Captured: $joined"
        );
    }

    /**
     * RED mirror of the previous test for the listForBranch() public path.
     */
    public function test_oss_listForBranch_stale_prune_binds_utc_now(): void
    {
        [, , , $now] = $this->pinParisWinterNow();

        $branch = Branch::factory()->create();

        Order::factory()->create([
            'branch_id' => $branch->id,
            'status' => OrderStatus::PREPARING,
            'payment_status' => PaymentStatus::PAID,
            'order_type' => OrderType::KIOSK,
            'order_datetime' => $now->subHour(),
            'is_advance_order' => Ask::NO,
            'queue_number' => 42,
            'token' => 'tok-prune-public',
        ]);

        $queries = $this->captureOrderQueries(function () use ($branch) {
            app(OrderStatusScreenOrderService::class)->listForBranch($branch->id);
        });

        $allBindings = [];
        foreach ($queries as $q) {
            foreach ($q['bindings'] as $b) {
                $allBindings[] = (string) $b;
            }
        }
        $joined = implode(' | ', $allBindings);

        $this->assertStringContainsString(
            '2026-01-15 04:00:00',
            $joined,
            "OSS::listForBranch MUST bind now('UTC')->subHours(8) UTC literal.\n"
            . "Captured: $joined"
        );

        $this->assertStringNotContainsString(
            '2026-01-15 05:00:00',
            $joined,
            "OSS::listForBranch MUST NOT bind Paris-local now()->subHours(8) literal (pre-heal regression).\n"
            . "Captured: $joined"
        );
    }

    // ---------------------------------------------------------------------
    // KDS-ADV3C-02 P1 — OrderService Sales Report TZ-aware
    // ---------------------------------------------------------------------

    /**
     * RED: fails when OrderService::list compiles `whereDate('order_datetime',
     *      '>=', $from_date)` with Paris-local strings.
     * GREEN: passes when service uses `where('order_datetime', '>=', $fromUtc)`
     *        with UTC-converted boundaries.
     *
     * Input: user picker sends from_date='2026-01-15', to_date='2026-01-15'
     * (Paris-local business day). UTC boundary lower = 2026-01-14 23:00:00.
     */
    public function test_orderService_list_binds_utc_converted_from_date(): void
    {
        $this->pinParisWinterNow();

        $branch = Branch::factory()->create();
        $admin = User::factory()->create(['branch_id' => 0]);
        $admin->assignRole('Admin');
        $this->actingAs($admin, 'sanctum');

        $request = \App\Http\Requests\PaginateRequest::create('/', 'GET', [
            'from_date' => '2026-01-15',
            'to_date' => '2026-01-15',
        ]);
        // Inject empty validation rules / cast through container if needed.
        $request->setContainer(app());

        $queries = $this->captureOrderQueries(function () use ($request) {
            try {
                app(OrderService::class)->list($request);
            } catch (\Throwable $e) {
                // Service may throw if pagination / permission helpers misalign
                // in the test environment. The DB::listen capture is what
                // matters here — even a partial SELECT emits the date binding.
            }
        });

        $allBindings = [];
        foreach ($queries as $q) {
            foreach ($q['bindings'] as $b) {
                $allBindings[] = (string) $b;
            }
        }
        $joined = implode(' | ', $allBindings);

        // Heal binds Paris-day 2026-01-15 startOfDay → UTC = '2026-01-14 23:00:00'.
        // Tomorrow exclusive upper bound = 2026-01-16 startOfDay → UTC = '2026-01-15 23:00:00'.
        $this->assertStringContainsString(
            '2026-01-14 23:00:00',
            $joined,
            "OrderService::list MUST bind UTC-converted from_date '2026-01-14 23:00:00' (Paris-day 2026-01-15 00:00 → UTC).\n"
            . "Captured: $joined"
        );
    }

    // ---------------------------------------------------------------------
    // KDS-ADV3C-03 P1 — ResetStaleDailyQuotaCommand TZ-explicit predicate
    // ---------------------------------------------------------------------

    /**
     * [WH-3 bug_003 2026-05-19] Sentinel inverted to lock the CORRECT
     * contract. Earlier (Wave 3c) iteration of this test enshrined a buggy
     * UTC-shifted binding because Wave 3c heal applied TIMESTAMP-style
     * TZ-conversion to a DATE column. Ultra-review 2026-05-18 caught it.
     *
     * `daily_reset_at` is a DATE column (`$table->date(...)` in migration
     * 2026_04_15_230100). DATE columns have NO timezone semantics in MySQL —
     * they store plain Y-m-d. The predicate
     *   `whereDate('daily_reset_at', '<', $today)`
     * compares Y-m-d strings lexically. Applying `setTimezone('UTC')` to a
     * Paris-local Carbon and then `toDateString()` shifts the literal
     * back one day (Paris 2026-01-15 00:00 → UTC 2026-01-14 23:00 →
     * '2026-01-14'), which makes the cron at 00:05 Paris fail to pick up
     * rows whose `daily_reset_at='2026-01-15'` (yesterday Paris) — exactly
     * the rows the cron is supposed to refresh. Real-world impact:
     * 86-rupture-flagged items stay unavailable one full business day
     * longer than intended.
     *
     * Heal: drop the artificial UTC conversion; use
     *   `Carbon::today(config('app.timezone'))->toDateString()`
     * for BOTH predicate and write (one variable, no inversion possible).
     * Mirrors the canonical pattern already in use in
     * `AvailabilityService::decrementForOrder()` / `toggle()`.
     *
     * Test pin: Paris-winter 2026-01-15 12:00 UTC → Paris-day '2026-01-15'.
     * Predicate must bind the Paris-local Y-m-d, NOT the UTC-shifted
     * '2026-01-14'.
     */
    public function test_reset_stale_quota_command_binds_paris_today(): void
    {
        $this->pinParisWinterNow();

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

        $this->artisan('foodking:availability:reset-stale-quota', ['--dry-run' => true])
            ->assertExitCode(0);

        $queries = array_values(array_filter($captured, function ($q) {
            $n = str_replace(['`', '"'], '', $q['sql']);
            return str_contains($n, 'from item_branch_availability');
        }));

        // --dry-run path issues only the count SELECT, so the predicate
        // date is the only meaningful binding captured. No write-side
        // timestamps interfere with substring matching.
        $allBindings = [];
        foreach ($queries as $q) {
            foreach ($q['bindings'] as $b) {
                $allBindings[] = (string) $b;
            }
        }
        $joined = implode(' | ', $allBindings);

        $this->assertNotEmpty(
            $queries,
            'Expected at least one query against item_branch_availability.'
        );

        // Post-heal: Paris-today = '2026-01-15'. DATE column → Paris-local
        // Y-m-d binding. This is what unblocks yesterday-Paris rows.
        $this->assertStringContainsString(
            '2026-01-15',
            $joined,
            "ResetStaleDailyQuotaCommand MUST bind Paris-local Y-m-d for DATE column (= '2026-01-15').\n"
            . "Captured: $joined"
        );

        // Negative: UTC-shifted Y-m-d MUST NOT appear (bug_003 regression).
        // '2026-01-14' would mean the cron skips rows with daily_reset_at
        // = '2026-01-15' (yesterday Paris on day-N+1) which is the bug.
        $this->assertStringNotContainsString(
            '2026-01-14',
            $joined,
            "ResetStaleDailyQuotaCommand MUST NOT bind UTC-shifted '2026-01-14' (bug_003 regression — skips yesterday-Paris rows).\n"
            . "Captured: $joined"
        );
    }
}
