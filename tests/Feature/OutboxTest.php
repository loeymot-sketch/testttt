<?php

namespace Tests\Feature;

use App\Enums\EventType;
use App\Events\OrderCreated;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Mockery;
use Tests\TestCase;

class OutboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_created_persists_domain_event(): void
    {
        Queue::fake();

        $order = $this->createOrder();

        OrderCreated::dispatch($order);

        $this->assertDatabaseCount('domain_events', 1);

        $domainEvent = DomainEvent::query()->firstOrFail();

        $this->assertSame(EventType::ORDER_CREATED, $domainEvent->event_type);
        $this->assertSame(Order::class, $domainEvent->aggregate_type);
        $this->assertSame($order->id, $domainEvent->aggregate_id);
        $this->assertSame($order->id, $domainEvent->payload['order_id']);
        $this->assertSame($order->queue_number, $domainEvent->payload['queue_number']);
    }

    public function test_domain_event_not_persisted_on_rollback(): void
    {
        Queue::fake();

        $order = $this->createOrder();

        DB::beginTransaction();

        try {
            OrderCreated::dispatch($order);

            $this->assertDatabaseCount('domain_events', 1);
        } finally {
            DB::rollBack();
        }

        $this->assertDatabaseCount('domain_events', 0);
    }

    public function test_dispatch_job_marks_event_dispatched(): void
    {
        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 123,
            'branch_id' => 1,
            'payload' => ['order_id' => 123],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => 'test-uuid-1234',
            'occurred_at' => now(),
        ]);

        $pusher = Mockery::mock();
        $pusher->shouldReceive('trigger')
            ->once()
            ->withArgs(function ($channels, $eventName, $data) {
                return $channels === ['private-branch.1']
                    && $eventName === 'OrderCreated'
                    && $data['version'] === 1
                    && $data['type'] === EventType::ORDER_CREATED
                    && $data['aggregate_id'] === 123
                    && $data['payload'] === ['order_id' => 123];
            });

        $connection = Mockery::mock();
        $connection->shouldReceive('getPusher')
            ->once()
            ->andReturn($pusher);

        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')
            ->once()
            ->with('pusher')
            ->andReturn($connection);

        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);

        (new DispatchDomainEventsJob($domainEvent->id))->handle();

        $domainEvent->refresh();

        $this->assertNotNull($domainEvent->dispatched_at);
        $this->assertSame(1, $domainEvent->attempts);
    }

    public function test_rescue_command_requeues_stale_events(): void
    {
        Bus::fake();

        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 321,
            'branch_id' => 1,
            'payload' => ['order_id' => 321],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'occurred_at' => now()->subMinutes(3),
            'attempts' => 1,
        ]);
        $domainEvent->forceFill([
            'created_at' => now()->subMinutes(3),
            'updated_at' => now()->subMinutes(3),
        ])->save();

        $this->artisan('foodking:outbox:rescue')
            ->expectsOutput('Re-queued 1 stale domain events.')
            ->assertExitCode(0);

        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($domainEvent): bool {
            return $job->domainEventId === $domainEvent->id;
        });
    }

    public function test_retry_failed_resets_and_requeues(): void
    {
        Bus::fake();

        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 654,
            'branch_id' => 1,
            'payload' => ['order_id' => 654],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'occurred_at' => now()->subMinutes(10),
            'attempts' => 5,
            'last_error' => 'Pusher timeout',
        ]);
        $domainEvent->forceFill([
            'created_at' => now()->subMinutes(10),
            'updated_at' => now()->subMinutes(10),
        ])->save();

        $this->artisan('foodking:outbox:retry-failed', ['--since' => '2h'])
            ->expectsOutput('Reset and re-queued 1 failed domain events.')
            ->assertExitCode(0);

        $domainEvent->refresh();

        $this->assertSame(0, $domainEvent->attempts);
        $this->assertNull($domainEvent->last_error);
        $this->assertNull($domainEvent->dispatched_at);

        Bus::assertDispatched(DispatchDomainEventsJob::class, function (DispatchDomainEventsJob $job) use ($domainEvent): bool {
            return $job->domainEventId === $domainEvent->id;
        });
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function createOrder(): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'branch_id' => $branch->id,
        ]);

        return Order::factory()->create([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'queue_number' => 'Q-100',
            'status' => \App\Enums\OrderStatus::PENDING,
            'order_type' => 1,
            'total' => 19.90,
        ]);
    }
}
