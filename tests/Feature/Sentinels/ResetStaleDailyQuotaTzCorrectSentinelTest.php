<?php

/**
 * [WH-3 bug_003 2026-05-19] Behavioral sentinel — locks the real-world
 * outcome of the `foodking:availability:reset-stale-quota` cron.
 *
 * Authority: reports/audit/wave-h-2026-05-19/WH-3-bug003-RESET-STALE-QUOTA-TZ/
 *
 * Companion to
 *   tests/Feature/Services/SisterServicesTzAwareV2Test::test_reset_stale_quota_command_binds_paris_today
 * which pins the SQL binding. This sentinel pins the BEHAVIOR: a row
 * whose `daily_reset_at` is yesterday-Paris MUST be picked up and reset
 * when the cron runs at 00:05 Paris-day-N+1.
 *
 * Bug class (pre-heal): TIMESTAMP-style TZ conversion applied to a DATE
 * column shifted the predicate literal back one day, so yesterday-Paris
 * rows were skipped → 86-rupture-flagged items stayed unavailable one
 * full business day longer than intended.
 *
 * Pin: 2026-01-15 23:30:00 UTC (deliberately winter, Paris = UTC+1, NO
 * DST ambiguity). At that instant:
 *   - UTC clock         = 2026-01-15 23:30
 *   - Paris clock       = 2026-01-16 00:30   (just past the cron's 00:05 trigger)
 *   - Paris-today       = '2026-01-16'
 *   - Yesterday Paris   = '2026-01-15'       (the row's daily_reset_at)
 *
 * The cron MUST observe `daily_reset_at='2026-01-15' < '2026-01-16'` and
 * update the row to `daily_reset_at='2026-01-16'`, `daily_consumed_qty=0`.
 */

namespace Tests\Feature\Sentinels;

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ResetStaleDailyQuotaTzCorrectSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    public function test_yesterday_paris_row_is_reset_when_cron_runs_just_past_paris_midnight(): void
    {
        // Pin "now" just past Paris midnight on day-N+1, winter (UTC+1, no DST).
        // UTC = 2026-01-15 23:30 → Paris = 2026-01-16 00:30.
        $now = CarbonImmutable::parse('2026-01-15 23:30:00', 'UTC');
        Carbon::setTestNow($now);
        CarbonImmutable::setTestNow($now);

        // Sanity-check the pin produces the expected Paris-day boundary.
        $appTz = config('app.timezone');
        $this->assertSame('Europe/Paris', $appTz, 'app.timezone must be Europe/Paris for this sentinel.');
        $this->assertSame('2026-01-16', Carbon::today($appTz)->toDateString(), 'Paris-today must be 2026-01-16 at the pinned instant.');

        // Seed a row whose daily_reset_at = yesterday Paris ('2026-01-15')
        // with daily_consumed_qty hitting max_daily_qty (86-rupture flag).
        // Pre-heal the cron skipped this row (bound '2026-01-15' < '2026-01-15'
        // is FALSE because of UTC-shift); post-heal the cron picks it up
        // because Paris-today bind = '2026-01-16' > '2026-01-15'.
        DB::table('item_branch_availability')->insert([
            'item_id'            => 9001,
            'branch_id'          => 1,
            'is_available'       => false, // 86-flagged from yesterday's consumption
            'unavailable_reason' => 'max_daily_qty',
            'max_daily_qty'      => 50,
            'daily_consumed_qty' => 50,
            'daily_reset_at'     => '2026-01-15',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        // Seed a control row whose daily_reset_at = Paris-today ('2026-01-16')
        // — same-day, MUST NOT be touched by an idempotent cron.
        DB::table('item_branch_availability')->insert([
            'item_id'            => 9002,
            'branch_id'          => 1,
            'is_available'       => true,
            'unavailable_reason' => null,
            'max_daily_qty'      => 50,
            'daily_consumed_qty' => 12,
            'daily_reset_at'     => '2026-01-16',
            'created_at'         => $now,
            'updated_at'         => $now,
        ]);

        // Execute the cron (real path, not --dry-run).
        $this->artisan('foodking:availability:reset-stale-quota')
            ->assertExitCode(0);

        // Stale row MUST be reset to today's Paris date with consumed=0.
        $stale = DB::table('item_branch_availability')
            ->where('item_id', 9001)
            ->where('branch_id', 1)
            ->first();

        $this->assertNotNull($stale, 'Stale yesterday-Paris row vanished.');
        $this->assertSame(
            '2026-01-16',
            (string) $stale->daily_reset_at,
            'bug_003: Yesterday-Paris row MUST be reset to today Paris (= 2026-01-16). '
            . 'Got: ' . $stale->daily_reset_at . '. Pre-heal regression: cron bound UTC-shifted '
            . "'2026-01-15' as predicate and SKIPPED the row, leaving it 86-flagged for an extra business day."
        );
        $this->assertSame(
            0,
            (int) $stale->daily_consumed_qty,
            'Stale row daily_consumed_qty MUST be reset to 0.'
        );

        // Idempotent guard: same-day row MUST be left alone.
        $sameDay = DB::table('item_branch_availability')
            ->where('item_id', 9002)
            ->where('branch_id', 1)
            ->first();

        $this->assertSame(
            '2026-01-16',
            (string) $sameDay->daily_reset_at,
            'Same-day row daily_reset_at unchanged.'
        );
        $this->assertSame(
            12,
            (int) $sameDay->daily_consumed_qty,
            'Same-day row daily_consumed_qty MUST NOT be reset (idempotent: < predicate is strict).'
        );
    }
}
