<?php

namespace Tests\Feature\Outbox;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * Heal B.4 — OutboxRescueCommand stale-claimed rows widening.
 *
 * Source: reports/audit/v1-sync-deep-audit-2026-05-19/HEAL-PLAN-B-outbox-sync.md §Heal-4
 * Audit anchor: RED-Z3 §B-1 P0 silent-loss when DispatchDomainEventsJob worker
 *               crashes between Phase 1 claim (dispatched_at=now(), attempts++)
 *               and Phase 3b release (dispatched_at=null on Throwable).
 *
 * Before the heal: rescue filtered `pending() + attempts<5`. A row whose worker
 * crashed mid-broadcast keeps `dispatched_at != null` forever — never re-queued
 * by rescue, never paged by MonitorOutboxStaleness (same predicate), never
 * reclaimed by RetryFailed (scope `failed(5)` requires attempts>=5 + 1h cadence).
 *
 * After the heal: two-lane rescue picks crash-claimed rows (lane B) once they
 * exceed a 10-minute threshold (> worst Pusher/Soketi hang + worker backoff
 * curve 6.4 min). Threshold + attempts<5 cap bound the recovery window and
 * prevent collision with legitimately retrying workers.
 *
 * lockForUpdate serialization at DispatchDomainEventsJob:65-86 ensures only one
 * worker holds Phase 1 entry — the rescue's claim release + re-dispatch is
 * serialized against any straggler worker's claim attempt.
 */
class OutboxRescueStaleClaimedRowsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Lane A (existing pending behaviour) — null-dispatched row past the
     * `stale(2)` minute gate gets rescued.
     */
    public function test_rescue_picks_classic_pending_stale_row(): void
    {
        Bus::fake();

        $event = $this->seedDomainEvent([
            'dispatched_at' => null,
            'attempts' => 0,
        ], createdMinutesAgo: 3);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($event) {
            return $job->domainEventId === $event->id;
        });
    }

    /**
     * Lane B (NEW heal B.4) — crash-claimed row past the 10-minute threshold
     * gets re-queued AND the stuck claim gets released so the new job's
     * Phase 1 guard at DispatchDomainEventsJob:75-77 does not silent-skip.
     */
    public function test_rescue_picks_crash_claimed_row_past_threshold(): void
    {
        Bus::fake();

        $event = $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(15),
            'attempts' => 2,
        ], createdMinutesAgo: 20);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($event) {
            return $job->domainEventId === $event->id;
        });

        // Claim must be released so the re-queued job's Phase 1 lockForUpdate
        // guard can mutate dispatched_at back to now() instead of silent-skip.
        $event->refresh();
        $this->assertNull(
            $event->dispatched_at,
            'Stuck dispatched_at claim must be released before re-dispatch to avoid Phase 1 silent-skip.'
        );
    }

    /**
     * Lane B negative — claim younger than 10 min belongs to a legitimately
     * retrying worker (backoff curve totals ≈6.4 min + worst broadcast hang).
     * Must NOT be picked to avoid double-broadcast.
     */
    public function test_rescue_ignores_in_flight_claim_under_threshold(): void
    {
        Bus::fake();

        $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(2),
            'attempts' => 2,
        ], createdMinutesAgo: 5);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);
    }

    /**
     * [SYNC-P2-1 2026-08-04] Crash-claimed orphan past the OLD attempts<5 cap is now
     * RECOVERED. The old cap rested on a false premise ("attempts>=5 belongs to
     * RetryFailed"): RetryFailed is `pending()` = `whereNull(dispatched_at)`, so it can
     * NEVER reach a still-claimed row. A crash-claimed row at attempts>=5 fell through
     * EVERY lane = permanent orphan. The crash lane now keys on `broadcast_at IS NULL`
     * (never delivered) and relaxes the cap to <20, so a crash (which sets no last_error
     * and is not a persistent failure) self-heals. Job-level lockForUpdate dedup makes
     * any race with a straggler harmless.
     */
    public function test_rescue_now_recovers_crash_claimed_orphan_past_old_attempts_cap(): void
    {
        Bus::fake();

        $event = $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(15),
            'broadcast_at'  => null,
            'attempts'      => 5,
        ], createdMinutesAgo: 20);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($event) {
            return $job->domainEventId === $event->id;
        });

        $event->refresh();
        $this->assertNull($event->dispatched_at, 'Stuck claim must be released before re-dispatch.');
    }

    /**
     * [SYNC-P2-1] Anti-infinite-loop bound: a crash-claimed orphan that keeps re-crashing
     * stops auto-recovering at attempts>=20 (the monitor's crash-claimed dimension pages
     * ops instead). Keeps the relaxed cap from spinning forever on a genuinely poisoned row.
     */
    public function test_rescue_gives_up_on_crash_claimed_orphan_past_hard_cap(): void
    {
        Bus::fake();

        $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(15),
            'broadcast_at'  => null,
            'attempts'      => 20,
        ], createdMinutesAgo: 30);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);
    }

    /**
     * [SYNC-P2-1] A genuinely DELIVERED row (broadcast_at SET) is never re-driven, even if
     * its claim timestamp is old — delivery is done, re-broadcasting would be a duplicate.
     * This is the guard that stops the post-deploy mass re-broadcast of historical rows.
     */
    public function test_rescue_ignores_delivered_row(): void
    {
        Bus::fake();

        $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(15),
            'broadcast_at'  => now()->subMinutes(15),
            'attempts'      => 3,
        ], createdMinutesAgo: 20);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);
    }

    /**
     * Pending lane minute-2 gate still active: a freshly-created null-dispatched
     * row should NOT be picked (operator just created it; worker hasn't had
     * its chance yet). Confirms the heal preserves the existing `stale(2)`
     * behaviour for the pending lane.
     */
    public function test_rescue_ignores_fresh_pending_row_under_stale_gate(): void
    {
        Bus::fake();

        $this->seedDomainEvent([
            'dispatched_at' => null,
            'attempts' => 0,
        ], createdMinutesAgo: 0);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function seedDomainEvent(array $attrs, int $createdMinutesAgo): DomainEvent
    {
        $event = DomainEvent::query()->create(array_merge([
            'event_type' => 'order.created',
            'aggregate_type' => 'order',
            'aggregate_id' => (string) random_int(1000, 9999),
            'branch_id' => 7,
            'payload' => [],
            'channel' => json_encode(['private-branch.7']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'test-heal-b4-'.uniqid(),
            'occurred_at' => now()->subMinutes($createdMinutesAgo),
            'last_error' => null,
        ], $attrs));

        $event->forceFill([
            'created_at' => now()->subMinutes($createdMinutesAgo),
            'updated_at' => now()->subMinutes($createdMinutesAgo),
        ])->save();

        return $event->fresh();
    }
}
