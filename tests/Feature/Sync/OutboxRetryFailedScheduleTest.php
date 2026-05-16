<?php

namespace Tests\Feature\Sync;

use App\Enums\EventType;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Models\Order;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Bus;
use Tests\TestCase;

/**
 * [Sprint 3B P1-SYNC-02 2026-05-16]
 *
 * Pre-Sprint 3B audit (Wave D) flagged that `foodking:outbox:retry-failed` was
 * defined as a CLI command (`app/Console/Commands/OutboxRetryFailedCommand.php`)
 * but never wired into the kernel schedule. The complementary `outbox:rescue`
 * cron only re-queues rows with `attempts < 5`; terminal failures (>= 5) sit
 * pending forever and silently never broadcast.
 *
 * This sentinel pins:
 *  1. The hourly cron registration with the expected name / overlap protection /
 *     onOneServer constraint and `--since=24h` window flag.
 *  2. The 24h cutoff window contract: rows older than 24h are NOT reset by
 *     this lane (they require manual triage from the staleness alerter).
 *  3. Boundary contract with `outbox:rescue`: rows with attempts < 5 are NOT
 *     touched by retry-failed (rescue lane handles them).
 */
class OutboxRetryFailedScheduleTest extends TestCase
{
    use RefreshDatabase;

    public function test_kernel_registers_outbox_retry_failed_hourly(): void
    {
        $events = collect($this->app->make(Schedule::class)->events());

        $entry = $events->first(function ($event) {
            $description = property_exists($event, 'description') ? (string) ($event->description ?? '') : '';

            return str_contains((string) $event->command, 'foodking:outbox:retry-failed')
                || str_contains($description, 'Retry domain events failed after 5 attempts');
        });

        $this->assertNotNull(
            $entry,
            'foodking:outbox:retry-failed must be scheduled (Sprint 3B P1-SYNC-02). '
                . 'Without this schedule, terminal failures (attempts >= 5) never recover.'
        );

        $command = (string) $entry->command;
        $this->assertStringContainsString('foodking:outbox:retry-failed', $command);
        $this->assertStringContainsString('--since=24h', $command, '24h window flag must be present to bound recovery scope.');

        $this->assertSame(
            '0 * * * *',
            (string) $entry->expression,
            'retry-failed must run hourly (cron `0 * * * *`).'
        );

        $this->assertNotEmpty(
            $entry->mutex,
            'withoutOverlapping required to prevent concurrent retry runs.'
        );

        $this->assertTrue(
            (bool) $entry->onOneServer,
            'onOneServer required to prevent multi-node double-reset of the same batch.'
        );
    }

    public function test_retry_failed_resets_attempts_5_within_window(): void
    {
        Bus::fake();

        $event = $this->makeFailedEvent(attempts: 5, createdMinutesAgo: 30);

        $exit = Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);

        $this->assertSame(0, $exit);

        $event->refresh();
        $this->assertSame(0, $event->attempts, 'attempts must be reset to 0 for re-queue.');
        $this->assertNull($event->last_error);
        $this->assertNull($event->dispatched_at);

        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($event): bool {
            return $job->domainEventId === $event->id;
        });
    }

    public function test_retry_failed_resets_attempts_6_within_window(): void
    {
        // attempts can exceed 5 if the command was never scheduled (the very
        // bug Sprint 3B fixes) and the row drifted past 5 via the dispatch
        // job's own `attempts++` counter. The `failed(5)` scope is `>= 5`,
        // so attempts=6 must also be picked up.
        Bus::fake();

        $event = $this->makeFailedEvent(attempts: 6, createdMinutesAgo: 60);

        Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);

        $event->refresh();
        $this->assertSame(0, $event->attempts);

        Bus::assertDispatched(DispatchDomainEventsJob::class);
    }

    public function test_retry_failed_does_not_reset_rows_under_5_attempts(): void
    {
        // Boundary with outbox:rescue: rows with attempts < 5 are owned by
        // the rescue lane (every minute). retry-failed must NOT step on them
        // — otherwise the same row could be re-queued twice within a single
        // hour.
        Bus::fake();

        $event = $this->makeFailedEvent(attempts: 3, createdMinutesAgo: 30);

        Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);

        $event->refresh();
        $this->assertSame(
            3,
            $event->attempts,
            'rows with attempts < 5 must be left to the rescue lane (no reset).'
        );

        Bus::assertNotDispatched(DispatchDomainEventsJob::class);
    }

    public function test_retry_failed_skips_rows_older_than_24h(): void
    {
        // 24h+ rows are out of the recovery window — staleness monitor pages
        // a human for triage instead of indefinite re-queueing.
        Bus::fake();

        $event = $this->makeFailedEvent(attempts: 5, createdMinutesAgo: 25 * 60);

        Artisan::call('foodking:outbox:retry-failed', ['--since' => '24h']);

        $event->refresh();
        $this->assertSame(
            5,
            $event->attempts,
            'rows older than --since=24h must not be reset by retry-failed.'
        );

        Bus::assertNotDispatched(DispatchDomainEventsJob::class);
    }

    private function makeFailedEvent(int $attempts, int $createdMinutesAgo): DomainEvent
    {
        $createdAt = now()->subMinutes($createdMinutesAgo);

        $event = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 9000 + $attempts,
            'branch_id' => 1,
            'payload' => ['order_id' => 9000 + $attempts],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'test-sprint3b-' . $attempts,
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
