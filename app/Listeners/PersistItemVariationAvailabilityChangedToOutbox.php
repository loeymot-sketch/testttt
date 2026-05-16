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

        // [Sprint 5C Z8-P1-01 2026-05-16] Skip afterCommit dispatch on listener
        // replay (firstOrCreate returned existing row). Parity with
        // PersistOrderCreatedToOutbox / PersistCatalogChangedToOutbox.
        if (! $domainEvent->wasRecentlyCreated) {
            return;
        }

        DB::afterCommit(function () use ($domainEvent): void {
            // [test-e2e fix E-001 round-2 2026-05-11] Same isolation as
            // PersistItemAvailabilityChangedToOutbox: under sync queue any
            // broadcaster failure (Pusher unreachable in dev) bubbles up to
            // the controller as HTTP 500 even though the outbox row IS
            // persisted. Outbox retry cron (foodking:outbox:retry-failed)
            // picks up rows with dispatched_at=null when broadcaster recovers.
            try {
                DispatchDomainEventsJob::dispatch($domainEvent->id);
            } catch (\Throwable $broadcastException) {
                Log::warning('[Outbox] DispatchDomainEventsJob inline dispatch failed (non-blocking)', [
                    'domain_event_id' => $domainEvent->id,
                    'event_type'      => $domainEvent->event_type,
                    'aggregate_id'    => $domainEvent->aggregate_id,
                    'branch_id'       => $domainEvent->branch_id,
                    'error'           => $broadcastException->getMessage(),
                    'gate'            => 'test-e2e-fix-E-001-round-2',
                ]);
            }
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
