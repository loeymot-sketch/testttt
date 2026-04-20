<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\ItemAvailabilityChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PersistItemAvailabilityChangedToOutbox
{
    public function handle(ItemAvailabilityChanged $event): void
    {
        $payload = [
            'item_id' => $event->itemId,
            'status'  => $event->status,
            'price'   => $event->price,
            'type'    => $event->type,
        ];

        if ($event->branchId !== null) {
            // Branch-scoped toggle (MENU_86) — single-branch channel, include rupture metadata.
            $payload['branch_id']    = $event->branchId;
            $payload['is_available'] = $event->isAvailable;
            $payload['reason']       = $event->reason;
            $channels = ['private-branch.' . $event->branchId];
        } else {
            // Global menu change (admin edits item) — broadcast to every active branch.
            $channels = Branch::query()
                ->where('status', Status::ACTIVE)
                ->pluck('id')
                ->map(fn (int $branchId): string => 'private-branch.' . $branchId)
                ->values()
                ->all();
        }

        $domainEvent = DomainEvent::query()->create([
            'event_type'     => EventType::MENU_ITEM_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item',
            'aggregate_id'   => $event->itemId,
            'branch_id'      => $event->branchId,
            'payload'        => $payload,
            'channel'        => json_encode($channels),
            'broadcast_as'   => 'ItemAvailabilityChanged',
            'correlation_id' => $this->resolveCorrelationId(),
            'occurred_at'    => now(),
        ]);

        DB::afterCommit(function () use ($domainEvent): void {
            DispatchDomainEventsJob::dispatch($domainEvent->id)->onQueue('high');
        });
    }

    private function resolveCorrelationId(): string
    {
        $sharedContext = Log::sharedContext();
        $sharedCorrelationId = is_array($sharedContext) ? ($sharedContext['correlation_id'] ?? null) : null;

        if (is_string($sharedCorrelationId) && trim($sharedCorrelationId) !== '') {
            return $sharedCorrelationId;
        }

        $requestCorrelationId = request()?->header('X-Correlation-ID');

        if (is_string($requestCorrelationId) && trim($requestCorrelationId) !== '') {
            return $requestCorrelationId;
        }

        return (string) Str::uuid();
    }
}
