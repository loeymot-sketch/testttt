<?php

namespace Tests\Feature\Sentinels;

use App\Enums\EventType;
use App\Exceptions\PayloadMismatchException;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * [F-3 SYNC P1 V1.0.1 quick win — 2026-05-19]
 *
 * Sentinel locking DispatchDomainEventsJob behaviour on
 * PayloadMismatchException: contract violations are NOT retry-recoverable
 * (the payload itself is malformed) so the job MUST short-circuit the
 * 6-attempt $backoff curve [1,5,15,60,300]s via $this->fail($e). Without
 * this, 1 bad payload becomes 6 'high' queue messages — 1000 bad payloads
 * = 6000 messages saturating the high lane while never being recoverable.
 *
 * Source audit: reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md
 *               §P1 — Hardening → "PayloadMismatchException retry loop"
 *
 * Companion sentinel: OutboxPipelineHealthSentinelTest::test_contract_violation_preserves_pager_grade_prefix
 * (same path, opposite-but-compatible pin: last_error prefix preserved).
 *
 * Mode: real PayloadMismatchException — let EventContract::assertEnvelopeValid
 * throw against a deliberately malformed payload. NO alias-mock (incompatible
 * with suite-wide test isolation: EventContract is autoloaded by other tests).
 */
class PayloadMismatchFailOnceSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Primary invariant: PayloadMismatchException MUST be swallowed by
     * $this->fail($e) so Laravel does NOT re-queue the job for the full
     * $backoff curve.
     */
    public function test_payload_mismatch_does_not_rethrow_to_avoid_retry_curve(): void
    {
        $event = DomainEvent::query()->create([
            'event_type'     => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item',
            'aggregate_id'   => 999004,
            'branch_id'      => 1,
            // Intentionally malformed: missing item_id, status, type — the
            // required keys per the V1 envelope contract.
            'payload'        => ['malformed' => true],
            'channel'        => json_encode(['private-branch.1']),
            'broadcast_as'   => 'ItemAvailabilityChanged',
            'correlation_id' => 'sentinel-fail-once-' . uniqid('', true),
            'occurred_at'    => now(),
            'attempts'       => 0,
            'last_error'     => null,
            'dispatched_at'  => null,
        ]);

        // Broadcaster MUST NOT be reached on contract violation —
        // assertEnvelopeValid runs before broadcast(). Pin that here so a
        // future refactor that moves assertion AFTER broadcast surfaces here.
        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldNotReceive('broadcast');
        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->andReturn($broadcaster);
        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);

        $threw = false;
        try {
            (new DispatchDomainEventsJob($event->id))->handle();
        } catch (PayloadMismatchException $e) {
            $threw = true;
        }

        $this->assertFalse(
            $threw,
            'DispatchDomainEventsJob::handle MUST NOT rethrow PayloadMismatchException — '
                . 'contract violations are not retry-recoverable; $this->fail($e) routes the '
                . 'event directly to failed_jobs and stops the 6-attempt $backoff curve. '
                . 'See reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md §P1 '
                . '"PayloadMismatchException retry loop".'
        );

        // last_error invariants preserved (these are what the OLD sentinel
        // OutboxPipelineHealthSentinelTest::test_contract_violation_preserves_pager_grade_prefix
        // also pins — pager-grade grep on the prefix still works).
        $event->refresh();
        $this->assertNull(
            $event->dispatched_at,
            'On contract violation, dispatched_at MUST be cleared (phase 3b release).'
        );
        $this->assertStringStartsWith(
            'contract_violation: ',
            (string) $event->last_error,
            'last_error MUST carry the contract_violation: prefix for monitoring grep.'
        );
    }

    /**
     * Defense in depth: NON-PayloadMismatch exceptions MUST still rethrow so
     * Laravel's retry curve applies (Pusher restarts, transient DB errors, etc.
     * ARE recoverable; only contract violations are not).
     *
     * Without this guard, a future "simplification" that broadens the
     * fail-fast behaviour to all Throwable would silently kill the retry
     * curve for transient outages — and that would re-introduce the RED-R3
     * silent-failure mode.
     */
    public function test_generic_throwable_still_rethrows_to_preserve_retry_curve(): void
    {
        $event = DomainEvent::query()->create([
            'event_type'     => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item',
            'aggregate_id'   => 999005,
            'branch_id'      => 1,
            'payload'        => [
                'item_id'      => 999005,
                'status'       => 1,
                'price'        => 5.0,
                'type'         => 'branch_availability',
                'is_available' => true,
                'branch_id'    => 1,
                'reason'       => null,
            ],
            'channel'        => json_encode(['private-branch.1']),
            'broadcast_as'   => 'ItemAvailabilityChanged',
            'correlation_id' => 'sentinel-retain-retry-' . uniqid('', true),
            'occurred_at'    => now(),
            'attempts'       => 0,
            'last_error'     => null,
            'dispatched_at'  => null,
        ]);

        // Inject a broadcaster that always throws a generic RuntimeException
        // (simulates soketi outage / network blip). DispatchDomainEventsJob
        // MUST rethrow so Laravel re-queues the job.
        $failing = Mockery::mock(Broadcaster::class);
        $failing->shouldReceive('broadcast')
            ->once()
            ->andThrow(new RuntimeException('simulated transient outage'));
        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->andReturn($failing);
        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);

        $threw = false;
        try {
            (new DispatchDomainEventsJob($event->id))->handle();
        } catch (RuntimeException $e) {
            $threw = true;
            $this->assertSame('simulated transient outage', $e->getMessage());
        }

        $this->assertTrue(
            $threw,
            'Generic Throwable MUST still rethrow so Laravel applies $backoff and $tries. '
                . 'Only PayloadMismatchException is special-cased — broadening the fail() '
                . 'short-circuit to all exceptions would re-introduce the RED-R3 silent-failure mode.'
        );

        $event->refresh();
        $this->assertNull($event->dispatched_at, 'Phase 3b release still applies to generic Throwable.');
        $this->assertSame(
            'simulated transient outage',
            (string) $event->last_error,
            'Generic exceptions keep their plain message (no contract_violation: prefix).'
        );
    }
}
