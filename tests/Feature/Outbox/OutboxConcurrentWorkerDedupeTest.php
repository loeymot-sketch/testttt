<?php

namespace Tests\Feature\Outbox;

use App\Enums\EventType;
use App\Exceptions\PayloadMismatchException;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Models\Order;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Mockery;
use RuntimeException;
use Tests\TestCase;

/**
 * [F-NEW01 / Phase 1bis] Verifies the claim-then-broadcast-then-finalize-or-release
 * pattern in DispatchDomainEventsJob::handle() so that concurrent workers cannot
 * broadcast the same DomainEvent twice, while preserving the
 * `commit_before_dispatch` invariant (broadcast happens AFTER the claim
 * transaction commits).
 *
 * Audit G3 (Claude Code, 2026-04-23) note: SQLite (CI test backend) treats
 * `lockForUpdate()` as a no-op — Laravel does not emit `SELECT ... FOR UPDATE`
 * outside MySQL/PostgreSQL. The tests below therefore exercise the
 * post-claim idempotence path (sequential handle() calls observe
 * `dispatched_at != null` after the first commit and skip), not the true
 * concurrent-row-lock path. The lock semantic is exercised end-to-end on
 * MySQL/PostgreSQL in production. A dedicated MySQL integration test is
 * tracked in plans/MEGA_PLAN_SYNC_HARDENING_v3 (Phase 3 dette technique).
 */
class OutboxConcurrentWorkerDedupeTest extends TestCase
{
    use RefreshDatabase;

    public function test_two_sequential_handle_calls_only_broadcast_once(): void
    {
        $event = $this->makeDomainEvent();

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')->once();
        $this->mockBroadcastManager($broadcaster);

        (new DispatchDomainEventsJob($event->id))->handle();
        // Second handle() with the same id (e.g. duplicate enqueue or worker race
        // post-claim) MUST observe dispatched_at != null after lockForUpdate
        // and return silently without firing the broadcaster again.
        (new DispatchDomainEventsJob($event->id))->handle();

        $event->refresh();
        $this->assertNotNull($event->dispatched_at);
        $this->assertSame(1, (int) $event->attempts);
    }

    public function test_claim_is_committed_before_broadcast(): void
    {
        $event = $this->makeDomainEvent();
        $eventId = $event->id;

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')
            ->once()
            ->andReturnUsing(function () use ($eventId): void {
                // While inside broadcast(), the claim transaction MUST already be
                // committed → a fresh DB read sees dispatched_at != null.
                $row = DB::table('domain_events')->where('id', $eventId)->first();
                $this->assertNotNull($row);
                $this->assertNotNull($row->dispatched_at, 'Claim must be committed BEFORE broadcaster fires.');
            });
        $this->mockBroadcastManager($broadcaster);

        (new DispatchDomainEventsJob($event->id))->handle();
    }

    public function test_broadcast_failure_releases_claim_for_retry(): void
    {
        $event = $this->makeDomainEvent();

        $failingBroadcaster = Mockery::mock(Broadcaster::class);
        $failingBroadcaster->shouldReceive('broadcast')
            ->once()
            ->andThrow(new RuntimeException('simulated broadcaster failure'));
        $this->mockBroadcastManager($failingBroadcaster);

        try {
            (new DispatchDomainEventsJob($event->id))->handle();
            $this->fail('Expected RuntimeException was not thrown.');
        } catch (RuntimeException $e) {
            $this->assertSame('simulated broadcaster failure', $e->getMessage());
        }

        $event->refresh();
        $this->assertNull($event->dispatched_at, 'Claim must be released so retry can re-attempt.');
        $this->assertStringContainsString('simulated broadcaster failure', (string) $event->last_error);
        $this->assertSame(1, (int) $event->attempts);

        // Subsequent attempt with a healthy broadcaster MUST succeed.
        $okBroadcaster = Mockery::mock(Broadcaster::class);
        $okBroadcaster->shouldReceive('broadcast')->once();
        $this->mockBroadcastManager($okBroadcaster);

        (new DispatchDomainEventsJob($event->id))->handle();

        $event->refresh();
        $this->assertNotNull($event->dispatched_at);
        $this->assertNull($event->last_error);
        $this->assertSame(2, (int) $event->attempts);
    }

    public function test_payload_contract_violation_releases_claim_and_writes_last_error(): void
    {
        // Payload missing the required `order_id` key for ORDER_CREATED.
        $event = $this->makeDomainEvent([
            'payload' => ['malformed' => true],
        ]);

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldNotReceive('broadcast');
        $this->mockBroadcastManager($broadcaster);

        $this->expectException(PayloadMismatchException::class);

        try {
            (new DispatchDomainEventsJob($event->id))->handle();
        } finally {
            $event->refresh();
            $this->assertNull($event->dispatched_at, 'Claim must be released on contract violation.');
            $this->assertNotEmpty($event->last_error);
            $this->assertStringContainsString('contract_violation', (string) $event->last_error);
            $this->assertSame(1, (int) $event->attempts);
        }
    }

