<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\OrderPaymentStatusChanged;
use App\Events\OutboxBroadcastSwallowedEvent;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Outbox listener for {@see OrderPaymentStatusChanged}.
 *
 * Mirrors {@see PersistOrderStatusChangedToOutbox} pattern.
 *
 * Plan : docs/audit/plans/PLAN_P13_PAYMENT_STATUS_STATE_MACHINE_2026-05-06.md
 */
class PersistOrderPaymentStatusChangedToOutbox
{
    use \App\Listeners\Concerns\GuardsOutboxPersistence;

    public function handle(OrderPaymentStatusChanged $event): void
    {
        $this->runOutboxPersistenceGuarded(self::class, fn () => $this->project($event));
    }

    private function project(OrderPaymentStatusChanged $event): void
    {
        $order = $event->order;
        $correlationId = $this->resolveCorrelationId();

        // [iter14 SPECIALIST-2] Payment status transitions can repeat across
        // requests (UNPAID→PAID→REFUNDED, then admin re-bills, etc.). Scope
        // dedupe to the originating request via correlation_id so duplicate
        // listener fires within the same request collapse but legitimate
        // later transitions still produce a row.
        $idempotencyKey = sha1(implode('|', [
            EventType::ORDER_PAYMENT_STATUS_CHANGED,
            $order->id,
            (int) $event->oldPaymentStatus,
            (int) $event->newPaymentStatus,
            $correlationId,
        ]));

        $domainEvent = DomainEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type'     => EventType::ORDER_PAYMENT_STATUS_CHANGED,
                'aggregate_type' => get_class($order),
                'aggregate_id'   => $order->id,
                'branch_id'      => $order->branch_id,
                'payload'        => [
                    'order_id'           => $order->id,
                    'branch_id'          => (int) $order->branch_id,
                    'queue_number'       => $order->queue_number,
                    '_origin'            => $this->resolveOrigin($order),
                    'payment_method'     => $this->resolvePaymentMethod($order),
                    'old_status'         => $event->oldPaymentStatus,
                    'new_status'         => $event->newPaymentStatus,
                    'total'              => round((float) $order->total, 2),
                    'fiscal_sequence_no' => $order->fiscal_sequence_no,
                    'token'              => $order->token ?? null,
                ],
                'channel'        => json_encode(['private-branch.' . $order->branch_id]),
                'broadcast_as'   => 'OrderPaymentStatusChanged',
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
            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
            // [test-e2e fix E-001 round-3 cluster-8 2026-05-11] broadcast best-effort;
            // do not fail HTTP on Pusher dispatch error (sibling defense — same
            // defect class as PersistItemAvailabilityChangedToOutbox patched
            // in cluster 6 / round 2).
            // [WJ-4 WI-5 OBS-OUTBOX-01 2026-05-19] Escalate swallow log to
            // Log::error tier + dispatch OutboxBroadcastSwallowedEvent typed
            // hook so production alerting (Sentry / Datadog) can wire
            // structured alarms. The DomainEvent row is already persisted —
            // cron `outbox:retry-failed` will retry — but if worker + cron
            // are simultaneously down the previous warning emit was silent.
            try {
                DispatchDomainEventsJob::dispatch($domainEvent->id);
            } catch (\Throwable $broadcastException) {
                Log::error('[Outbox] DispatchDomainEventsJob inline dispatch failed (non-blocking, retries via cron)', [
                    'gate'            => 'WJ-4-WI5-OBSOUTBOX01',
                    'domain_event_id' => $domainEvent->id,
                    'event_type'      => $domainEvent->event_type,
                    'aggregate_id'    => $domainEvent->aggregate_id,
                    'branch_id'       => $domainEvent->branch_id,
                    'error'           => $broadcastException->getMessage(),
                ]);

                // Defense-in-depth: observability event MUST NOT itself
                // re-break the cascade. Mirror DecrementStockOnOrderCreated.
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

    private function resolveOrigin(object $order): string
    {
        $surface = trim((string) ($order->source_surface ?? ''));

        if ($surface !== '') {
            return $surface;
        }

        if (($order->pos_payment_method ?? null) !== null) {
            return 'pos';
        }

        return ($order->queue_number ?? null) !== null ? 'kiosk' : 'web';
    }

    private function resolvePaymentMethod(object $order): int|string|null
    {
        if (($order->pos_payment_method ?? null) !== null) {
            return $order->pos_payment_method;
        }

        return $order->payment_method ?? null;
    }
}
