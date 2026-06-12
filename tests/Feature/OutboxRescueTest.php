<?php

namespace Tests\Feature;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class OutboxRescueTest extends TestCase
{
    use RefreshDatabase;

    public function test_outbox_rescue_commands_are_registered(): void
    {
        $commands = array_keys(Artisan::all());

        $this->assertContains('foodking:outbox:rescue', $commands);
        $this->assertContains('foodking:outbox:retry-failed', $commands);
    }

    public function test_outbox_rescue_requeues_stale_pending_event_on_high_queue(): void
    {
        Queue::fake();

        $event = DomainEvent::query()->create([
            'event_type' => 'order.created',
            'aggregate_type' => 'order',
            'aggregate_id' => '123',
            'branch_id' => 7,
            'payload' => [],
            'channel' => json_encode(['private-branch.7']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'test-correlation',
            'occurred_at' => now()->subMinutes(3),
            'attempts' => 0,
            'dispatched_at' => null,
            'last_error' => null,
        ]);
        $event->forceFill([
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ])->save();

        $exit = Artisan::call('foodking:outbox:rescue');

        $this->assertSame(0, $exit);
        Queue::assertPushed(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($event) {
            return $job->domainEventId === $event->id
                && $job->queue === 'high';
        });
    }

    /**
     * [W-REM R1 T-R1.1c — 2026-06-12] Lane A is BOUNDED: the pending lane
     * re-queues at most `--limit` rows per run (deterministic id order) so a
     * backlog surge can never trigger an unbounded scan + unbounded queue
     * flood from the every-minute cron. Overflow drains on the next tick.
     */
    public function test_outbox_rescue_lane_a_is_bounded_by_limit_option(): void
    {
        Queue::fake();

        $ids = [];

        foreach (range(1, 3) as $i) {
            $event = DomainEvent::query()->create([
                'event_type' => 'order.created',
                'aggregate_type' => 'order',
                'aggregate_id' => (string) (1000 + $i),
                'branch_id' => 7,
                'payload' => [],
                'channel' => json_encode(['private-branch.7']),
                'broadcast_as' => 'OrderCreated',
                'correlation_id' => 'test-bounded-'.$i,
                'occurred_at' => now()->subMinutes(5),
                'attempts' => 0,
                'dispatched_at' => null,
                'last_error' => null,
            ]);
            $event->forceFill([
                'created_at' => now()->subMinutes(5),
                'updated_at' => now()->subMinutes(5),
            ])->save();
            $ids[] = $event->id;
        }

        $exit = Artisan::call('foodking:outbox:rescue', ['--limit' => 2]);

        $this->assertSame(0, $exit);
        Queue::assertPushed(DispatchDomainEventsJob::class, 2);

        // Deterministic order: the two LOWEST ids are re-queued; the tail
        // overflows to the next cron tick.
        sort($ids);
        $expected = array_slice($ids, 0, 2);
        Queue::assertPushed(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($expected) {
            return in_array($job->domainEventId, $expected, true);
        });
    }
}