    public function test_unrelated_domain_events_do_not_block_each_other(): void
    {
        $eventA = $this->makeDomainEvent(['correlation_id' => 'corr-a-' . uniqid('', true)]);
        $eventB = $this->makeDomainEvent(['correlation_id' => 'corr-b-' . uniqid('', true)]);

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')->twice();
        $this->mockBroadcastManager($broadcaster);

        (new DispatchDomainEventsJob($eventA->id))->handle();
        (new DispatchDomainEventsJob($eventB->id))->handle();

        $this->assertNotNull($eventA->fresh()->dispatched_at);
        $this->assertNotNull($eventB->fresh()->dispatched_at);
    }

    public function test_skip_does_not_increment_attempts_or_clear_last_error(): void
    {
        $event = $this->makeDomainEvent();
        $event->forceFill([
            'dispatched_at' => now(),
            'attempts' => 3,
            'last_error' => 'previous failure detail',
        ])->save();

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldNotReceive('broadcast');
        $this->mockBroadcastManager($broadcaster);

        (new DispatchDomainEventsJob($event->id))->handle();

        $event->refresh();
        $this->assertSame(3, (int) $event->attempts, 'Skip path must not bump attempts.');
        $this->assertSame('previous failure detail', $event->last_error, 'Skip path must not clear last_error.');
    }

    /**
     * [Audit G1 / T-MISS-01] failed() must preserve the `contract_violation:`
     * prefix for PayloadMismatchException so monitoring filters do not lose
     * terminal contract failures after `tries` exhaustion.
     */
    public function test_failed_callback_preserves_contract_violation_prefix_for_payload_mismatch(): void
    {
        $event = $this->makeDomainEvent();
        $exception = new PayloadMismatchException(
            'envelope missing required key',
            ['missing:order_id'],
            EventType::ORDER_CREATED
        );

        (new DispatchDomainEventsJob($event->id))->failed($exception);

        $event->refresh();
        $this->assertNotNull($event->last_error);
        $this->assertStringStartsWith('contract_violation:', (string) $event->last_error);
        $this->assertStringContainsString('envelope missing required key', (string) $event->last_error);
    }

    /**
     * [Audit G1 / T-MISS-01 — sibling] failed() with a non-contract exception
     * stores the raw message (no synthetic prefix) — preserves backward
     * compatibility with existing log scanners.
     */
    public function test_failed_callback_stores_raw_message_for_generic_exceptions(): void
    {
        $event = $this->makeDomainEvent();
        $exception = new RuntimeException('pusher_unreachable');

        (new DispatchDomainEventsJob($event->id))->failed($exception);

        $event->refresh();
        $this->assertSame('pusher_unreachable', (string) $event->last_error);
    }

    /**
     * [T-MISS-03] Events with channel == null OR broadcast_as == null skip the
     * broadcast step entirely (legacy persisted-only events) but still finalise
     * the dispatched_at timestamp so they are not retried indefinitely.
     */
    public function test_event_with_null_channel_marks_dispatched_without_broadcast(): void
    {
        $event = $this->makeDomainEvent([
            'channel' => null,
            'broadcast_as' => null,
        ]);

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldNotReceive('broadcast');
        $this->mockBroadcastManager($broadcaster);

        (new DispatchDomainEventsJob($event->id))->handle();

        $event->refresh();
        $this->assertNotNull($event->dispatched_at);
        $this->assertNull($event->last_error);
        $this->assertSame(1, (int) $event->attempts);
    }

    private function makeDomainEvent(array $overrides = []): DomainEvent
    {
        $defaults = [
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 123,
            'branch_id' => 1,
            'payload' => $this->orderCreatedPayload(123),
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'corr-' . uniqid('', true),
            'occurred_at' => now(),
            'attempts' => 0,
            'last_error' => null,
            'dispatched_at' => null,
        ];

        return DomainEvent::query()->create(array_merge($defaults, $overrides));
    }

    /**
     * @return array<string, mixed>
     */
    private function orderCreatedPayload(int $orderId): array
    {
        return [
            'order_id' => $orderId,
            'queue_number' => 'Q-' . $orderId,
            '_origin' => 'pos',
            'payment_method' => 'cash',
        ];
    }

    private function mockBroadcastManager(Broadcaster $broadcaster): void
    {
        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->andReturn($broadcaster);
        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
