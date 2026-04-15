<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\OrderStatusChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PersistOrderStatusChangedToOutbox
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;

        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::ORDER_STATUS_CHANGED,
            'aggregate_type' => get_class($order),
            'aggregate_id' => $order->id,
            'branch_id' => $order->branch_id,
            'payload' => [
                'order_id' => $order->id,
                'queue_number' => $order->queue_number,
                'old_status' => $event->oldStatus,
                'new_status' => $event->newStatus,
                'token' => $order->token ?? null,
            ],
            'channel' => json_encode(['private-branch.' . $order->branch_id]),
            'broadcast_as' => 'OrderStatusChanged',
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);

        DB::afterCommit(function () use ($domainEvent): void {
            DispatchDomainEventsJob::dispatch($domainEvent->id)->onQueue('high');
        });
    }
}
