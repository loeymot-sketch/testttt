<?php

namespace Tests\Feature;

use App\Enums\EventType;
use App\Enums\OrderStatus;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Exceptions\PayloadMismatchException;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Models\Order;
use App\Models\User;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Mockery;
use Tests\TestCase;

class EventContractTest extends TestCase
{
    use RefreshDatabase;

    public function test_event_type_enum_contains_all_v1_types(): void
    {
        $expected = [
            'order.created',
            'order.status_changed',
            'order.item_added',
            'order.cancelled',
            'menu.item_availability_changed',
            'stock.low',
        ];
        $actual = EventType::all();

        sort($expected);
        sort($actual);

        $this->assertSame($expected, $actual);
    }

    public function test_order_created_listener_uses_event_type_constant(): void
    {
        Queue::fake();

        $order = $this->createOrder();

        OrderCreated::dispatch($order);

        $domainEvent = DomainEvent::query()->latest('id')->firstOrFail();

        $this->assertSame(EventType::ORDER_CREATED, $domainEvent->event_type);
    }

    public function test_order_status_changed_listener_uses_event_type_constant(): void
    {
        Queue::fake();

        $order = $this->createOrder([
            'status' => OrderStatus::ACCEPT,
        ]);

        OrderStatusChanged::dispatch($order, OrderStatus::ACCEPT, OrderStatus::PREPARING);

        $domainEvent = DomainEvent::query()->latest('id')->firstOrFail();

        $this->assertSame(EventType::ORDER_STATUS_CHANGED, $domainEvent->event_type);
    }

    public function test_dispatch_job_broadcasts_canonical_envelope(): void
    {
        $occurredAt = now()->startOfSecond();
        $correlationId = (string) Str::uuid();

        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 123,
            'branch_id' => 1,
            'payload' => ['order_id' => 123],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => $correlationId,
            'occurred_at' => $occurredAt,
        ]);

        $pusher = Mockery::mock();
        $pusher->shouldReceive('trigger')
            ->once()
            ->with(['private-branch.1'], 'OrderCreated', Mockery::on(function (array $data) use ($occurredAt, $correlationId): bool {
                $this->assertSame(1, $data['version']);
                $this->assertSame(EventType::ORDER_CREATED, $data['type']);
                $this->assertSame(123, $data['aggregate_id']);
                $this->assertSame(1, $data['branch_id']);
                $this->assertSame($occurredAt->toIso8601String(), $data['occurred_at']);
                $this->assertSame($correlationId, $data['correlation_id']);
                $this->assertSame(['order_id' => 123], $data['payload']);

                return array_keys($data) === [
                    'version',
                    'type',
                    'aggregate_id',
                    'branch_id',
                    'occurred_at',
                    'correlation_id',
                    'payload',
                ];
            }));

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
    }

    public function test_domain_event_has_correlation_id(): void
    {
        Queue::fake();

        $order = $this->createOrder();

        OrderCreated::dispatch($order);

        $domainEvent = DomainEvent::query()->latest('id')->firstOrFail();

        $this->assertNotNull($domainEvent->correlation_id);
        $this->assertTrue(Str::isUuid($domainEvent->correlation_id));
    }

    public function test_dispatch_job_rejects_envelope_that_violates_contract(): void
    {
        // Build a corrupt row: ORDER_STATUS_CHANGED without the required new_status key.
        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_STATUS_CHANGED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 999,
            'branch_id' => 1,
            'payload' => ['order_id' => 999, 'old_status' => 2],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderStatusChanged',
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);

        // Pusher must NOT be called when the envelope is invalid.
        $pusher = Mockery::mock();
        $pusher->shouldNotReceive('trigger');

        $connection = Mockery::mock();
        $connection->shouldReceive('getPusher')->zeroOrMoreTimes()->andReturn($pusher);

        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->zeroOrMoreTimes()->with('pusher')->andReturn($connection);

        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);

        try {
            (new DispatchDomainEventsJob($domainEvent->id))->handle();
            $this->fail('Expected PayloadMismatchException for invalid envelope.');
        } catch (PayloadMismatchException $exception) {
            $this->assertSame(EventType::ORDER_STATUS_CHANGED, $exception->eventType);
            $this->assertNotEmpty($exception->errors);
        }

        $domainEvent->refresh();

        // The row must NOT be marked dispatched — the attempt is counted,
        // and the error must be persisted for ops visibility.
        $this->assertNull($domainEvent->dispatched_at);
        $this->assertStringContainsString('contract_violation', (string) $domainEvent->last_error);
        $this->assertSame(1, $domainEvent->attempts);
    }

    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    private function createOrder(array $overrides = []): Order
    {
        $branch = Branch::factory()->create();
        $user = User::factory()->create([
            'branch_id' => $branch->id,
        ]);

        return Order::factory()->create(array_merge([
            'user_id' => $user->id,
            'branch_id' => $branch->id,
            'queue_number' => 'Q-100',
            'status' => OrderStatus::PENDING,
            'order_type' => 1,
            'total' => 19.90,
        ], $overrides));
    }
}
