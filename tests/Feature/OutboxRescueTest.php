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
}
