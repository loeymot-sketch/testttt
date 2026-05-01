<?php

namespace Tests\Feature;

use App\Events\Concerns\DispatchableAfterCommit;
use App\Events\ItemAvailabilityChanged;
use App\Events\OrderCreated;
use App\Events\OrderPaidAtCounter;
use App\Events\OrderStatusChanged;
use App\Events\OrderTableChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Listeners\PersistOrderCreatedToOutbox;
use App\Listeners\PersistOrderPaidAtCounterToOutbox;
use App\Listeners\PersistOrderStatusChangedToOutbox;
use App\Providers\EventServiceProvider;
use App\Services\OrderService;
use ReflectionClass;
use Tests\TestCase;

class AfterCommitDispatchTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string}>
     */
    public static function broadcastOutboxEventsProvider(): array
    {
        return [
            'OrderCreated' => [OrderCreated::class],
            'OrderStatusChanged' => [OrderStatusChanged::class],
            'OrderPaidAtCounter' => [OrderPaidAtCounter::class],
            'ItemAvailabilityChanged' => [ItemAvailabilityChanged::class],
            'OrderTableChanged' => [OrderTableChanged::class],
        ];
    }

    /**
     * @dataProvider broadcastOutboxEventsProvider
     */
    public function test_outbox_broadcast_events_use_after_commit_dispatch_trait(string $eventClass): void
    {
        $uses = class_uses_recursive($eventClass);

        $this->assertContains(
            DispatchableAfterCommit::class,
            $uses,
            $eventClass.' must keep the dispatch-after-commit invariant.'
        );
    }

    /**
     * @return array<string, array{0: class-string, 1: class-string}>
     */
    public static function orderOutboxListenerProvider(): array
    {
        return [
            'OrderCreated' => [OrderCreated::class, PersistOrderCreatedToOutbox::class],
            'OrderStatusChanged' => [OrderStatusChanged::class, PersistOrderStatusChangedToOutbox::class],
            'OrderPaidAtCounter' => [OrderPaidAtCounter::class, PersistOrderPaidAtCounterToOutbox::class],
        ];
    }

    /**
     * @dataProvider orderOutboxListenerProvider
     */
    public function test_order_events_are_registered_to_outbox_listeners(string $eventClass, string $listenerClass): void
    {
        $provider = new EventServiceProvider($this->app);
        $reflection = new ReflectionClass(EventServiceProvider::class);
        $listen = $reflection->getProperty('listen');
        $listen->setAccessible(true);

        $registered = $listen->getValue($provider);

        $this->assertContains(
            $listenerClass,
            $registered[$eventClass] ?? [],
            $eventClass.' must stay wired to '.$listenerClass
        );
    }

    /**
     * @return array<string, array{0: class-string, 1: string, 2: string}>
     */
    public static function orderOutboxMappingProvider(): array
    {
        return [
            'OrderCreated' => [
                PersistOrderCreatedToOutbox::class,
                'EventType::ORDER_CREATED',
                "'broadcast_as' => 'OrderCreated'",
            ],
            'OrderStatusChanged' => [
                PersistOrderStatusChangedToOutbox::class,
                'EventType::ORDER_STATUS_CHANGED',
                "'broadcast_as' => 'OrderStatusChanged'",
            ],
            'OrderPaidAtCounter' => [
                PersistOrderPaidAtCounterToOutbox::class,
                'EventType::ORDER_PAYMENT_CONFIRMED',
                "'broadcast_as' => 'OrderPaidAtCounter'",
            ],
        ];
    }

    /**
     * @dataProvider orderOutboxMappingProvider
     */
    public function test_order_outbox_listeners_persist_branch_channel_and_dispatch_job_after_commit(
        string $listenerClass,
        string $eventTypeConstant,
        string $broadcastAsSnippet
    ): void {
        $source = file_get_contents((new ReflectionClass($listenerClass))->getFileName());

        $this->assertStringContainsString($eventTypeConstant, $source);
        $this->assertStringContainsString("'branch_id' => \$order->branch_id", $source);
        $this->assertStringContainsString("'channel' => json_encode(['private-branch.' . \$order->branch_id])", $source);
        $this->assertStringContainsString($broadcastAsSnippet, $source);
        $this->assertStringContainsString('DB::afterCommit(function () use ($domainEvent): void {', $source);
        $this->assertStringContainsString('DispatchDomainEventsJob::dispatch($domainEvent->id);', $source);
    }

    public function test_order_event_outbox_channel_map_doc_exists(): void
    {
        $path = base_path('docs/orchestration/ORDER_EVENT_OUTBOX_CHANNEL_MAP_2026-04-26.md');
        $source = file_get_contents($path);

        $this->assertFileExists($path);
        $this->assertStringContainsString('App\Events\OrderCreated', $source);
        $this->assertStringContainsString('App\Events\OrderStatusChanged', $source);
        $this->assertStringContainsString('App\Events\OrderPaidAtCounter', $source);
        $this->assertStringContainsString('private-branch.{branch_id}', $source);
        $this->assertStringContainsString('DB::afterCommit()', $source);
    }

    public function test_order_service_uses_dispatchable_order_events_not_direct_event_instances(): void
    {
        $source = file_get_contents((new ReflectionClass(OrderService::class))->getFileName());

        $this->assertMatchesRegularExpression('/(?:\\\\App\\\\Events\\\\)?OrderCreated::dispatch\\(/', $source);
        $this->assertMatchesRegularExpression('/(?:\\\\App\\\\Events\\\\)?OrderStatusChanged::dispatch\\(/', $source);
        $this->assertDoesNotMatchRegularExpression('/event\\s*\\(\\s*new\\s+(?:\\\\?App\\\\Events\\\\)?Order(?:Created|StatusChanged)\\b/', $source);
        $this->assertDoesNotMatchRegularExpression('/broadcast\\s*\\(\\s*new\\s+(?:\\\\?App\\\\Events\\\\)?Order(?:Created|StatusChanged)\\b/', $source);
    }

    public function test_dispatch_domain_events_job_claims_row_before_broadcast_for_dedupe(): void
    {
        $source = file_get_contents((new ReflectionClass(DispatchDomainEventsJob::class))->getFileName());
        $claimIndex = strpos($source, "'dispatched_at' => now()");
        $broadcastIndex = strpos($source, '$broadcaster->broadcast(');

        $this->assertStringContainsString('lockForUpdate()', $source);
        $this->assertStringContainsString('if ($domainEvent->dispatched_at !== null)', $source);
        $this->assertNotFalse($claimIndex, 'DispatchDomainEventsJob must claim dispatched_at before broadcast.');
        $this->assertNotFalse($broadcastIndex, 'DispatchDomainEventsJob must still broadcast claimed rows.');
        $this->assertLessThan(
            $broadcastIndex,
            $claimIndex,
            'Outbox row claim must happen before broadcasting to dedupe concurrent workers.'
        );
    }
}
