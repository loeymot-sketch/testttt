<?php

namespace Tests\Feature\Console;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * [GOAL-I2-HEAL-04 2026-05-24] Sentinel: sanctum:prune-expired scheduling.
 *
 * Phase I.7 R6 P2 finding: the Laravel Sanctum vendor command
 * `sanctum:prune-expired` (vendor/laravel/sanctum/src/Console/Commands/
 * PruneExpired.php) was NEVER registered in Kernel.php::schedule(). The
 * compound risk: relogin-revoke only touches the *active* token name, so
 * expired rows of all OTHER token names accumulate forever. Over the
 * NF525 6-year retention horizon = unbounded storage bloat on
 * `personal_access_tokens`.
 *
 * This sentinel pins the operational contract:
 *
 *  (1) The vendor command is registered in Artisan (auto-loaded by the
 *      SanctumServiceProvider; the test ensures the provider hasn't been
 *      disabled or the command renamed upstream).
 *  (2) The scheduler lane fires daily at 04:30 Europe/Paris, AFTER all
 *      other daily lanes (backup 03:00, archive 02:00, chain monitor
 *      03:30, parked-orders 03:15, outbox-prune 04:00, webhook-prune
 *      04:15 — see CRONTAB_PROD.md). This staggering keeps mutex windows
 *      independent so a long-running prune cannot collide with anything.
 *  (3) Concurrency defenses are present: `onOneServer` (cross-host
 *      serialization) + `withoutOverlapping` (single-host re-entry
 *      guard) + `runInBackground` (does not block schedule:run).
 *  (4) Output is captured to a dedicated log file so ops can audit the
 *      prune cadence without polluting the shared schedule.log.
 *
 * @group sanctum
 * @group cron
 * @group nf525
 */
class SanctumPruneExpiredScheduledTest extends TestCase
{
    /**
     * Locate the registered scheduler event for sanctum:prune-expired.
     *
     * Returns null if no matching event exists — callers assert non-null
     * with a contract-violation message.
     */
    private function findSanctumPruneEvent(): ?\Illuminate\Console\Scheduling\Event
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        foreach ($schedule->events() as $event) {
            if (str_contains((string) $event->command, 'sanctum:prune-expired')) {
                return $event;
            }
        }

        return null;
    }

    public function test_vendor_command_is_registered_in_artisan(): void
    {
        $signatures = collect(Artisan::all())->keys()->all();

        $this->assertContains(
            'sanctum:prune-expired',
            $signatures,
            'sanctum:prune-expired must be registered in Artisan command list. '
            . 'Vendor source: vendor/laravel/sanctum/src/Console/Commands/PruneExpired.php. '
            . 'If this fails, SanctumServiceProvider may be disabled or the command renamed upstream.'
        );
    }

    public function test_kernel_registers_sanctum_prune_expired_daily_at_04_30(): void
    {
        $event = $this->findSanctumPruneEvent();

        $this->assertNotNull(
            $event,
            'sanctum:prune-expired must be scheduled in app/Console/Kernel.php (GOAL-I2-HEAL-04). '
            . 'Without this lane, expired personal_access_tokens rows accumulate forever for every '
            . 'token name other than the active relogin-revoked one. Over the NF525 6-year horizon '
            . 'this becomes unbounded storage bloat. See Phase I.7 R6 P2 finding.'
        );

        $this->assertSame(
            '30 4 * * *',
            (string) $event->expression,
            "Lane must run daily at 04:30 (after backup 03:00, archive 02:00, chain monitor 03:30, "
            . "parked-orders 03:15, outbox-prune 04:00, webhook-prune 04:15). Got: '{$event->expression}'."
        );
    }

    public function test_lane_uses_europe_paris_timezone(): void
    {
        $event = $this->findSanctumPruneEvent();
        $this->assertNotNull($event, 'Pre-condition: sanctum:prune-expired lane must exist.');

        $this->assertSame(
            'Europe/Paris',
            (string) $event->timezone,
            'Timezone must be Europe/Paris so the 04:30 slot lands at the operational off-peak '
            . 'on the production host (Le Cayenne V1 LOCAL, France) regardless of the server clock.'
        );
    }

    public function test_lane_has_concurrency_defenses(): void
    {
        $event = $this->findSanctumPruneEvent();
        $this->assertNotNull($event, 'Pre-condition: sanctum:prune-expired lane must exist.');

        $this->assertNotEmpty(
            $event->mutex,
            'withoutOverlapping required: a long-running prune (large `personal_access_tokens` '
            . 'after a long uptime) must not race a same-minute re-fire.'
        );

        $this->assertTrue(
            (bool) $event->onOneServer,
            'onOneServer required: V2 SaaS horizontal scale must NEVER double-prune the same row set '
            . '(the vendor DELETE is idempotent but the lock contention would still be wasteful).'
        );

        $this->assertTrue(
            (bool) $event->runInBackground,
            'runInBackground required: a slow prune must not block the schedule:run tick (next minute '
            . 'must still fire on time — every-minute lanes #2/#3/#13 depend on this).'
        );
    }

    public function test_lane_captures_output_to_dedicated_log(): void
    {
        $event = $this->findSanctumPruneEvent();
        $this->assertNotNull($event, 'Pre-condition: sanctum:prune-expired lane must exist.');

        $this->assertNotNull($event->output, 'Lane must capture output (appendOutputTo).');

        $this->assertStringContainsString(
            'sanctum-prune-expired.log',
            (string) $event->output,
            'Output must target a dedicated log file (sanctum-prune-expired.log) so ops can audit '
            . 'the prune cadence without polluting /var/log/lecayenne/schedule.log. Got: '
            . (string) $event->output
        );
    }

    public function test_command_carries_hours_24_retention_grace_period(): void
    {
        $event = $this->findSanctumPruneEvent();
        $this->assertNotNull($event, 'Pre-condition: sanctum:prune-expired lane must exist.');

        $this->assertStringContainsString(
            '--hours=24',
            (string) $event->command,
            'Lane MUST pass --hours=24 retention grace period. This keeps tokens for 24h after '
            . 'expiry before delete, providing a forensic safety net (e.g. ops needs to debug a '
            . 'just-expired token without resurrecting it). The vendor command default is 24 but '
            . 'we pin it explicitly to make the intent visible at the scheduler layer. '
            . 'Got command: ' . (string) $event->command
        );
    }

    public function test_exactly_one_sanctum_prune_lane_is_registered(): void
    {
        /** @var Schedule $schedule */
        $schedule = $this->app->make(Schedule::class);

        $count = collect($schedule->events())
            ->filter(fn ($event) => str_contains((string) $event->command, 'sanctum:prune-expired'))
            ->count();

        $this->assertSame(
            1,
            $count,
            "Exactly 1 sanctum:prune-expired lane expected (idempotency / drift guard). Found: {$count}."
        );
    }
}
