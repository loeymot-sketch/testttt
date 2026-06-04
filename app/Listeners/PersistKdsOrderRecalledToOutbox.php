<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\KdsOrderRecalled;
use App\Events\OutboxBroadcastSwallowedEvent;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * [Heal-5 / PROPOSAL KDS Archive Undo 2026-05-25 — Path B compensating action]
 *
 * Mirrors {@see PersistOrderStatusChangedToOutbox} structure so the outbox
 * pipeline (DispatchDomainEventsJob) fans the recall out on the existing
 * `private-branch.{branchId}` channel without bespoke broadcast wiring.
 *
 * Frontend (KitchenDisplaySystemComponent.vue) subscribes via the existing
 * `onEvents(branchId, [{ broadcastAs: 'KdsOrderRecalled', handler: ... }])`
 * helper, so the only contract change is registering the broadcast name in
 * `resources/js/services/eventContract.js::BROADCAST_MAP`.
 *
 * Idempotency: keyed by (event_type, order_id, recalled_at, correlation_id)
 * so a listener replay collapses but a genuine second recall in a different
 * request still gets a fresh row (matches the OrderStatusChanged dedupe
 * pattern documented at PersistOrderStatusChangedToOutbox.php:22-33).
 */
class PersistKdsOrderRecalledToOutbox
{
    public function handle(KdsOrderRecalled $event): void
    {
        $correlationId = $event->correlationId ?: $this->resolveCorrelationId();

        $idempotencyKey = sha1(implode('|', [
            EventType::KDS_ORDER_RECALLED,
            $event->orderId,
            $event->recalledAt,
            $correlationId,
        ]));

        $domainEvent = DomainEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type'     => EventType::KDS_ORDER_RECALLED,
                'aggregate_type' => 'App\\Models\\Order',
                'aggregate_id'   => $event->orderId,
                'branch_id'      => $event->branchId,
                'payload'        => [
                    'order_id'     => $event->orderId,
                    'branch_id'    => $event->branchId,
                    'queue_number' => $event->queueNumber,
                    'actor_id'     => $event->actorId,
                    'recalled_at'  => $event->recalledAt,
                ],
                'channel'        => json_encode(['private-branch.' . $event->branchId]),
                'broadcast_as'   => 'KdsOrderRecalled',
                'correlation_id' => $correlationId,
                'occurred_at'    => now(),
            ]
        );

        if (! $domainEvent->wasRecentlyCreated) {
            return;
        }

        DB::afterCommit(function () use ($domainEvent): void {
            try {
                DispatchDomainEventsJob::dispatch($domainEvent->id);
            } catch (\Throwable $broadcastException) {
                Log::error('[Outbox] DispatchDomainEventsJob inline dispatch failed for KdsOrderRecalled (non-blocking, retries via cron)', [
                    'gate'            => 'HEAL-5-KDS-RECALL',
                    'domain_event_id' => $domainEvent->id,
                    'event_type'      => $domainEvent->event_type,
                    'aggregate_id'    => $domainEvent->aggregate_id,
                    'branch_id'       => $domainEvent->branch_id,
                    'error'           => $broadcastException->getMessage(),
                ]);

                try {
                    event(new OutboxBroadcastSwallowedEvent(
                        domainEventId: (int) $domainEvent->id,
                        eventType:     (string) $domainEvent->event_type,
                        aggregateId:   (int) $domainEvent->aggregate_id,
                        branchId:      (int) $domainEvent->branch_id,
                        listener:      self::class,
                        errorMessage:  $broadcastException->getMessage(),
                        failedAt:      new \DateTimeImmutable(),
                    ));
                } catch (\Throwable $observabilityException) {
                    Log::warning('[Outbox] OutboxBroadcastSwallowedEvent dispatch absorbed', [
                        'domain_event_id' => $domainEvent->id,
                        'error'           => $observabilityException->getMessage(),
                    ]);
                }
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
