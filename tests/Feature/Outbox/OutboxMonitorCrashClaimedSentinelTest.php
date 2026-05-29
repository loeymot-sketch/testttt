<?php

namespace Tests\Feature\Outbox;

use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\TestCase;

/**
 * [GOAL-sync-ordertaking 2026-05-29 H3] Crash-claimed orphan alarm sentinel.
 *
 * @source reports/audit/goal-v1-sync-ordertaking-2026-05-29/sync-realtime-verified.json
 *         (MonitorOutboxStaleness blind to crash-claimed rows + OutboxRescueStaleClaimedRowsTest:114
 *          ratifies a FALSE invariant — the row "belongs to RetryFailed" but scopeFailed -> pending
 *          -> whereNull(dispatched_at) excludes a still-claimed row).
 *
 * Semantics (verified against DispatchDomainEventsJob phases):
 *   - Phase 3a success  : dispatched_at SET, last_error CLEARED (null).
 *   - Phase 3b caught err: dispatched_at = NULL (released), last_error SET  -> pending lane.
 *   - Hard crash (no catch) on a RETRY: dispatched_at stays SET, last_error
 *     retains the prior attempt's error, attempts already incremented in Phase 1.
 *
 * The hard-crash-on-retry orphan (dispatched_at SET + last_error SET + attempts>=5)
 * falls through every automatic re-queue lane:
 *   - staleCount     : whereNull(dispatched_at)  -> excluded (claimed).
 *   - retry-failed   : scopeFailed -> pending -> whereNull -> excluded.
 *   - rescue lane B  : attempts < 5 cap          -> excluded.
 * The heal adds a distinct alarm dimension so the operator is PAGED for it.
 *
 * Precision invariant: a legitimately succeeded-after-retries row
 * (dispatched_at SET, last_error NULL, attempts>=5) MUST NOT be counted —
 * Phase 3a clears last_error, so the `last_error IS NOT NULL` predicate gives
 * zero false-positives.
 */
class OutboxMonitorCrashClaimedSentinelTest extends TestCase
{
    use RefreshDatabase;

    public function test_monitor_alarms_on_crash_claimed_orphan(): void
    {
        $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(15),
            'last_error'    => 'Pusher unreachable: connection reset (worker killed mid-broadcast)',
            'attempts'      => 5,
        ], createdMinutesAgo: 20);

        // No classic-stale (null-dispatched) rows -> only the orphan dimension trips.
        $exit = Artisan::call('foodking:outbox:monitor', ['--threshold' => 10, '--stale-after' => 30]);

        $this->assertSame(
            1,
            $exit,
            'Monitor MUST return FAILURE (exit 1) when a crash-claimed orphan exists, so the '
            . 'cron/supervisor pages the operator — the row is unreachable by retry-failed/rescue.'
        );
    }

    public function test_monitor_does_not_false_positive_on_success_after_retries(): void
    {
        // Succeeded on a late attempt: dispatched_at kept, last_error CLEARED by Phase 3a.
        $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(15),
            'last_error'    => null,
            'attempts'      => 6,
        ], createdMinutesAgo: 20);

        $exit = Artisan::call('foodking:outbox:monitor', ['--threshold' => 10, '--stale-after' => 30]);

        $this->assertSame(
            0,
            $exit,
            'Monitor MUST NOT alarm on a succeeded-after-retries row (last_error cleared in Phase 3a) '
            . '— zero false-positives is the precision contract of the crash-claimed dimension.'
        );
    }

    public function test_monitor_ignores_recently_claimed_in_flight_row(): void
    {
        // Claimed 10s ago with an error from a prior attempt — a worker may still be
        // mid-retry (backoff curve ~6.4 min). Younger than stale-after -> not yet an orphan.
        $this->seedDomainEvent([
            'dispatched_at' => now()->subSeconds(10),
            'last_error'    => 'transient',
            'attempts'      => 5,
        ], createdMinutesAgo: 1);

        $exit = Artisan::call('foodking:outbox:monitor', ['--threshold' => 10, '--stale-after' => 30]);

        $this->assertSame(0, $exit, 'A claim younger than stale-after must not trip the orphan alarm.');
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function seedDomainEvent(array $attrs, int $createdMinutesAgo): DomainEvent
    {
        $event = DomainEvent::query()->create(array_merge([
            'event_type'     => 'order.payment.confirmed',
            'aggregate_type' => 'order',
            'aggregate_id'   => (string) random_int(1000, 9999),
            'branch_id'      => 1,
            'payload'        => [],
            'channel'        => json_encode(['private-branch.1']),
            'broadcast_as'   => 'OrderPaidAtCounter',
            'correlation_id' => 'test-h3-'.uniqid(),
            'occurred_at'    => now()->subMinutes($createdMinutesAgo),
        ], $attrs));

        $event->forceFill([
            'created_at' => now()->subMinutes($createdMinutesAgo),
            'updated_at' => now()->subMinutes($createdMinutesAgo),
        ])->save();

        return $event->fresh();
    }
}
