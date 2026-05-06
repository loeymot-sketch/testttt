<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\OrderPaymentStatusChanged;
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
    public function handle(OrderPaymentStatusChanged $event): void
    {
        $order = $event->order;

        $domainEvent = DomainEvent::query()->create([
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
            'correlation_id' => $this->resolveCorrelationId(),
            'occurred_at'    => now(),
        ]);

        DB::afterCommit(function () use ($domainEvent): void {
            // [Audit Claude NEW-03 B7] Queue lane SSOT = job constructor.
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
