<?php

namespace Tests\Feature\Outbox;

use App\Enums\EventType;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * [Outbox dead-letter fix — 2026-07-07]
 *
 * `HealthController::checkQueueWorker` counted EVERY undispatched
 * `domain_events` row older than 30s as "worker lagging". Terminal contract
 * violations (short-circuited via $this->fail(), NEVER retried — see
 * app/Jobs/DispatchDomainEventsJob.php:168-187) freeze at dispatched_at=NULL
 * and accumulate, so 17 immortal poison rows pushed /health/ready to a FALSE
 * 503 — pulling healthy prod nodes out of the load balancer.
 *
 * This test pins that contract-violation rows are EXCLUDED from the lag
 * signal, while genuinely-pending rows still trip it.
 */
class HealthQueueWorkerContractViolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_contract_violation_rows_do_not_count_as_worker_lag(): void
    {
        // 15 terminal poison rows — well above the >10 threshold — but they are
        // NOT worker lag, so the queue_worker probe must stay green.
        $this->seedStale(15, 'contract_violation: envelope missing field');

        $response = $this->getJson('/api/health/ready');

        $response->assertJsonPath('subsystems.queue_worker.status', 'ok');
        $response->assertJsonPath('subsystems.queue_worker.stale_count', 0);
    }

    public function test_genuinely_pending_rows_still_trip_worker_lag(): void
    {
        // 15 undispatched rows with NO error = a real backlog (worker down).
        $this->seedStale(15, null);

        $response = $this->getJson('/api/health/ready');

        $response->assertJsonPath('subsystems.queue_worker.status', 'error');
        $response->assertJsonPath('subsystems.queue_worker.stale_count', 15);
    }

    public function test_mixed_backlog_counts_only_the_genuine_rows(): void
    {
        // 12 poison (excluded) + 3 genuine pending (counted) → below the >10
        // threshold once poison is filtered out → worker probe stays green.
        $this->seedStale(12, 'contract_violation: bad payload');
        $this->seedStale(3, null);

        $response = $this->getJson('/api/health/ready');

        $response->assertJsonPath('subsystems.queue_worker.status', 'ok');
        $response->assertJsonPath('subsystems.queue_worker.stale_count', 3);
    }

    public function test_non_contract_runtime_failures_still_count(): void
    {
        // Retrying runtime failures (non-contract error) ARE evidence of a
        // struggling pipeline and must still count toward the lag signal.
        $this->seedStale(15, 'Pusher unreachable');

        $response = $this->getJson('/api/health/ready');

        $response->assertJsonPath('subsystems.queue_worker.status', 'error');
        $response->assertJsonPath('subsystems.queue_worker.stale_count', 15);
    }

    /**
     * [Outbox recency-floor fix — 2026-07-11] Second immortal-row class:
     * ancient orphans (attempts=0 / last_error=NULL) from a PAST worker-down
     * window must NOT keep tripping /ready once the worker recovered.
     */
    public function test_ancient_orphan_rows_do_not_count_as_worker_lag(): void
    {
        // 20 undispatched orphans created 25h ago — outside the 24h active
        // retry window. The worker is fine NOW; these must not flap the gate.
        $this->seedStale(20, null, now()->subHours(25));

        $response = $this->getJson('/api/health/ready');

        $response->assertJsonPath('subsystems.queue_worker.status', 'ok');
        $response->assertJsonPath('subsystems.queue_worker.stale_count', 0);
    }

    public function test_recent_backlog_counts_even_with_ancient_orphans_present(): void
    {
        // 20 ancient orphans (excluded by recency floor) + 15 recent genuine
        // pending (a real current outage) → only the recent 15 count → 503.
        $this->seedStale(20, null, now()->subHours(25));
        $this->seedStale(15, null);

        $response = $this->getJson('/api/health/ready');

        $response->assertJsonPath('subsystems.queue_worker.status', 'error');
        $response->assertJsonPath('subsystems.queue_worker.stale_count', 15);
    }

    private function seedStale(int $count, ?string $lastError, ?\Illuminate\Support\Carbon $createdAt = null): void
    {
        $past = $createdAt ?? now()->subMinute();
        $rows = [];
        static $seq = 0;

        for ($i = 0; $i < $count; $i++) {
            $seq++;
            $rows[] = [
                'event_type' => EventType::ORDER_CREATED,
                'aggregate_type' => 'Order',
                'aggregate_id' => 9000 + $seq,
                'branch_id' => 1,
                'payload' => json_encode(['order_id' => 9000 + $seq]),
                'channel' => json_encode(['private-branch.1']),
                'broadcast_as' => 'OrderCreated',
                'correlation_id' => 'health-cv-' . $seq,
                'occurred_at' => $past,
                'dispatched_at' => null,
                'attempts' => $lastError === null ? 0 : 2,
                'last_error' => $lastError,
                'created_at' => $past,
                'updated_at' => $past,
            ];
        }

        DomainEvent::query()->getQuery()->insert($rows);
    }
}
