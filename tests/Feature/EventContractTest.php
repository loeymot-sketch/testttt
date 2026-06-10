<?php

namespace Tests\Feature;

use App\Domain\Events\EventContract;
use App\Enums\EventType;
use App\Enums\OrderStatus;
use App\Enums\PaymentGateway;
use App\Enums\PosPaymentMethod;
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
            'order.payment_confirmed',
            'order.item_added',
            'order.cancelled',
            'order.table_changed',
            'menu.item_availability_changed',
            // [F-016a-BIS] Dedicated extra/variation availability events.
            'menu.extra_availability_changed',
            'menu.variation_availability_changed',
            'catalog.changed',
            // [GOAL LOYALTY_UNIFIED_SYNC L2 2026-06-11 — heal audit petits-systemes]
            // Le commit L2 (e784f9353) a déclaré le const + dispatché l'event mais a
            // oublié all() ET cette liste canonique : chaque envelope
            // loyalty.balance_changed était rejetée par EventContract::validate().
            'loyalty.balance_changed',
            'stock.low',
            // [PROMO-DASH-2026-05-06] cycle-6 Dashboard promo broadcast
            'promo.coupon_changed',
            // [P13 — F-VERIFY-09-01 / F-VERIFY-09-10] payment_status transitions.
            'order.payment_status_changed',
            // [Wave 5G R9 heal 2026-05-17] Admin settings live-propagation
            'settings.updated',
            // [Wave 5G R10 heal 2026-05-17] Branch deactivation token revoke
            'branch.status_changed',
            // [GOAL-2026-05-29] KDS "Annuler bump" recall compensating action. The
            // EventType enum has carried kds.order_recalled since the Heal-5 recall
            // feature, but this canonical contract list was never updated — leaving
            // the V1 event-contract sentinel RED (surfaced by the validation-state
            // assessment, NOT by the 51-agent campaign nor the capstone).
            'kds.order_recalled',
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
            'payload' => [
                'order_id' => 123,
                'queue_number' => 'A0123',
                '_origin' => 'kiosk',
                'payment_method' => PaymentGateway::CARD,
            ],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => $correlationId,
            'occurred_at' => $occurredAt,
        ]);

        // [T09b] Standard Laravel Broadcaster::broadcast() contract.
        $connection = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $connection->shouldReceive('broadcast')
            ->once()
            ->with(['private-branch.1'], 'OrderCreated', Mockery::on(function (array $data) use ($occurredAt, $correlationId): bool {
                $this->assertSame(1, $data['version']);
                $this->assertSame(EventType::ORDER_CREATED, $data['type']);
                $this->assertSame(123, $data['aggregate_id']);
                $this->assertSame(1, $data['branch_id']);
                $this->assertSame($occurredAt->toIso8601String(), $data['occurred_at']);
                $this->assertSame($correlationId, $data['correlation_id']);
                $this->assertSame([
                    'order_id' => 123,
                    'queue_number' => 'A0123',
                    '_origin' => 'kiosk',
                    'payment_method' => PaymentGateway::CARD,
                ], $data['payload']);

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

        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')
            ->once()
            ->withNoArgs()
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

    public function test_order_realtime_payload_contract_requires_origin_payment_method_and_queue(): void
    {
        $this->assertContains('_origin', EventContract::REQUIRED_PAYLOAD_KEYS[EventType::ORDER_CREATED]);
        $this->assertContains('payment_method', EventContract::REQUIRED_PAYLOAD_KEYS[EventType::ORDER_CREATED]);
        $this->assertContains('queue_number', EventContract::REQUIRED_PAYLOAD_KEYS[EventType::ORDER_CREATED]);

        $this->assertContains('_origin', EventContract::REQUIRED_PAYLOAD_KEYS[EventType::ORDER_STATUS_CHANGED]);
        $this->assertContains('payment_method', EventContract::REQUIRED_PAYLOAD_KEYS[EventType::ORDER_STATUS_CHANGED]);
        $this->assertContains('queue_number', EventContract::REQUIRED_PAYLOAD_KEYS[EventType::ORDER_STATUS_CHANGED]);
    }

    public function test_order_created_listener_persists_realtime_identity_payload(): void
    {
        Queue::fake();

        $order = $this->createOrder([
            'source_surface' => 'kiosk',
            'payment_method' => PaymentGateway::CARD,
            'queue_number' => 'A0009',
        ]);

        OrderCreated::dispatch($order);

        $payload = DomainEvent::query()->latest('id')->firstOrFail()->payload;

        $this->assertSame('A0009', $payload['queue_number']);
        $this->assertSame('kiosk', $payload['_origin']);
        $this->assertSame(PaymentGateway::CARD, $payload['payment_method']);
    }

    public function test_order_status_changed_listener_persists_realtime_identity_payload(): void
    {
        Queue::fake();

        $order = $this->createOrder([
            'source_surface' => 'pos',
            'pos_payment_method' => PosPaymentMethod::CASH,
            'queue_number' => 'A0010',
            'status' => OrderStatus::ACCEPT,
        ]);

        OrderStatusChanged::dispatch($order, OrderStatus::ACCEPT, OrderStatus::PREPARING);

        $payload = DomainEvent::query()->latest('id')->firstOrFail()->payload;

        $this->assertSame('A0010', $payload['queue_number']);
        $this->assertSame('pos', $payload['_origin']);
        $this->assertSame(PosPaymentMethod::CASH, $payload['payment_method']);
    }

    public function test_dispatch_job_rejects_envelope_that_violates_contract(): void
    {
        // Build a corrupt row: ORDER_STATUS_CHANGED without the required new_status key.
        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_STATUS_CHANGED,
            'aggregate_type' => Order::class,
            'aggregate_id' => 999,
            'branch_id' => 1,
            'payload' => [
                'order_id' => 999,
                'queue_number' => 'A0999',
                '_origin' => 'kiosk',
                'payment_method' => PaymentGateway::CARD,
                'old_status' => 2,
            ],
            'channel' => json_encode(['private-branch.1']),
            'broadcast_as' => 'OrderStatusChanged',
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);

        // [T09b] Broadcaster::broadcast() must NOT be called when envelope invalid.
        $connection = Mockery::mock(\Illuminate\Contracts\Broadcasting\Broadcaster::class);
        $connection->shouldNotReceive('broadcast');

        $manager = Mockery::mock(BroadcastManager::class);
        $manager->shouldReceive('connection')->zeroOrMoreTimes()->withNoArgs()->andReturn($connection);

        $this->app->instance(BroadcastManager::class, $manager);
        $this->app->instance('broadcast.manager', $manager);

        // [F-3 SYNC P1 V1.0.1 update — 2026-05-19]
        // PayloadMismatchException behaviour changed from "rethrow" to
        // "$this->fail($e) then return" (commit 5452e556d). Contract
        // violations are NOT retry-recoverable so the job is routed
        // directly to failed_jobs via $this->fail() instead of consuming
        // the 6-attempt $backoff curve. The exception-detail assertions
        // below (eventType + errors) were originally checked on the caught
        // exception; we now drive them through the EventContract directly
        // (single-source-of-truth — the very assertion the job invokes).
        // Source: reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md §P1
        // Companion sentinel: tests/Feature/Sentinels/PayloadMismatchFailOnceSentinelTest.php

        $threw = false;
        try {
            (new DispatchDomainEventsJob($domainEvent->id))->handle();
        } catch (PayloadMismatchException $exception) {
            $threw = true;
        }

        $this->assertFalse(
            $threw,
            'DispatchDomainEventsJob::handle MUST NOT rethrow PayloadMismatchException — '
                . '$this->fail($e) short-circuits the retry curve. See '
                . 'reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md §P1.'
        );

        // Drive the contract assertion directly so the exception detail
        // pins are preserved (eventType, errors). This is what the job
        // itself calls.
        try {
            \App\Domain\Events\EventContract::assertEnvelopeValid(
                \App\Domain\Events\EventContract::buildEnvelope($domainEvent->fresh()),
                $domainEvent->event_type
            );
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
