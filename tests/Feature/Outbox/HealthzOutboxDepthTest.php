<?php

namespace Tests\Feature\Outbox;

use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [W-REM R1 T-R1.2 — 2026-06-12] RED-SHARED-02: healthz `queue_pending`
 * graphed the REDIS queue depth (Queue::size default+high), not the real
 * outbox backlog. With a live worker the redis lists are near-empty even
 * while 8 405 domain_events rows sit pending (worker down at event-creation
 * time, jobs long since dropped/consumed) — the monitor graph showed 0
 * while a day of sync events was silently undelivered.
 *
 * New contract: `checks.queue_pending` = COUNT(domain_events WHERE
 * dispatched_at IS NULL) — the true number of undelivered sync events,
 * regardless of redis list depth. Mirrored by `healthz:check` CLI.
 */
class HealthzOutboxDepthTest extends TestCase
{
    use RefreshDatabase;

    public function test_queue_pending_counts_domain_events_pending_backlog(): void
    {
        // 3 pending (mixed ages) + 2 dispatched → backlog = 3.
        $this->seedDomainEvent(['dispatched_at' => null], minutesAgo: 1);
        $this->seedDomainEvent(['dispatched_at' => null], minutesAgo: 30);
        $this->seedDomainEvent(['dispatched_at' => null, 'attempts' => 5, 'last_error' => 'Pusher timeout'], minutesAgo: 90);
        $this->seedDomainEvent(['dispatched_at' => now()->subMinutes(5), 'attempts' => 1], minutesAgo: 10);
        $this->seedDomainEvent(['dispatched_at' => now()->subMinutes(2), 'attempts' => 1], minutesAgo: 4);

        $response = $this->getJson('/api/healthz');

        $this->assertContains($response->getStatusCode(), [200, 503]);
        $this->assertSame(
            3,
            $response->json('checks.queue_pending'),
            'queue_pending must be the REAL outbox backlog (COUNT domain_events pending), not the redis list depth.'
        );
    }

    public function test_healthz_check_cli_mirrors_outbox_backlog(): void
    {
        $this->seedDomainEvent(['dispatched_at' => null], minutesAgo: 1);
        $this->seedDomainEvent(['dispatched_at' => null], minutesAgo: 10);

        // NOTE: Artisan::output() is unusable under RefreshDatabase (the
        // migrate:fresh PendingCommand leaves a mocked OutputStyle bound),
        // so assert through the PendingCommand expectations instead.
        // json_encode emits compact keys → '"queue_pending":2'.
        $this->artisan('healthz:check', ['--json' => true])
            ->expectsOutputToContain('"queue_pending":2')
            ->assertExitCode(0);

        // Shared probe = the single SSOT both the CLI mirror and the HTTP
        // surface delegate to (HealthzCheckCommand::checkQueuePending →
        // HealthzController::probeQueuePending).
        $this->assertSame(
            2,
            \App\Http\Controllers\HealthzController::probeQueuePending(),
            'CLI heartbeat lane must report the same outbox backlog as the HTTP probe.'
        );
    }

    /**
     * @param  array<string,mixed>  $attrs
     */
    private function seedDomainEvent(array $attrs, int $minutesAgo): DomainEvent
    {
        $event = DomainEvent::query()->create(array_merge([
            'event_type' => 'order.created',
            'aggregate_type' => 'order',
            'aggregate_id' => (string) random_int(1000, 9999),
            'branch_id' => 1,
            'payload' => [],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'test-healthz-depth-'.uniqid(),
            'occurred_at' => now()->subMinutes($minutesAgo),
            'attempts' => 0,
            'last_error' => null,
        ], $attrs));

        $event->forceFill([
            'created_at' => now()->subMinutes($minutesAgo),
            'updated_at' => now()->subMinutes($minutesAgo),
        ])->save();

        return $event->fresh();
    }
}
