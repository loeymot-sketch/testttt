<?php

namespace Tests\Feature\Outbox;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * [W-REM R1 T-R1.1a — 2026-06-12] QUARANTINE BY DEFAULT for expired outbox rows.
 *
 * Production context: 8 405 pending domain_events older than 24h accumulated
 * in the operating DB. Replaying them (the pre-fix rescue lane A behaviour:
 * `pending() + created_at < now()-2min + attempts<5`, NO upper age bound)
 * would re-broadcast thousands of stale OrderCreated/StatusChanged events —
 * KDS boards and notification listeners would replay a day+ of history.
 *
 * Contract under test:
 *   - pending rows older than the 24h cutoff are marked
 *     `dispatched_at = now()` + `last_error = 'expired:quarantined'`
 *     WITHOUT any DispatchDomainEventsJob being queued (no broadcast,
 *     zero side-effects).
 *   - the quarantine runs BY DEFAULT on the scheduled lane
 *     (`foodking:outbox:rescue`, every minute) and on the manual drain
 *     (`foodking:outbox:drain`).
 *   - RED-SHARED-01 hole: a row with (pending, attempts=5, age>24h) used to
 *     fall through EVERY lane (rescue caps attempts<5; retry-failed cron is
 *     scoped --since=24h) — the quarantine now covers it.
 *   - quarantined rows must NOT trip the MonitorOutboxStaleness
 *     crash-claimed alarm (they have dispatched_at SET + last_error SET,
 *     which pre-fix matched the orphan predicate forever).
 */
class OutboxQuarantineExpiredTest extends TestCase
{
    use RefreshDatabase;

    public const QUARANTINE_ERROR = 'expired:quarantined';

    public function test_rescue_quarantines_expired_pending_event_without_broadcast(): void
    {
        Bus::fake();

        $expired = $this->seedDomainEvent([
            'dispatched_at' => null,
            'attempts' => 0,
        ], createdHoursAgo: 25);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);

        // The expired event must NOT be re-broadcast (no job queued at all).
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);

        $expired->refresh();
        $this->assertNotNull(
            $expired->dispatched_at,
            'Expired pending row must be claimed-closed (dispatched_at=now) so no lane ever replays it.'
        );
        $this->assertSame(
            self::QUARANTINE_ERROR,
            (string) $expired->last_error,
            "Quarantined row must carry last_error='expired:quarantined' as forensic marker."
        );
    }

    public function test_quarantine_covers_red_shared_01_hole_attempts_capped_expired_row(): void
    {
        Bus::fake();

        // RED-SHARED-01: pending + attempts=5 + age>24h falls through
        // rescue (attempts<5 cap) AND retry-failed (--since=24h window).
        $hole = $this->seedDomainEvent([
            'dispatched_at' => null,
            'attempts' => 5,
            'last_error' => 'Pusher timeout',
        ], createdHoursAgo: 30);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);

        $hole->refresh();
        $this->assertNotNull($hole->dispatched_at, 'RED-SHARED-01 hole row must be quarantined, not left pending forever.');
        $this->assertSame(self::QUARANTINE_ERROR, (string) $hole->last_error);
    }

    public function test_quarantined_rows_do_not_trip_staleness_or_crash_claimed_monitor(): void
    {
        Bus::fake();

        // attempts>=5 + dispatched_at SET + last_error SET would match the
        // monitor's crash-claimed orphan predicate FOREVER without the
        // quarantine marker exclusion → permanent false page.
        $this->seedDomainEvent([
            'dispatched_at' => null,
            'attempts' => 5,
            'last_error' => 'Pusher timeout',
        ], createdHoursAgo: 30);

        Artisan::call('foodking:outbox:rescue');

        // Make the quarantined claim "old" so the monitor's 10-min orphan
        // age-gate would apply if the exclusion were missing.
        DomainEvent::query()->update(['dispatched_at' => now()->subMinutes(20)]);

        $exit = Artisan::call('foodking:outbox:monitor', ['--threshold' => 10, '--stale-after' => 30]);

        $this->assertSame(
            0,
            $exit,
            'Quarantined rows must NOT page the operator as crash-claimed orphans (terminal, intentional state).'
        );
    }

    public function test_drain_quarantines_expired_and_requeues_only_fresh(): void
    {
        Bus::fake();

        $expired = $this->seedDomainEvent([
            'dispatched_at' => null,
            'attempts' => 1,
        ], createdHoursAgo: 26);

        $fresh = $this->seedDomainEvent([
            'dispatched_at' => null,
            'attempts' => 0,
        ], createdHoursAgo: 0, createdMinutesAgo: 5);

        $exit = Artisan::call('foodking:outbox:drain');

        $this->assertSame(0, $exit);

        // Only the FRESH row is re-queued.
        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($fresh) {
            return $job->domainEventId === $fresh->id;
        });
        Bus::assertNotDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($expired) {
            return $job->domainEventId === $expired->id;
        });

        $expired->refresh();
        $this->assertSame(self::QUARANTINE_ERROR, (string) $expired->last_error);
        $this->assertNotNull($expired->dispatched_at);
    }

    public function test_drain_quarantine_only_pushes_zero_jobs(): void
    {
        Bus::fake();

        $this->seedDomainEvent(['dispatched_at' => null, 'attempts' => 0], createdHoursAgo: 25);
        $this->seedDomainEvent(['dispatched_at' => null, 'attempts' => 0], createdHoursAgo: 0, createdMinutesAgo: 5);

        $exit = Artisan::call('foodking:outbox:drain', ['--quarantine-only' => true]);

        $this->assertSame(0, $exit);

        // Quarantine-only mode = ZERO queue pushes, even for fresh rows.
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);

        $this->assertSame(
            1,
            DomainEvent::query()->whereNull('dispatched_at')->count(),
            'Fresh row stays pending (untouched); only the expired row is quarantined.'
        );
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function seedDomainEvent(array $attrs, int $createdHoursAgo, int $createdMinutesAgo = 0): DomainEvent
    {
        $createdAt = now()->subHours($createdHoursAgo)->subMinutes($createdMinutesAgo);

        $event = DomainEvent::query()->create(array_merge([
            'event_type' => 'order.created',
            'aggregate_type' => 'order',
            'aggregate_id' => (string) random_int(1000, 9999),
            'branch_id' => 1,
            'payload' => [],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'test-quarantine-'.uniqid(),
            'occurred_at' => $createdAt,
            'last_error' => null,
        ], $attrs));

        $event->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $event->fresh();
    }
}
