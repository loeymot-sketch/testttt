<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\OrderPaidAtCounter;
use App\Events\OutboxBroadcastSwallowedEvent;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PersistOrderPaidAtCounterToOutbox
{
    public function handle(OrderPaidAtCounter $event): void
    {
        $order = $event->order;

        // [iter14 SPECIALIST-2] OrderPaidAtCounter is a one-shot per aggregate.
        // sha1(event_type|aggregate_id) — second listener fire (e.g. queue
        // retry between persistence and post-commit dispatch) collapses on
        // the UNIQUE index.
        $idempotencyKey = sha1(EventType::ORDER_PAYMENT_CONFIRMED . '|' . $order->id);

        $domainEvent = DomainEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type' => EventType::ORDER_PAYMENT_CONFIRMED,
                'aggregate_type' => get_class($order),
                'aggregate_id' => $order->id,
                'branch_id' => $order->branch_id,
                'payload' => [
                    'order_id' => $order->id,
                    'queue_number' => $order->queue_number,
                    '_origin' => (string) ($order->source_surface ?: 'kiosk'),
                    'payment_method' => $event->paymentMethod,
                    'payment_status' => $order->payment_status,
                    'fiscal_sequence_no' => $order->fiscal_sequence_no,
                ],
                'channel' => json_encode(['private-branch.' . $order->branch_id]),
                'broadcast_as' => 'OrderPaidAtCounter',
                'correlation_id' => $this->resolveCorrelationId(),
                'occurred_at' => now(),
            ]
        );

        // [Sprint 3B P1-SYNC-03 2026-05-16] Parity with PersistCatalogChangedToOutbox:92
        // and PersistCouponChangedToOutbox:82. On a listener replay (e.g. queue retry
        // between persistence and post-commit dispatch), the second firstOrCreate()
        // returns the existing row and `wasRecentlyCreated` is false. Phase 1 of the
        // job (claim skip) would correctly absorb the dup, but we save the cost of
        // queue serialization + log noise by skipping the afterCommit dispatch
        // registration entirely on replay.
        if (! $domainEvent->wasRecentlyCreated) {
            return;
        }

        DB::afterCommit(function () use ($domainEvent): void {
            // [test-e2e fix E-001 round-3 cluster-8 2026-05-11] broadcast best-effort;
            // do not fail HTTP on Pusher dispatch error (sibling defense — same
            // defect class as PersistItemAvailabilityChangedToOutbox patched
            // in cluster 6 / round 2).
            try {
                DispatchDomainEventsJob::dispatch($domainEvent->id);
            } catch (\Throwable $broadcastException) {
                // [GOAL-sync-ordertaking 2026-05-29 H4] Parity with
                // PersistOrderStatusChangedToOutbox / PersistOrderCreatedToOutbox.
                // OrderPaidAtCounter is fiscal-adjacent (payment-confirmed) yet was
                // the ONLY order outbox listener missing the swallow alarm — a lost
                // broadcast here left the operator un-paged. Escalate to Log::error +
                // fire OutboxBroadcastSwallowedEvent (the persisted row is still
                // retried by cron outbox:retry-failed; the event wires structured
                // production alerting via EscalateOutboxBroadcastSwallowed).
                Log::error('[Outbox] DispatchDomainEventsJob inline dispatch failed (non-blocking, retries via cron)', [
                    'gate'            => 'GOAL-sync-ordertaking-2026-05-29-H4',
                    'domain_event_id' => $domainEvent->id,
                    'event_type'      => $domainEvent->event_type,
                    'aggregate_id'    => $domainEvent->aggregate_id,
                    'branch_id'       => $domainEvent->branch_id,
                    'error'           => $broadcastException->getMessage(),
                ]);

                // Defense-in-depth: the observability event MUST NOT itself
                // re-break the cascade. Mirror PersistOrderStatusChangedToOutbox.
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
