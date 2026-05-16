<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\OrderCreated;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PersistOrderCreatedToOutbox
{
    public function handle(OrderCreated $event): void
    {
        $order = $event->order;

        // [iter14 SPECIALIST-2] OrderCreated is a one-shot per aggregate.
        // sha1(event_type|aggregate_id) — no discriminator; second listener
        // fire (e.g. queue retry) is silently absorbed by the UNIQUE index.
        $idempotencyKey = sha1(EventType::ORDER_CREATED . '|' . $order->id);

        $domainEvent = DomainEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type' => EventType::ORDER_CREATED,
                'aggregate_type' => get_class($order),
                'aggregate_id' => $order->id,
                'branch_id' => $order->branch_id,
                'payload' => [
                    'order_id' => $order->id,
                    'queue_number' => $order->queue_number,
                    '_origin' => $this->resolveOrigin($order),
                    'payment_method' => $this->resolvePaymentMethod($order),
                    'payment_status' => $order->payment_status,
                    'payment_pending_counter' => (int) $order->payment_status === \App\Enums\PaymentStatus::PENDING_COUNTER,
                    'status' => $order->status,
                    'order_type' => $order->order_type,
                    'total' => $order->total,
                    'created_at' => $order->created_at?->toISOString(),
                ],
                'channel' => json_encode(['private-branch.' . $order->branch_id]),
                'broadcast_as' => 'OrderCreated',
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
            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
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
