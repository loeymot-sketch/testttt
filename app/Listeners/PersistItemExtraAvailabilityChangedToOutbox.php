<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\ItemExtraAvailabilityChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * [F-016a-BIS] Outbox persistence for {@see ItemExtraAvailabilityChanged}.
 *
 * Mirrors the contract used by {@see PersistItemAvailabilityChangedToOutbox}:
 * single-branch channel (since the toggle is always branch-scoped), payload
 * shape includes `extra_id` / `branch_id` / `is_available` / `reason` so
 * frontend Echo handlers can branch on the broadcast event name.
 */
class PersistItemExtraAvailabilityChangedToOutbox
{
    public function handle(ItemExtraAvailabilityChanged $event): void
    {
        $payload = [
            'extra_id'     => $event->extraId,
            'branch_id'    => $event->branchId,
            'is_available' => $event->isAvailable,
            'reason'       => $event->reason,
        ];

        $channels = ['private-branch.' . $event->branchId];

        $domainEvent = DomainEvent::query()->create([
            'event_type'     => EventType::MENU_EXTRA_AVAILABILITY_CHANGED,
            'aggregate_type' => 'item_extra',
            'aggregate_id'   => $event->extraId,
            'branch_id'      => $event->branchId,
            'payload'        => $payload,
            'channel'        => json_encode($channels),
            'broadcast_as'   => 'ItemExtraAvailabilityChanged',
            'correlation_id' => $this->resolveCorrelationId(),
            'occurred_at'    => now(),
        ]);

        DB::afterCommit(function () use ($domainEvent): void {
            DispatchDomainEventsJob::dispatch($domainEvent->id);
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
