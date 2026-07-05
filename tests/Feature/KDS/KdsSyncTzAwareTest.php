<?php

/**
 * Wave T R5 KDS Adversarial — P0 production-breaking sentinel (CORRECTED).
 * Authority: Wave T R5 mission 2026-05-20 (KDS-T-R5-02) — empirical capture
 *   showed 1 row served out of 11 DB rows at 23:51 Paris-local because the
 *   prior heal (148dbebce, Wave 2b/3b) over-corrected with `->setTimezone('UTC')`.
 *
 * ORIGINAL Wave 3 heal assumed MySQL session_tz = UTC. EMPIRICALLY FALSE on
 * this deployment: `SELECT @@session.time_zone` returns 'SYSTEM' (Paris-local),
 * because config/database.php connections.mysql.timezone is NULL and PDO
 * inherits the OS TZ (Europe/Paris).
 *
 * Under session_tz=Paris, the buggy heal bound UTC strings (e.g.
 * "2026-05-19 22:00:00") which MySQL re-interpreted as Paris-local datetimes,
 * shifting the active window backward by 2h and silently dropping the last
 * ~2h of every Paris day (22h–minuit) from the KDS sync feed.
 *
 * CORRECT BEHAVIOR (this sentinel pins): bind Paris-local Carbon bounds
 * directly. MySQL session_tz=Paris interprets them at face value, matching
 * the semantic intent "all of TODAY in Paris" and aligning with how
 * orders.order_datetime stored TIMESTAMPs are displayed/compared.
 *
 * INVARIANT WARNING: this sentinel pins behavior under session_tz=OS-local.
 * If config/database.php gains `connections.mysql.timezone => '+00:00'`,
 * BOTH services AND this sentinel must be re-evaluated.
 *
 * SQLite (test driver) has no session-TZ concept, so a behavioral test
 * would pass either way. This sentinel pins the compiled SQL bindings.
 */

namespace Tests\Feature\Kds;

use App\Enums\Ask;
use App\Enums\OrderStatus;
use App\Models\Branch;
use App\Models\Order;
use App\Services\KdsSyncService;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class KdsSyncTzAwareTest extends TestCase
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
        // Carbon::setTestNow() leaks across tests if not cleared.
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /**
     * RED (post-Wave T R5): would have failed pre-heal — bindings would have
     *      shown UTC-converted Paris-day-bounds (e.g. '2026-01-14 23:00:00').
     * GREEN (post-Wave T R5): bindings show Paris-local Carbon bounds directly,
     *       matching the MySQL session_tz=Paris production deployment.
     *
     * Pinned date is winter (no DST ambiguity): Paris = UTC+1.
     */
    public function test_sync_binds_paris_local_day_boundaries(): void
    {
        $branch = Branch::factory()->create();

        // Pin "now" so the computed boundaries are deterministic.
        $now = CarbonImmutable::parse('2026-01-15 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        // Expected Paris-local literals for the window bounds (matching the heal).
        // [MINUIT-STRADDLE 2026-07-04] Borne basse = fenêtre GLISSANTE now-8h (Paris-
        // local), plus le jour civil : 12:00 UTC = 13:00 Paris (hiver) → floor 05:00.
        // L'intention TZ du pin est inchangée (le bug UTC donnerait 04:00).
        $appTz = config('app.timezone'); // 'Europe/Paris'
        $expectedStart = now($appTz)->subHours((int) config('oss.stale_window_hours', 8))->format('Y-m-d H:i:s');
        $expectedEnd = Carbon::today($appTz)->endOfDay()->format('Y-m-d H:i:s');
        $expectedTomorrow = Carbon::tomorrow($appTz)->format('Y-m-d H:i:s');

        // Sanity: confirm test-date math (Paris-local, NOT UTC).
        $this->assertSame('2026-01-15 05:00:00', $expectedStart);
        $this->assertSame('2026-01-15 23:59:59', $expectedEnd);
        $this->assertSame('2026-01-16 00:00:00', $expectedTomorrow);

        // Seed a few active orders so sync emits the main SELECT (Eloquent
        // skips the query if it can short-circuit; with seeded rows we
        // guarantee the captured bindings include the date predicates).
        for ($i = 0; $i < 3; $i++) {
            $order = Order::factory()->create([
                'branch_id' => $branch->id,
                'status' => OrderStatus::ACCEPT,
                'order_datetime' => $now->subHour(),
                'is_advance_order' => Ask::NO,
            ]);
            $order->forceFill(['updated_at' => $now])->saveQuietly();
        }

        // Capture compiled SQL + bindings for every query.
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

        Cache::flush();
        $service = app(KdsSyncService::class);
        $since = new DateTimeImmutable('-10 minutes');
        $service->sync($branch->id, $since, true);

        $this->assertNotEmpty($captured, 'DB::listen must capture at least one query.');

        // Find the orders SELECT(s) filtering by order_datetime.
        $normalize = static fn (string $sql): string => str_replace(['`', '"'], '', $sql);
        $orderQueries = array_values(array_filter(
            $captured,
            function (array $q) use ($normalize): bool {
                $n = $normalize($q['sql']);
                return str_contains($n, 'from orders')
                    && str_contains($n, 'order_datetime');
            }
        ));
        if (empty($orderQueries)) {
            $this->fail(
                'Expected at least one SELECT from orders filtering by '
                . 'order_datetime. Captured ' . count($captured) . ' queries.'
            );
        }

        // Flatten bindings across all order queries for assertions.
        $allBindings = [];
        foreach ($orderQueries as $q) {
            foreach ($q['bindings'] as $b) {
                $allBindings[] = (string) $b;
            }
        }
        $joinedBindings = implode(' | ', $allBindings);

        // ASSERT-A (negative): UTC-converted Paris-day boundary MUST NOT
        // appear. This is the Wave 2b/3b bug — converting Paris-local
        // bounds to UTC strings before binding caused MySQL session_tz=Paris
        // to re-interpret them, shifting the window backward by 2h.
        $this->assertStringNotContainsString(
            '2026-01-14 23:00:00',
            $joinedBindings,
            'KdsSyncService MUST NOT bind UTC-converted Paris-day-start to '
            . 'MySQL. Empirically session_tz=SYSTEM (Paris) on the deployment, '
            . 'so UTC literals get re-interpreted as Paris-local, shifting '
            . "the active window backward by 2h. Wave T R5 KDS-T-R5-02.\n"
            . 'Captured bindings: ' . $joinedBindings
        );

        // ASSERT-B (positive): Paris-local day-start MUST be bound.
        $this->assertStringContainsString(
            $expectedStart,
            $joinedBindings,
            'KdsSyncService MUST bind Paris-local today-start '
            . "(expected '$expectedStart'). Wave T R5 KDS-T-R5-02."
        );

        // ASSERT-C (positive): Paris-local tomorrow-start MUST be bound
        // (the "<" boundary for the advance-order branch).
        $this->assertStringContainsString(
            $expectedTomorrow,
            $joinedBindings,
            'KdsSyncService MUST bind Paris-local tomorrow-start '
            . "for advance-order branch (expected '$expectedTomorrow'). "
            . 'Wave T R5 KDS-T-R5-02.'
        );
    }
}
