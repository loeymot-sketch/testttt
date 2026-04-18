<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\OrderCreated;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PersistOrderCreatedToOutbox
{
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_CREATED,
            'aggregate_type' => get_class($order),
            'aggregate_id' => $order->id,
            'branch_id' => $order->branch_id,
            'payload' => [
                'order_id' => $order->id,
                'queue_number' => $order->queue_number,
                'status' => $order->status,
                'order_type' => $order->order_type,
                'total' => $order->total,
                'created_at' => $order->created_at?->toISOString(),
            ],
            'channel' => json_encode(['private-branch.' . $order->branch_id]),
            'broadcast_as' => 'OrderCreated',
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);

        DB::afterCommit(function () use ($domainEvent): void {
            DispatchDomainEventsJob::dispatch($domainEvent->id)->onQueue('high');
        });
    }
}
