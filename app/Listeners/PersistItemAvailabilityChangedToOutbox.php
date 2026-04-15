<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DomainEvent;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\DB;

class PersistItemAvailabilityChangedToOutbox
{
    public function handle(ItemAvailabilityChanged $event): void
    {
        $channels = Branch::query()
            ->where('status', Status::ACTIVE)
            ->pluck('id')
            ->map(fn (int $branchId): string => 'private-branch.' . $branchId)
            ->values()
            ->all();

        $domainEvent = DomainEvent::query()->create([
            'event_type' => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item',
            'aggregate_id' => $event->itemId,
            'branch_id' => null,
            'payload' => [
                'item_id' => $event->itemId,
                'status' => $event->status,
                'price' => $event->price,
                'type' => $event->type,
            ],
            'channel' => json_encode($channels),
            'broadcast_as' => 'ItemAvailabilityChanged',
            'correlation_id' => (string) Str::uuid(),
            'occurred_at' => now(),
        ]);

        DB::afterCommit(function () use ($domainEvent): void {
            DispatchDomainEventsJob::dispatch($domainEvent->id)->onQueue('high');
        });
    }
}
