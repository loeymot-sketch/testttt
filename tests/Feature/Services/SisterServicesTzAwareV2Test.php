<?php

/**
 * Wave T R5 KDS+OSS Adversarial — corrected sentinel (GOAL-G2-HEAL-04 2026-05-23).
 * Authority: commit 27d95e066 (Wave T R5, 2026-05-20) + commit 4905138fa
 * (Wave 3c, 2026-05-18, superseded).
 *
 * HISTORY (read together with class header)
 * - Wave 3c heal (4905138fa) applied UTC-conversion to Paris-local Carbon
 *   boundaries ASSUMING MySQL session_tz=UTC, and this sentinel suite was
 *   written to PIN that assumption (assert UTC literal, forbid Paris-local
 *   literal).
 * - Wave T R5 (27d95e066) caught the assumption empirically: production
 *   `SELECT @@session.time_zone` returns 'SYSTEM' (= Europe/Paris) because
 *   config/database.php connections.mysql.timezone is NULL and PDO inherits
 *   the OS local TZ. Under session_tz=Paris, UTC bind literals are re-
 *   interpreted as Paris-local → window shift backward by 1-2h → last
 *   ~2h of every Paris day silently dropped.
 * - Wave T R5 corrected KDS/OSS service queries (and corresponding
 *   sentinels in SisterServicesTzAwareTest) to bind Paris-local Carbon
 *   directly. GOAL-G2-HEAL-04 extends the same correction to:
 *     - DashboardService (every admin widget)               (was KDS-ADV3C-01)
 *     - OrderService::list + salesReportOverview            (was KDS-ADV3C-02)
 *     - OrderStatusScreenOrderService now()->subHours()     (was KDS-ADV3C-04)
 *   ResetStaleDailyQuotaCommand (was KDS-ADV3C-03) was already fixed by
 *   885c625383 (cron-bug003, 2026-05-19); AvailabilityService DATE column
 *   path was never UTC-shifted.
 *
 * CONTRACT (post G2-HEAL-04)
 * Bound Carbon literals MUST be Paris-local (matching MySQL session_tz=
 * Paris face-value). UTC-converted literals MUST NOT appear (Wave 3c
 * regression).
 *
 * SQLite (test driver) has no session-TZ concept, so a row-count behavioral
 * test would pass either way. These sentinels pin the compiled SQL
 * bindings.
 *
 * INVARIANT DEPENDENCY (same as service inline comments):
 * heal assumes session_tz=OS-local (Paris). Future
 * config/database.php connections.mysql.timezone => '+00:00' MUST
 * re-evaluate BOTH the services AND this sentinel.
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
     * Returns the expected Paris-local bound literals (Wave T R5 contract,
     * GOAL-G2-HEAL-04 extension).
     *
     * @return array{0:string,1:string,2:string,3:CarbonImmutable}
     */
    private function pinParisWinterNow(): array
    {
        $now = CarbonImmutable::parse('2026-01-15 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        $appTz = config('app.timezone'); // 'Europe/Paris'
        $expectedStart = Carbon::today($appTz)->format('Y-m-d H:i:s');
        $expectedEnd = Carbon::today($appTz)->endOfDay()->format('Y-m-d H:i:s');
        $expectedTomorrow = Carbon::tomorrow($appTz)->format('Y-m-d H:i:s');

        // Sanity: Paris-local midnight literals (NOT UTC-shifted).
        $this->assertSame('2026-01-15 00:00:00', $expectedStart);
        $this->assertSame('2026-01-15 23:59:59', $expectedEnd);
        $this->assertSame('2026-01-16 00:00:00', $expectedTomorrow);

        return [$expectedStart, $expectedEnd, $expectedTomorrow, $now];
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
     * Assert at least one binding contains the Paris-local literal AND no
     * binding carries the UTC-converted literal (GOAL-G2-HEAL-04 inversion
     * of the prior Wave 3c contract).
     */
    private function assertBindingsContainParisNotUtcConverted(
        array $queries,
        string $expectedParisLiteral,
        string $forbiddenUtcLiteral,
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

        // ASSERT (negative): UTC-converted Paris-day-start MUST NOT appear.
        // That was the Wave 3c regression — empirical session_tz=Paris (NOT
        // UTC as the Wave 3c commit asserted) means UTC literals get re-
        // interpreted as Paris-local, shifting the window backward by 1-2h.
        $this->assertStringNotContainsString(
            $forbiddenUtcLiteral,
            $joined,
            "$service MUST NOT bind UTC-converted literal '$forbiddenUtcLiteral'. "
            . 'Empirical session_tz=SYSTEM (Paris-local) on this deployment, '
            . 'so UTC literals get re-interpreted as Paris-local, shifting '
            . "the active window. Wave T R5 / GOAL-G2-HEAL-04.\n"
            . "Captured: $joined"
        );

        // ASSERT (positive): Paris-local literal MUST be bound directly.
        $this->assertStringContainsString(
            $expectedParisLiteral,
            $joined,
            "$service MUST bind Paris-local literal '$expectedParisLiteral'. "
            . "Wave T R5 / GOAL-G2-HEAL-04.\n"
            . "Captured: $joined"
        );
    }

    // ---------------------------------------------------------------------
    // GOAL-G2-HEAL-04 — DashboardService Paris-local bound (was KDS-ADV3C-01)
    // ---------------------------------------------------------------------

    /**
     * GREEN: post-G2-HEAL-04, DashboardService::orderStatistics binds
     *        Paris-local today-start ('2026-01-15 00:00:00') so MySQL
     *        session_tz=Paris interprets it at face value. Pre-heal regression
     *        (UTC '2026-01-14 23:00:00') MUST NOT appear.
     */
    public function test_dashboard_orderStatistics_binds_paris_today(): void
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

        $this->assertBindingsContainParisNotUtcConverted(
            $queries,
            '2026-01-15 00:00:00',        // expected Paris-local today-start
            '2026-01-14 23:00:00',        // forbidden UTC-shifted boundary
            'DashboardService::orderStatistics'
        );
    }

    /**
     * GREEN: post-G2-HEAL-04, DashboardService::realtimeReport binds
     *        Paris-local today-start. Pre-heal UTC-shifted bound MUST NOT
     *        appear.
     */
    public function test_dashboard_realtimeReport_binds_paris_today(): void
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

        $this->assertBindingsContainParisNotUtcConverted(
            $queries,
            '2026-01-15 00:00:00',
            '2026-01-14 23:00:00',
            'DashboardService::realtimeReport'
        );
    }

    // ---------------------------------------------------------------------
    // GOAL-G2-HEAL-04 — OSS stale-prune `now()->subHours()` Paris-local bind
    // (was KDS-ADV3C-04, inverted)
    // ---------------------------------------------------------------------

    /**
     * GREEN: post-G2-HEAL-04, OSS::list binds now(Paris)->subHours(8) so
     *        MySQL session_tz=Paris interprets the bound literal at face
     *        value (matching the surrounding day-bound queries which are
     *        Paris-local since Wave T R5).
     *
     * Paris-winter now = 2026-01-15 12:00 UTC = 2026-01-15 13:00 Paris.
     * Post-G2-HEAL-04: now(Paris) → 13:00 - 8h = 05:00 Paris ('2026-01-15 05:00:00').
     * Pre-heal regression: now('UTC') → 12:00 - 8h = 04:00 UTC ('2026-01-15 04:00:00').
     */
    public function test_oss_list_stale_prune_binds_paris_now(): void
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

        // Post-heal: now(Paris) = 13:00 Paris - 8h = '2026-01-15 05:00:00'.
        $this->assertStringContainsString(
            '2026-01-15 05:00:00',
            $joined,
            "OSS::list MUST bind now(Paris)->subHours(8) = '2026-01-15 05:00:00' "
            . "(Wave T R5 / GOAL-G2-HEAL-04 contract).\n"
            . "Captured: $joined"
        );

        // Negative: UTC-shifted would have been 2026-01-15 04:00:00 (12:00 UTC - 8h).
        $this->assertStringNotContainsString(
            '2026-01-15 04:00:00',
            $joined,
            "OSS::list MUST NOT bind UTC-shifted now('UTC')->subHours(8) = '2026-01-15 04:00:00' "
            . "(Wave 3c regression — re-interpreted as Paris-local under session_tz=Paris).\n"
            . "Captured: $joined"
        );
    }

    /**
     * Mirror of previous test for the listForBranch() public path.
     */
    public function test_oss_listForBranch_stale_prune_binds_paris_now(): void
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
            '2026-01-15 05:00:00',
            $joined,
            "OSS::listForBranch MUST bind now(Paris)->subHours(8) Paris-local literal "
            . "(Wave T R5 / GOAL-G2-HEAL-04 contract).\n"
            . "Captured: $joined"
        );

        $this->assertStringNotContainsString(
            '2026-01-15 04:00:00',
            $joined,
            "OSS::listForBranch MUST NOT bind UTC-shifted literal (Wave 3c regression).\n"
            . "Captured: $joined"
        );
    }

    // ---------------------------------------------------------------------
    // GOAL-G2-HEAL-04 — OrderService Sales Report Paris-local from_date
    // (was KDS-ADV3C-02, inverted)
    // ---------------------------------------------------------------------

    /**
     * GREEN: post-G2-HEAL-04, OrderService::list binds Paris-local from_date
     *        ('2026-01-15 00:00:00') so MySQL session_tz=Paris interprets it
     *        at face value. Pre-heal UTC-shifted bound ('2026-01-14 23:00:00')
     *        MUST NOT appear (Wave 3c regression).
     *
     * Input: user picker sends from_date='2026-01-15', to_date='2026-01-15'
     * (Paris-local business day). Lower bound binds Paris-day startOfDay
     * directly.
     */
    public function test_orderService_list_binds_paris_from_date(): void
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

        // Post-heal: Paris-day '2026-01-15' startOfDay = '2026-01-15 00:00:00'
        // bound directly. Tomorrow exclusive upper bound = '2026-01-16 00:00:00'.
        $this->assertStringContainsString(
            '2026-01-15 00:00:00',
            $joined,
            "OrderService::list MUST bind Paris-local from_date '2026-01-15 00:00:00' "
            . "(Wave T R5 / GOAL-G2-HEAL-04 contract).\n"
            . "Captured: $joined"
        );

        // Negative: UTC-shifted '2026-01-14 23:00:00' MUST NOT appear
        // (Wave 3c regression that this heal corrects).
        $this->assertStringNotContainsString(
            '2026-01-14 23:00:00',
            $joined,
            "OrderService::list MUST NOT bind UTC-shifted '2026-01-14 23:00:00' "
            . "(Wave 3c regression — re-interpreted as Paris-local under session_tz=Paris).\n"
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
