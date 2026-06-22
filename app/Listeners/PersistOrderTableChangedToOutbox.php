<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\OrderTableChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * [F-02] Persist OrderTableChanged into the outbox and fan it out on the
 * private-branch.{branchId} channel after commit. Mirrors the pattern used by
 * {@see PersistOrderStatusChangedToOutbox}.
 *
 * Payload contract (see also broadcast_as = 'OrderTableChanged'):
 *   - order_id            (int)
 *   - branch_id           (int)
 *   - previous_table_id   (?int)
 *   - new_table_id        (int)
 *   - action              ('occupy'|'transfer')
 *   - queue_number        (?int)  — KDS card key
 *   - dining_table_name   (?string) — frontend label hint, when known
 */
class PersistOrderTableChangedToOutbox
{
    public function handle(OrderTableChanged $event): void
    {
        $order = $event->order;
        $correlationId = $this->resolveCorrelationId();

        $diningTableName = null;
        try {
            if (method_exists($order, 'diningTable') || isset($order->diningTable)) {
                $relation = $order->diningTable ?? null;
                $diningTableName = is_object($relation) && isset($relation->name) ? (string) $relation->name : null;
            }
        } catch (\Throwable $e) {
            $diningTableName = null;
        }

        // [iter15-P1b — ref iter14 SPECIALIST-2 pattern]
        // Table reassignment is a transition (previous_table → new_table) and is
        // NOT one-shot — admin can re-assign back to a prior table in a separate
        // request. Scope dedupe to the originating request via correlation_id so
        // a duplicate listener fire within the same request collapses, but a
        // legitimate later reassignment with the same (prev,new) tuple in a
        // distinct request still gets a fresh row.
        $idempotencyKey = sha1(implode('|', [
            EventType::ORDER_TABLE_CHANGED,
            (int) $order->id,
            $event->previousTableId === null ? 'null' : (int) $event->previousTableId,
            (int) $event->newTableId,
            $correlationId,
        ]));

        $domainEvent = DomainEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type'     => EventType::ORDER_TABLE_CHANGED,
                'aggregate_type' => get_class($order),
                'aggregate_id'   => $order->id,
                'branch_id'      => $order->branch_id,
                'payload'        => [
                    'order_id'           => (int) $order->id,
                    'branch_id'          => (int) $order->branch_id,
                    'previous_table_id'  => $event->previousTableId,
                    'new_table_id'       => (int) $event->newTableId,
                    'action'             => $event->action,
                    'queue_number'       => $order->queue_number ?? null,
                    'dining_table_name'  => $diningTableName,
                ],
                'channel'        => json_encode(['private-branch.' . $order->branch_id]),
                'broadcast_as'   => 'OrderTableChanged',
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
            // [Audit Claude NEW-03 B7] Queue lane is encoded in the job
            // constructor (SSOT). Do NOT chain ->onQueue(...) here unless
            // an intentional override is required.
            // [test-e2e fix E-001 round-3 cluster-8 2026-05-11] broadcast best-effort;
            // do not fail HTTP on Pusher dispatch error (sibling defense — same
            // defect class as PersistItemAvailabilityChangedToOutbox patched
            // in cluster 6 / round 2).
            try {
                DispatchDomainEventsJob::dispatch($domainEvent->id);
            } catch (\Throwable $broadcastException) {
                Log::warning('[Outbox] DispatchDomainEventsJob inline dispatch failed (non-blocking)', [
                    'gate'            => 'test-e2e-fix-E-001-round-3',
                    'domain_event_id' => $domainEvent->id,
                    'event_type'      => $domainEvent->event_type,
                    'aggregate_id'    => $domainEvent->aggregate_id,
                    'branch_id'       => $domainEvent->branch_id,
                    'error'           => $broadcastException->getMessage(),
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
