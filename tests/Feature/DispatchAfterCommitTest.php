<?php

namespace Tests\Feature;

use App\Events\ItemAvailabilityChanged;
use App\Events\OrderCreated;
use App\Events\OrderStatusChanged;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class DispatchAfterCommitTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{0: class-string, 1: callable(): array<int, mixed>}>
     */
    public static function broadcastEventsProvider(): array
    {
        return [
            'OrderCreated' => [
                OrderCreated::class,
                static fn (): array => [(new Order())->fill(['id' => 999999])],
            ],
            'OrderStatusChanged' => [
                OrderStatusChanged::class,
                static fn (): array => [(new Order())->fill(['id' => 999999]), 1, 2],
            ],
            'ItemAvailabilityChanged' => [
                ItemAvailabilityChanged::class,
                static fn (): array => [999999, 1, 9.99],
            ],
        ];
    }

    /**
     * @dataProvider broadcastEventsProvider
     *
     * @group dispatch_after_commit_invariant
     */
    public function test_event_is_not_dispatched_if_transaction_rolls_back(string $eventClass, callable $dispatchArgsFactory): void
    {
        Event::fake([$eventClass]);

        try {
            DB::transaction(function () use ($eventClass, $dispatchArgsFactory) {
                $eventClass::dispatch(...$dispatchArgsFactory());
                throw new \RuntimeException('forced rollback');
            });
        } catch (\RuntimeException $e) {
            // expected
        }

        // Laravel Event::assertNotDispatched second parameter is a filter callback, not a custom message.
        Event::assertNotDispatched($eventClass);
    }

    /**
     * @dataProvider broadcastEventsProvider
     *
     * @group dispatch_after_commit_invariant
     */
    public function test_event_is_dispatched_after_successful_commit(string $eventClass, callable $dispatchArgsFactory): void
    {
        Event::fake([$eventClass]);

        DB::transaction(function () use ($eventClass, $dispatchArgsFactory) {
            $eventClass::dispatch(...$dispatchArgsFactory());
        });

        Event::assertDispatched($eventClass);
    }
}
