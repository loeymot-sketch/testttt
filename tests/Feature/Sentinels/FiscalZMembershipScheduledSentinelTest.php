<?php

namespace Tests\Feature\Sentinels;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [GOAL-2026-05-29 V3 / NF525 P0 #1 detect-only] Lock the daily NF525 Z-membership
 * reconciliation alarm into the scheduler. The owner chose "detect-only" for the
 * cross-Z-window settlement orphan (a numbered receipt absent from every signed Z);
 * the read-only fiscal:verify-z-membership detector ONLY protects the invariant if
 * it actually runs on a schedule. This sentinel prevents the cron wiring from being
 * silently dropped. It also covers the confirmCounterPayment cross-window exposure
 * (the detector flags an orphan regardless of which path allocated the sequence).
 */
class FiscalZMembershipScheduledSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_z_membership_detector_is_scheduled(): void
    {
        $schedule = app(Schedule::class);

        $commands = collect($schedule->events())
            ->map(fn ($e) => (string) ($e->command ?? ''))
            ->filter();

        $this->assertTrue(
            $commands->contains(fn ($c) => str_contains($c, 'fiscal:verify-z-membership')),
            'fiscal:verify-z-membership MUST be scheduled (NF525 cross-Z-window orphan alarm). '
            . 'Without the cron, the detect-only control never runs.'
        );
    }
}
