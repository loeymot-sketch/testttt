<?php

namespace Tests\Feature\Outbox;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\CategoryUpdated;
use App\Exceptions\PayloadMismatchException;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Contracts\Broadcasting\Broadcaster;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class OutboxProductionLikeSimulationTest extends TestCase
{
    use RefreshDatabase;

    public function test_rescue_requeues_only_stale_retriable_pending_events(): void
    {
        Queue::fake();

        $retriable = $this->domainEvent([
            'aggregate_id' => 101,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
            'attempts' => 4,
        ]);
        $fresh = $this->domainEvent([
            'aggregate_id' => 102,
            'created_at' => now(),
            'updated_at' => now(),
            'attempts' => 0,
        ]);
        $maxed = $this->domainEvent([
            'aggregate_id' => 103,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
            'attempts' => 5,
            'last_error' => 'provider timeout',
        ]);
        $dispatched = $this->domainEvent([
            'aggregate_id' => 104,
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
            'attempts' => 1,
            'dispatched_at' => now()->subMinute(),
        ]);

        $this->artisan('foodking:outbox:rescue')
            ->expectsOutput('Re-queued 1 stale domain events.')
            ->assertExitCode(0);

        Queue::assertPushed(DispatchDomainEventsJob::class, 1);
        Queue::assertPushed(DispatchDomainEventsJob::class, fn (DispatchDomainEventsJob $job): bool => $job->domainEventId === $retriable->id);
        Queue::assertNotPushed(DispatchDomainEventsJob::class, fn (DispatchDomainEventsJob $job): bool => in_array($job->domainEventId, [$fresh->id, $maxed->id, $dispatched->id], true));
    }

    public function test_retry_failed_resets_only_recent_failed_events(): void
    {
        Queue::fake();

        $recentFailed = $this->domainEvent([
            'aggregate_id' => 201,
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
            'attempts' => 5,
            'last_error' => 'pusher down',
        ]);
        $oldFailed = $this->domainEvent([
            'aggregate_id' => 202,
            'created_at' => now()->subHours(4),
            'updated_at' => now()->subHours(4),
            'attempts' => 5,
            'last_error' => 'old pusher down',
        ]);
        $notTerminal = $this->domainEvent([
            'aggregate_id' => 203,
            'created_at' => now()->subMinutes(15),
            'updated_at' => now()->subMinutes(15),
            'attempts' => 4,
            'last_error' => 'temporary pusher down',
        ]);

        $this->artisan('foodking:outbox:retry-failed', ['--since' => '1h'])
            ->expectsOutput('Reset and re-queued 1 failed domain events.')
            ->assertExitCode(0);

        $recentFailed->refresh();
        $oldFailed->refresh();
        $notTerminal->refresh();

        $this->assertSame(0, (int) $recentFailed->attempts);
        $this->assertNull($recentFailed->last_error);
        $this->assertNull($recentFailed->dispatched_at);

        $this->assertSame(5, (int) $oldFailed->attempts);
        $this->assertSame('old pusher down', $oldFailed->last_error);
        $this->assertSame(4, (int) $notTerminal->attempts);
        $this->assertSame('temporary pusher down', $notTerminal->last_error);

        Queue::assertPushed(DispatchDomainEventsJob::class, 1);
        Queue::assertPushed(DispatchDomainEventsJob::class, fn (DispatchDomainEventsJob $job): bool => $job->domainEventId === $recentFailed->id);
    }

    public function test_broadcast_failure_releases_claim_and_rescue_can_requeue_for_successful_retry(): void
    {
        $event = $this->domainEvent([
            'aggregate_id' => 301,
            'attempts' => 0,
        ]);

        $failingBroadcaster = Mockery::mock(Broadcaster::class);
        $failingBroadcaster->shouldReceive('broadcast')
            ->once()
            ->andThrow(new RuntimeException('provider restart'));
        $this->mockBroadcastManager($failingBroadcaster);

        try {
            (new DispatchDomainEventsJob($event->id))->handle();
            $this->fail('Expected provider restart exception.');
        } catch (RuntimeException $exception) {
            $this->assertSame('provider restart', $exception->getMessage());
        }

        $event->refresh();
        $this->assertNull($event->dispatched_at);
        $this->assertSame(1, (int) $event->attempts);
        $this->assertSame('provider restart', $event->last_error);

        $event->forceFill([
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ])->save();

        Queue::fake();

        $this->artisan('foodking:outbox:rescue')
            ->expectsOutput('Re-queued 1 stale domain events.')
            ->assertExitCode(0);

        Queue::assertPushed(DispatchDomainEventsJob::class, fn (DispatchDomainEventsJob $job): bool => $job->domainEventId === $event->id);

        $okBroadcaster = Mockery::mock(Broadcaster::class);
        $okBroadcaster->shouldReceive('broadcast')->once();
        $this->mockBroadcastManager($okBroadcaster);

        (new DispatchDomainEventsJob($event->id))->handle();

        $event->refresh();
        $this->assertNotNull($event->dispatched_at);
        $this->assertNull($event->last_error);
        $this->assertSame(2, (int) $event->attempts);
    }

    public function test_global_catalog_event_fans_out_to_active_branch_channels_with_valid_envelopes(): void
    {
        Queue::fake();

        $branchA = Branch::factory()->create(['status' => Status::ACTIVE]);
        $branchB = Branch::factory()->create(['status' => Status::ACTIVE]);
        Branch::factory()->create(['status' => 0]);

        event(new CategoryUpdated(999));

        $events = DomainEvent::query()
            ->where('event_type', EventType::CATALOG_CHANGED)
            ->orderBy('branch_id')
            ->get();

        $this->assertCount(2, $events);
        $this->assertSame([$branchA->id, $branchB->id], $events->pluck('branch_id')->all());
        $this->assertCount(1, $events->pluck('correlation_id')->unique()->all());

        $broadcasted = [];
        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldReceive('broadcast')
            ->twice()
            ->andReturnUsing(function (array $channels, string $eventName, array $envelope) use (&$broadcasted): void {
                $broadcasted[] = [$channels, $eventName, $envelope];
            });
        $this->mockBroadcastManager($broadcaster);

        foreach ($events as $event) {
            (new DispatchDomainEventsJob($event->id))->handle();
        }

        $this->assertCount(2, $broadcasted);
        foreach ($broadcasted as [$channels, $eventName, $envelope]) {
            $this->assertSame(['private-branch.' . $envelope['branch_id']], $channels);
            $this->assertSame('CatalogChanged', $eventName);
            $this->assertSame(EventType::CATALOG_CHANGED, $envelope['type']);
            $this->assertSame(999, (int) $envelope['aggregate_id']);
            $this->assertSame(999, (int) $envelope['payload']['entity_id']);
            $this->assertSame('updated', $envelope['payload']['change_type']);
            $this->assertNotEmpty($envelope['correlation_id']);
        }

        $this->assertSame(2, DomainEvent::query()->whereNotNull('dispatched_at')->count());
    }

    public function test_contract_violation_never_reaches_broadcaster_and_remains_retriable(): void
    {
        // [F-3 SYNC P1 V1.0.1 update — 2026-05-19]
        // PayloadMismatchException behaviour changed from "rethrow" to
        // "$this->fail($e) then return". The "remains retriable" wording in
        // this test name is now misleading: contract violations are NOT
        // retry-recoverable and are routed directly to failed_jobs via
        // $this->fail(). DB invariants (claim released, last_error prefix,
        // broadcaster bypassed) are unchanged. Test renamed conceptually
        // but kept name for git-blame continuity; the docblock and
        // assertions reflect the new semantics.
        //
        // Source: reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md §P1
        // Companion sentinel: tests/Feature/Sentinels/PayloadMismatchFailOnceSentinelTest.php

        $event = $this->domainEvent([
            'aggregate_id' => 401,
            'payload' => ['missing_required_keys' => true],
        ]);

        $broadcaster = Mockery::mock(Broadcaster::class);
        $broadcaster->shouldNotReceive('broadcast');
        $this->mockBroadcastManager($broadcaster);

        $threw = false;
        try {
            (new DispatchDomainEventsJob($event->id))->handle();
        } catch (PayloadMismatchException $e) {
            $threw = true;
        }

        $this->assertFalse(
            $threw,
            'PayloadMismatchException MUST NOT be rethrown — $this->fail() short-circuits the retry curve. '
                . 'See reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md §P1.'
        );

        $event->refresh();
        $this->assertNull($event->dispatched_at);
        $this->assertSame(1, (int) $event->attempts);
        $this->assertStringStartsWith('contract_violation:', (string) $event->last_error);
    }

    private function domainEvent(array $overrides = []): DomainEvent
    {
        $aggregateId = (int) ($overrides['aggregate_id'] ?? 100);
        $defaults = [
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => $aggregateId,
            'branch_id' => 1,
            'payload' => [
                'order_id' => $aggregateId,
                'queue_number' => 'Q-' . $aggregateId,
                '_origin' => 'pos',
                'payment_method' => 'cash',
            ],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'corr-' . uniqid('', true),
            'occurred_at' => now(),
            'attempts' => 0,
            'last_error' => null,
            'dispatched_at' => null,
        ];

        $timestamps = array_intersect_key($overrides, array_flip(['created_at', 'updated_at']));
        $event = DomainEvent::query()->create(array_merge($defaults, array_diff_key($overrides, $timestamps)));

        if ($timestamps !== []) {
            $event->forceFill($timestamps)->save();
        }

        return $event;
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
