<?php

namespace Tests\Feature\Outbox;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * [W-REM R1 T-R1.1b — 2026-06-12] F-SHARED-02: rescue lane B duplicated
 * broadcasts ~4× per event.
 *
 * Root cause: the heal-B.4 "crash-claimed" lane B re-queued ANY row with
 * `dispatched_at NOT NULL AND dispatched_at < now()-10min AND attempts<5`.
 * But a SUCCESSFULLY dispatched row keeps `dispatched_at` set forever
 * (Phase 3a of DispatchDomainEventsJob clears last_error, keeps the claim).
 * So every minute-cron rescue run nulled the claim of every successfully
 * dispatched event older than 10 minutes and re-broadcast it, incrementing
 * attempts 1→2→3→4→5 — exactly the observed ~4 duplicate broadcasts per
 * event before the attempts<5 cap finally stopped the loop.
 *
 * New contract: rescue NEVER re-queues a row whose dispatched_at is NOT NULL.
 * Crash-claimed orphans are surfaced by MonitorOutboxStaleness (H3 alarm
 * dimension) for MANUAL re-drive — a rare manual action is strictly better
 * than systematic duplicate KDS/notification replays.
 */
class OutboxLaneBNoRebroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_rescue_does_not_rebroadcast_successfully_dispatched_row(): void
    {
        Bus::fake();

        // Successfully dispatched 20 minutes ago: dispatched_at SET,
        // last_error NULL (Phase 3a), attempts=1. Pre-fix lane B re-queued
        // this row on every rescue run → F-SHARED-02 duplicates.
        $event = $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(20),
            'attempts' => 1,
            'last_error' => null,
        ], createdMinutesAgo: 25);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);

        $event->refresh();
        $this->assertNotNull(
            $event->dispatched_at,
            'dispatched_at claim of an already-dispatched row must NOT be released by rescue (no re-broadcast).'
        );
        $this->assertSame(1, (int) $event->attempts, 'attempts must not be consumed by phantom re-queues.');
    }

    public function test_rescue_does_not_rebroadcast_claimed_row_with_error_trail(): void
    {
        Bus::fake();

        // Crash-claimed shape (dispatched_at SET + last_error SET from a
        // prior attempt). Also excluded: the monitor pages for manual
        // re-drive instead of risking a duplicate broadcast.
        $event = $this->seedDomainEvent([
            'dispatched_at' => now()->subMinutes(15),
            'attempts' => 2,
            'last_error' => 'Pusher timeout',
        ], createdMinutesAgo: 20);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertNotDispatched(DispatchDomainEventsJob::class);

        $event->refresh();
        $this->assertNotNull($event->dispatched_at, 'Claimed row must stay claimed — manual re-drive only.');
    }

    public function test_rescue_still_requeues_fresh_stale_pending_rows(): void
    {
        Bus::fake();

        // Sanity guard: removing lane B must NOT break lane A (pending lane).
        $pending = $this->seedDomainEvent([
            'dispatched_at' => null,
            'attempts' => 0,
            'last_error' => null,
        ], createdMinutesAgo: 3);

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($pending) {
            return $job->domainEventId === $pending->id;
        });
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
            'branch_id' => 1,
            'payload' => [],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'test-laneb-'.uniqid(),
            'occurred_at' => now()->subMinutes($createdMinutesAgo),
        ], $attrs));

        $event->forceFill([
            'created_at' => now()->subMinutes($createdMinutesAgo),
            'updated_at' => now()->subMinutes($createdMinutesAgo),
        ])->save();

        return $event->fresh();
    }
}
