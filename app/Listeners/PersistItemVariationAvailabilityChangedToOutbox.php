<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\ItemVariationAvailabilityChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * [F-016a-BIS] Outbox persistence for {@see ItemVariationAvailabilityChanged}.
 *
 * Sibling of {@see PersistItemExtraAvailabilityChangedToOutbox}; the only
 * differences are the event type constant, aggregate_type label and
 * payload key (variation_id vs extra_id) so downstream consumers can route.
 */
class PersistItemVariationAvailabilityChangedToOutbox
{
    public function handle(ItemVariationAvailabilityChanged $event): void
    {
        $payload = [
            'variation_id' => $event->variationId,
            'branch_id'    => $event->branchId,
            'is_available' => $event->isAvailable,
            'reason'       => $event->reason,
        ];

        $channels = ['private-branch.' . $event->branchId];
        $correlationId = $this->resolveCorrelationId();

        // [iter15-P1b — ref iter14 SPECIALIST-2 pattern]
        // Variation rupture toggle is a transition (true↔false) and NOT one-shot.
        // Scope dedupe to the originating request via correlation_id; a duplicate
        // listener fire within the same request collapses, but the next legitimate
        // toggle in a distinct request gets a fresh row.
        $idempotencyKey = sha1(implode('|', [
            EventType::MENU_VARIATION_AVAILABILITY_CHANGED,
            (int) $event->variationId,
            (int) $event->branchId,
            $event->isAvailable ? '1' : '0',
            $correlationId,
        ]));

        $domainEvent = DomainEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type'     => EventType::MENU_VARIATION_AVAILABILITY_CHANGED,
                'aggregate_type' => 'item_variation',
                'aggregate_id'   => $event->variationId,
                'branch_id'      => $event->branchId,
                'payload'        => $payload,
                'channel'        => json_encode($channels),
                'broadcast_as'   => 'ItemVariationAvailabilityChanged',
                'correlation_id' => $correlationId,
                'occurred_at'    => now(),
            ]
        );

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
