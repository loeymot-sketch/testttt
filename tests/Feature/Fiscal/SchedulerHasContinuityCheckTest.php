<?php

namespace Tests\Feature\Fiscal;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [P1-2 REGISTRE_FINAL 2026-07-18 / NF525 GAP-FREE DETECTOR]
 *
 * Lock the daily NF525 numbering-continuity detector into the scheduler.
 *
 * `fiscal:verify-sequence-continuity` is a read-only scan of
 * orders.fiscal_sequence_no (min..max, gap-free + no-duplicate, per branch). It
 * covers the observability blind spot that neither `fiscal:verify-chain` (HMAC
 * integrity) nor `fiscal:verify-z-membership` (Z appartenance) reaches: a
 * hard-delete post-allocation leaves a hole in the fiscal numbering (e.g. branch
 * 1: 2506-2508 missing) that both other lanes walk straight past.
 *
 * The detector already existed and is safe (read-only) — but it was NEVER
 * scheduled, so in prod a real gap would have stayed invisible. This sentinel
 * prevents the cron wiring from being silently dropped and pins its placement
 * (daily 03:35, just after the 03:30 chain monitor).
 */
class SchedulerHasContinuityCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_sequence_continuity_detector_is_scheduled(): void
    {
        $schedule = app(Schedule::class);

        $commands = collect($schedule->events())
            ->map(fn ($e) => (string) ($e->command ?? ''))
            ->filter();

        $this->assertTrue(
            $commands->contains(fn ($c) => str_contains($c, 'fiscal:verify-sequence-continuity')),
            'fiscal:verify-sequence-continuity MUST be scheduled (NF525 numbering-continuity detector). '
            . 'Without the cron, a gap in orders.fiscal_sequence_no stays invisible in prod.'
        );
    }

    public function test_continuity_check_runs_daily_after_the_chain_monitor(): void
    {
        $schedule = app(Schedule::class);

        $event = collect($schedule->events())
            ->first(fn ($e) => str_contains((string) ($e->command ?? ''), 'fiscal:verify-sequence-continuity'));

        $this->assertNotNull(
            $event,
            'fiscal:verify-sequence-continuity event not found in the schedule.'
        );

        // dailyAt('03:35') => cron "35 3 * * *". Placed just AFTER the 03:30
        // chain monitor so the fiscal trio runs in sequence off-peak.
        $this->assertSame(
            '35 3 * * *',
            $event->expression,
            'The continuity check must run daily at 03:35 (right after the 03:30 chain monitor).'
        );

        // Same concurrency guard as its fiscal neighbours.
        $this->assertNotEmpty(
            $event->withoutOverlapping,
            'The continuity check must use withoutOverlapping() like the other fiscal lanes.'
        );
    }
}
