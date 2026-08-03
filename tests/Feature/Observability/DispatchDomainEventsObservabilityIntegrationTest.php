<?php

namespace Tests\Feature\Observability;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Services\Observability\SyncMetricsRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

/**
 * [NEW-04 / Audit T-MISS-A + T-MISS-C]
 *
 * Sentinel tests around the integration point between DispatchDomainEventsJob
 * and SyncMetricsRecorder. These exist independently of SyncMetricsRecorderTest
 * because they prove behaviour AT the call site — not just the recorder in
 * isolation.
 */
class DispatchDomainEventsObservabilityIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * T-MISS-A — Even if the metrics insert blows up (DB pressure, table
     * locked, etc.), the outbox job MUST finalise: dispatched_at preserved,
     * last_error cleared. Telemetry failure cannot poison the outbox state.
     */
    public function test_metrics_insert_failure_does_not_break_outbox_finalisation(): void
    {
        $domainEvent = $this->makeClaimedDomainEvent(['last_error' => 'old residual']);

        $recorder = Mockery::mock(SyncMetricsRecorder::class);
        $recorder->shouldReceive('recordEventDispatched')
            ->once()
            ->andThrow(new \RuntimeException('sync_metrics table locked'));
        $this->app->instance(SyncMetricsRecorder::class, $recorder);

        // Job should complete WITHOUT propagating the metrics exception.
        (new DispatchDomainEventsJob($domainEvent->id))->handle();

        $domainEvent->refresh();

        $this->assertNotNull($domainEvent->dispatched_at, 'dispatched_at must remain set after telemetry failure');
        $this->assertNull($domainEvent->last_error, 'last_error must be cleared even when telemetry throws');
        $this->assertSame(2, (int) $domainEvent->attempts, 'attempts incremented exactly once during the claim');
    }

    /**
     * T-MISS-C — In a queue-worker context (no HTTP request bound), the
     * domain_event.correlation_id must be propagated to sync_metrics. This
     * verifies the explicit correlation_id arg wins over the request fallback
     * (which would be null/empty in a worker).
     */
    public function test_worker_context_propagates_domain_event_correlation_id(): void
    {
        $cid = (string) Str::uuid();
        $domainEvent = $this->makeClaimedDomainEvent(['correlation_id' => $cid]);

        (new DispatchDomainEventsJob($domainEvent->id))->handle();

        $row = DB::table('sync_metrics')
            ->where('metric_type', 'outbox.dispatch_latency_ms')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, 'expected a sync_metrics row for the dispatched event');
        $this->assertSame($cid, $row->correlation_id, 'sync_metrics.correlation_id must match the domain event');
    }

    private function makeClaimedDomainEvent(array $overrides = []): DomainEvent
    {
        $defaults = [
            'event_type' => 'TestEvent',
            'aggregate_type' => 'order',
            'aggregate_id' => 1,
            'branch_id' => 1,
            'payload' => json_encode([]),
            // No channel/broadcast_as: handle() skips broadcast and goes
            // straight to the success-path forceFill — perfect for telemetry
            // assertions without spinning up a Pusher mock.
            'channel' => null,
            'broadcast_as' => null,
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now()->subSeconds(2),
            'dispatched_at' => null,
            'attempts' => 1,
            'last_error' => null,
        ];

        $event = DomainEvent::create(array_merge($defaults, $overrides));
        // Persist-but-don't-claim: the job will lock + claim during handle().
        return $event->refresh();
    }
}
