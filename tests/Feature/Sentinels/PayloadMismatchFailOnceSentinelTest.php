<?php

namespace Tests\Feature\Sentinels;

use App\Domain\Events\EventContract;
use App\Enums\EventType;
use App\Exceptions\PayloadMismatchException;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

/**
 * [F-3 SYNC P1 V1.0.1 quick win — 2026-05-19]
 *
 * Sentinel locking the DispatchDomainEventsJob behaviour on
 * PayloadMismatchException: contract violations are NOT recoverable by
 * retry (the payload itself is malformed), so we must short-circuit the
 * 6-attempt $backoff curve [1,5,15,60,300]s and route the event directly
 * to the failed_jobs lane. Without this, 1 bad payload becomes 6 'high'
 * queue messages — 1000 bad payloads = 6000 messages saturating the high
 * lane while never being recoverable.
 *
 * Source audit: reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md
 *               §P1 — Hardening → "PayloadMismatchException retry loop"
 *
 * Mode: behavioural — we drive the job's handle() against a fixture
 * DomainEvent and assert (a) dispatched_at is cleared, (b) last_error
 * carries the `contract_violation:` prefix, (c) the job did NOT throw
 * (because $this->fail() was called, terminating the attempt without
 * re-queue). The "no throw" assertion is the behavioural locking pin.
 */
class PayloadMismatchFailOnceSentinelTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_payload_mismatch_marks_job_failed_without_rethrowing(): void
    {
        // Build a DomainEvent that will broadcast (channel + broadcast_as set)
        // and force a contract violation by stubbing EventContract.
        $event = DomainEvent::create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => 'Order',
            'aggregate_id' => 1,
            'branch_id' => 1,
            'payload' => json_encode(['order_id' => 1]),
            'channel' => json_encode(['orders.branch.1']),
            'broadcast_as' => 'OrderCreated',
            'occurred_at' => now(),
        ]);

        // Force EventContract::assertEnvelopeValid to throw PayloadMismatchException.
        // The class is statically called from the job; we use the alias mock pattern
        // — supported by mockery because the class is autoloaded.
        $mismatch = new PayloadMismatchException(
            'envelope missing required key',
            ['missing:order_id'],
            (string) EventType::ORDER_CREATED
        );
        $mock = Mockery::mock('alias:' . EventContract::class);
        $mock->shouldReceive('buildEnvelope')->andReturn(['stub' => true]);
        $mock->shouldReceive('assertEnvelopeValid')->andThrow($mismatch);

        $job = new DispatchDomainEventsJob($event->id);

        // Behavioural invariant: the job MUST NOT rethrow a PayloadMismatchException
        // (which would trigger Laravel queue retry). It MUST instead call
        // $this->fail($e), which terminates the attempt without re-queue.
        $threw = false;
        try {
            $job->handle();
        } catch (PayloadMismatchException $e) {
            $threw = true;
        }

        $this->assertFalse(
            $threw,
            'DispatchDomainEventsJob::handle MUST NOT rethrow PayloadMismatchException — '
                . 'contract violations are not retry-recoverable. Use $this->fail($e) instead. '
                . 'See reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md §P1 '
                . '"PayloadMismatchException retry loop".'
        );

        // Persistence invariants preserved.
        $event->refresh();
        $this->assertNull(
            $event->dispatched_at,
            'On contract violation, dispatched_at MUST be cleared so the row remains observable.'
        );
        $this->assertStringStartsWith(
            'contract_violation: ',
            (string) $event->last_error,
            'last_error MUST carry the contract_violation prefix for monitoring grep.'
        );
    }

    /**
     * Defense in depth: NON-PayloadMismatch exceptions MUST still rethrow so
     * Laravel's retry curve applies (Pusher restarts etc. are recoverable).
     */
    public function test_generic_throwable_still_rethrows_to_preserve_retry_curve(): void
    {
        $event = DomainEvent::create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => 'Order',
            'aggregate_id' => 1,
            'branch_id' => 1,
            'payload' => json_encode(['order_id' => 1]),
            'channel' => json_encode(['orders.branch.1']),
            'broadcast_as' => 'OrderCreated',
            'occurred_at' => now(),
        ]);

        $boom = new \RuntimeException('pusher_unreachable');
        $mock = Mockery::mock('alias:' . EventContract::class);
        $mock->shouldReceive('buildEnvelope')->andReturn(['stub' => true]);
        $mock->shouldReceive('assertEnvelopeValid')->andThrow($boom);

        $job = new DispatchDomainEventsJob($event->id);

        $threw = false;
        try {
            $job->handle();
        } catch (\RuntimeException $e) {
            $threw = true;
            $this->assertSame('pusher_unreachable', $e->getMessage());
        }

        $this->assertTrue(
            $threw,
            'Generic Throwable MUST still rethrow so Laravel applies $backoff and $tries. '
                . 'Only PayloadMismatchException is special-cased.'
        );
    }
}
