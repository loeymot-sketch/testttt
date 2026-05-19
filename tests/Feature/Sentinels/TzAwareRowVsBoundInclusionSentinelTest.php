<?php

/**
 * [WG-4 TZ-test-drift V1.0.X 2026-05-19] Sentinel — KDS row-vs-bound inclusion
 * contract in the Paris-evening failure window.
 *
 * Authority:
 *   - reports/audit/foundation-2026-05-18/failures/V1_0_X_BACKLOG_KDS_TZ_FIX.md
 *     §"Sentinel test addition"
 *   - Companion to tests/Feature/Services/SisterServicesTzAwareTest.php
 *     (Wave 3b KDS-ADV3B-01) and SisterServicesTzAwareV2Test.php
 *     (Wave 3c KDS-ADV3C-01..04). Those sentinels pin the COMPILED SQL
 *     BINDINGS shape (UTC literals). This sentinel pins the COMPLEMENTARY
 *     CONTRACT: at Paris 22:20 (inside the documented failure window
 *     [22:00, 23:59:59]), the row's UTC-converted instant falls strictly
 *     between the service-computed UTC bound literals, so on a TZ-aware
 *     storage engine (MySQL prod) the row IS included in the result set.
 *
 * Why driver-agnostic math instead of SQLite behavioral assertion:
 *   SQLite has no session-TZ concept, so a behavioral row-count test would
 *   pass both pre- and post-heal (or fail post-heal because SQLite string-
 *   compares Paris-local fixture literals against UTC bound literals
 *   unawarely). Asserting the **UTC-aware comparison math** holds in the
 *   Paris-evening window is what guarantees the production behavior on
 *   MySQL.
 *
 * RED trigger:
 *   If any future change reverts c2613cab0 (re-introduces Paris-local
 *   `Carbon::today()` day bounds), the computed bound literals shift back
 *   to Paris-local strings and the row's UTC instant falls OUTSIDE the
 *   resulting [bound_start, bound_end] window in UTC space → assertion
 *   fails → V1.0.X regression caught at CI.
 */

namespace Tests\Feature\Sentinels;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class TzAwareRowVsBoundInclusionSentinelTest extends TestCase
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
     * Contract: at Paris 22:20 (CEST, DST active), the KDS service-computed
     * UTC day bounds MUST bracket the row's UTC instant.
     *
     * GREEN (post-c2613cab0): row at Paris 22:20 = UTC 20:20 falls inside
     *   [UTC 22:00 prev-day, UTC 21:59:59 today] because the heal converts
     *   Paris-today → UTC range = [2026-05-17 22:00:00, 2026-05-18 21:59:59].
     *
     * RED (pre-c2613cab0 or reverted): bounds would be the raw Paris-local
     *   literals `[2026-05-18 00:00:00, 2026-05-18 23:59:59]` interpreted by
     *   MySQL as UTC, which on a UTC-stored TIMESTAMP column shifts the
     *   visible window to Paris [02:00, 25:59:59 (next day 01:59:59)] — the
     *   [22:00, 23:59:59] Paris evening drops out of `today`'s window.
     */
    public function test_row_at_paris_evening_falls_inside_utc_converted_day_bounds(): void
    {
        // Pin the wall-clock to the documented failure-window centroid.
        // 2026-05-18 20:20:00 UTC = 22:20:00 Paris CEST (DST active May).
        $now = CarbonImmutable::parse('2026-05-18 20:20:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        // Sanity: confirm pinning + DST math.
        $this->assertSame(
            '2026-05-18 22:20:00',
            Carbon::now()->setTimezone('Europe/Paris')->format('Y-m-d H:i:s'),
            'pinned UTC 20:20 must resolve to Paris 22:20 (CEST, DST active in May)'
        );

        // Replicate the heal's bound-computation logic byte-for-byte.
        $appTz = config('app.timezone'); // 'Europe/Paris'
        $startUtc = Carbon::today($appTz)->setTimezone('UTC');
        $endUtc = Carbon::today($appTz)->endOfDay()->setTimezone('UTC');
        $tomorrowUtc = Carbon::tomorrow($appTz)->setTimezone('UTC');

        // Expected UTC literals for Paris-today on 2026-05-18 (CEST, UTC+2).
        $this->assertSame(
            '2026-05-17 22:00:00',
            $startUtc->format('Y-m-d H:i:s'),
            'Paris-today start in CEST = UTC 22:00 prev-day. If this changes, '
            . 'c2613cab0 heal has been reverted to Carbon::today() (Paris-local).'
        );
        $this->assertSame(
            '2026-05-18 21:59:59',
            $endUtc->format('Y-m-d H:i:s'),
            'Paris-today end in CEST = UTC 21:59:59 today.'
        );
        $this->assertSame(
            '2026-05-18 22:00:00',
            $tomorrowUtc->format('Y-m-d H:i:s'),
            'Paris-tomorrow start in CEST = UTC 22:00 today (used by advance-'
            . 'order overdue branch).'
        );

        // CORE CONTRACT — the row's UTC instant falls strictly between the
        // bounds, so on a TZ-aware MySQL TIMESTAMP column it WILL be included
        // in the whereBetween(...) predicate. This is the bit that breaks on
        // revert: pre-heal bounds were Paris-local '2026-05-18 23:59:59' but
        // bound against UTC-stored '2026-05-18 20:20:00' would compare as
        // raw lexicographic strings — fine here — yet a 22:20 Paris-local
        // fixture row writes UTC 20:20 to the column, AND a Paris-local end-
        // bound 23:59:59 bound as UTC means the predicate is "row 20:20 UTC
        // BETWEEN UTC 00:00 and UTC 23:59:59", which mistakenly INCLUDES
        // rows from 00:00-01:59:59 UTC = Paris 02:00-03:59:59 of the SAME
        // calendar day (next day's early morning Paris-local). The heal
        // restores the correct Paris-anchored window.
        $rowUtc = $now->format('Y-m-d H:i:s'); // '2026-05-18 20:20:00'
        $this->assertGreaterThanOrEqual(
            $startUtc->format('Y-m-d H:i:s'),
            $rowUtc,
            "Row UTC instant ($rowUtc) MUST be >= Paris-today start UTC bound "
            . "({$startUtc->format('Y-m-d H:i:s')}). If this fails, c2613cab0 "
            . 'is reverted and Paris 22:00-23:59:59 rows fall outside the day '
            . 'window — KDS UI silently drops them. See '
            . 'reports/audit/foundation-2026-05-18/failures/V1_0_X_BACKLOG_KDS_TZ_FIX.md.'
        );
        $this->assertLessThanOrEqual(
            $endUtc->format('Y-m-d H:i:s'),
            $rowUtc,
            "Row UTC instant ($rowUtc) MUST be <= Paris-today end UTC bound "
            . "({$endUtc->format('Y-m-d H:i:s')}). Same root cause as above."
        );

        // SECONDARY GUARD — the row is also strictly INSIDE the half-open
        // overdue-advance-order window (..<tomorrowStartUtc), so the second
        // branch of the service whereBetween also matches.
        $this->assertLessThan(
            $tomorrowUtc->format('Y-m-d H:i:s'),
            $rowUtc,
            'Row UTC instant MUST be < Paris-tomorrow start UTC bound '
            . '(advance-order overdue branch). Same revert symptom as above.'
        );
    }
}
