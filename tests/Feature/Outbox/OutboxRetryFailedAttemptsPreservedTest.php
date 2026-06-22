<?php

namespace Tests\Feature\Outbox;

use App\Enums\EventType;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * [Heal B.1 Z3 B-2 P0 — 2026-05-19]
 *
 * RED-Z3 evidence (anchor):
 *   `app/Console/Commands/OutboxRetryFailedCommand.php:119-123` pre-heal
 *   wiped `attempts=0`, `last_error=null` on every replay. Two consequences
 *   compounded into a P0 sync defect:
 *     1. The PruneOutboxCommand lane (`attempts>=6 AND created_at<cutoff`)
 *        could NEVER reclaim a chronically-failing row — `attempts` was
 *        reset hourly, so the row "flapped" indefinitely between failed
 *        and pending without ever crossing the prune threshold.
 *     2. Forensic `last_error` history was destroyed each cycle, making
 *        post-mortem of chronic broadcast failures impossible.
 *
 * Heal: preserve `attempts` monotonically + cap replays at
 * `REPLAY_MAX_ATTEMPTS = 12` (≈ 2× the `$tries=6` cycles of
 * DispatchDomainEventsJob). At cap, retry-failed stops touching the row
 * so the prune lane can eventually reclaim it AND the staleness monitor
 * keeps paging the operator (it filters on dispatched_at NULL, NOT on
 * attempts ceiling).
 *
 * Contract pinned by this sentinel:
 *   - row at attempts < REPLAY_MAX_ATTEMPTS within --since cutoff:
 *       attempts PRESERVED (not reset to 0), last_error PRESERVED,
 *       dispatched_at re-nulled (so DispatchDomainEventsJob can re-claim),
 *       DispatchDomainEventsJob dispatched once.
 *   - row at attempts >= REPLAY_MAX_ATTEMPTS within --since cutoff:
 *       UNTOUCHED (no field mutation), NO dispatch (prune lane will
 *       eventually reclaim; staleness monitor still pages until then).
 */
class OutboxRetryFailedAttemptsPreservedTest extends TestCase
{
    use RefreshDatabase;

    public function test_retry_failed_preserves_attempts_when_below_replay_cap(): void
    {
        Bus::fake();

        $event = $this->makeFailedEvent(attempts: 8, createdMinutesAgo: 30);

        $exit = Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);
        $this->assertSame(0, $exit);

        $event->refresh();

        $this->assertSame(
            8,
            $event->attempts,
            'Heal B.1: attempts MUST be preserved monotonically — pre-heal reset to 0 caused indefinite flap.'
        );
        $this->assertSame(
            'Pusher unreachable',
            (string) $event->last_error,
            'Heal B.1: last_error MUST be preserved as forensic trail (pre-heal wiped it).'
        );
        $this->assertNull(
            $event->dispatched_at,
            'Heal B.1: dispatched_at MUST still be re-nulled so DispatchDomainEventsJob can re-claim.'
        );

        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($event): bool {
            return $job->domainEventId === $event->id;
        });
    }

    public function test_retry_failed_skips_row_at_or_above_replay_cap(): void
    {
        Bus::fake();

        // REPLAY_MAX_ATTEMPTS = 12. attempts=12 must be SKIPPED.
        $event = $this->makeFailedEvent(attempts: 12, createdMinutesAgo: 30);

        $exit = Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);
        $this->assertSame(0, $exit);

        $event->refresh();

        $this->assertSame(
            12,
            $event->attempts,
            'Row at REPLAY_MAX_ATTEMPTS cap must NOT be touched.'
        );
        $this->assertSame(
            'Pusher unreachable',
            (string) $event->last_error,
            'Row at cap must NOT have last_error mutated.'
        );
        $this->assertNull(
            $event->dispatched_at,
            'Row at cap must keep dispatched_at NULL (already NULL pre-run, untouched).'
        );

        Bus::assertNotDispatched(DispatchDomainEventsJob::class);
    }

    public function test_retry_failed_dispatches_row_just_below_replay_cap(): void
    {
        // Boundary case: attempts=11 (just below cap of 12) MUST still replay.
        Bus::fake();

        $event = $this->makeFailedEvent(attempts: 11, createdMinutesAgo: 30);

        Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);

        $event->refresh();

        $this->assertSame(11, $event->attempts, 'attempts=11 must replay (cap is strict less-than 12) and be preserved.');
        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($event): bool {
            return $job->domainEventId === $event->id;
        });
    }

    private function makeFailedEvent(int $attempts, int $createdMinutesAgo): DomainEvent
    {
        $createdAt = now()->subMinutes($createdMinutesAgo);

        $event = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 7000 + $attempts,
            'branch_id' => 1,
            'payload' => ['order_id' => 7000 + $attempts],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'heal-b1-' . $attempts,
            'occurred_at' => $createdAt,
            'attempts' => $attempts,
            'last_error' => 'Pusher unreachable',
        ]);

        $event->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $event;
    }
}
