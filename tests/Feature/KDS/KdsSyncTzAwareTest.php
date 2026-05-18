<?php

/**
 * Wave 3 KDS Adversarial — P0 production-breaking sentinel.
 * Authority: reports/audit/critical-focus-2026-05-18/wave-3/adv-3-fiscal-kds-heals.md
 *   (KDS-ADV3-01).
 *
 * KdsSyncService::sync() previously bound `Carbon::today()` / `Carbon::tomorrow()`
 * — which resolve in `app.timezone='Europe/Paris'` — directly to the MySQL
 * query. The mysql connection in config/database.php has NO `timezone` key,
 * so PDO's MySQL session TZ defaults to UTC. The `orders.order_datetime`
 * column is a TIMESTAMP — MySQL coerces between session TZ and UTC storage.
 *
 * Net effect: 1-2h of nightly orders [00:00-02:00 Paris, depending on DST]
 * were silently excluded from the KDS sync delta because the query bound
 * the Paris-local midnight literal as if it were UTC midnight.
 *
 * Heal: convert Paris-local day boundaries to UTC before binding so MySQL
 * receives UTC literals matching the UTC-stored TIMESTAMP column. Surgical
 * at the query level (does NOT touch config/database.php mysql.timezone,
 * which would affect every query in the app).
 *
 * SQLite (test driver) does not have a session-TZ concept, so a behavioral
 * test would pass both pre- and post-heal. This sentinel pins the compiled
 * SQL bindings: it asserts the bound literals are the UTC representation
 * of Paris-local today, NOT the Paris-local midnight string.
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
     * RED: fails when sync binds Carbon::today() (Paris-local midnight)
     *      to MySQL UTC session — bindings show Paris-local midnight.
     * GREEN: passes when sync converts Paris-local day to UTC range —
     *        bindings show the UTC instant of Paris-local midnight.
     *
     * Pinned date is winter (no DST ambiguity): Paris = UTC+1.
     * Paris-today midnight 2026-01-15 00:00:00 = UTC 2026-01-14 23:00:00.
     */
    public function test_sync_binds_utc_converted_paris_day_boundaries(): void
    {
        $branch = Branch::factory()->create();

        // Pin "now" so the computed boundaries are deterministic.
        $now = CarbonImmutable::parse('2026-01-15 12:00:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        // Expected UTC literals corresponding to Paris-local day bounds.
        // Paris is UTC+1 in January (no DST) — confirms winter pinning.
        $appTz = config('app.timezone'); // 'Europe/Paris'
        $expectedStartUtc = Carbon::today($appTz)
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');
        $expectedEndUtc = Carbon::today($appTz)
            ->endOfDay()
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');
        $expectedTomorrowUtc = Carbon::tomorrow($appTz)
            ->setTimezone('UTC')
            ->format('Y-m-d H:i:s');

        // Sanity: confirm test-date math — Paris winter = UTC+1.
        $this->assertSame('2026-01-14 23:00:00', $expectedStartUtc);
        $this->assertSame('2026-01-15 22:59:59', $expectedEndUtc);
        $this->assertSame('2026-01-15 23:00:00', $expectedTomorrowUtc);

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

        // ASSERT-A (negative): Paris-local midnight MUST NOT appear in any
        // binding. This is the bug — pre-heal, `Carbon::today()` resolves
        // to '2026-01-15 00:00:00' (Paris-local) and is bound as-is.
        $this->assertStringNotContainsString(
            '2026-01-15 00:00:00',
            $joinedBindings,
            'KdsSyncService MUST NOT bind Paris-local midnight literal to MySQL. '
            . 'MySQL session TZ is UTC (no timezone key in config/database.php) '
            . 'so the bound literal would shift orders by 1-2h vs the UTC-stored '
            . "TIMESTAMP column. KDS-ADV3-01.\nCaptured bindings: " . $joinedBindings
        );

        // ASSERT-B (positive): the UTC instant corresponding to Paris-today
        // start MUST be bound (whereBetween lower bound).
        $this->assertStringContainsString(
            $expectedStartUtc,
            $joinedBindings,
            'KdsSyncService MUST bind UTC instant of Paris-today start '
            . "(expected '$expectedStartUtc'). KDS-ADV3-01."
        );

        // ASSERT-C (positive): tomorrow's UTC instant (the "<" boundary for
        // advance-order branch) MUST also be bound.
        $this->assertStringContainsString(
            $expectedTomorrowUtc,
            $joinedBindings,
            'KdsSyncService MUST bind UTC instant of Paris-tomorrow start '
            . "for advance-order branch (expected '$expectedTomorrowUtc'). KDS-ADV3-01."
        );
    }
}
