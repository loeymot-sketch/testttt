<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\OrderStatusChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PersistOrderStatusChangedToOutbox
{
    public function handle(OrderStatusChanged $event): void
    {
        $order = $event->order;
        $correlationId = $this->resolveCorrelationId();

        // [iter14 SPECIALIST-2] Status transitions are NOT one-shot — Admin
        // override allows DELIVERED↔RETURNED reverts (see OrderStateMachine).
        // Scope dedupe to the originating request via correlation_id, so a
        // legitimate later transition with the same (old,new) tuple in a
        // different request still gets a fresh row, while a duplicate
        // listener fire within the same request collapses.
        $idempotencyKey = sha1(implode('|', [
            EventType::ORDER_STATUS_CHANGED,
            $order->id,
            (int) $event->oldStatus,
            (int) $event->newStatus,
            $correlationId,
        ]));

        $domainEvent = DomainEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type' => EventType::ORDER_STATUS_CHANGED,
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
                    'old_status' => $event->oldStatus,
                    'new_status' => $event->newStatus,
                    'token' => $order->token ?? null,
                ],
                'channel' => json_encode(['private-branch.' . $order->branch_id]),
                'broadcast_as' => 'OrderStatusChanged',
                'correlation_id' => $correlationId,
                'occurred_at' => now(),
            ]
        );

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
