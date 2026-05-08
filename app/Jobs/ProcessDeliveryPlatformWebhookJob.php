<?php

namespace App\Jobs;

use App\Domain\Delivery\PlatformAdapterRegistry;
use App\Models\DeliveryWebhookEvent;
use App\Services\Delivery\DeliveryOrderCancellationService;
use App\Services\Delivery\DeliveryOrderIngestionService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * [PARALLEL-TRACK-1.2 / Delivery Platform Integration — Phase 2]
 *
 * Async ingest worker.
 *
 * Why a separate job (vs. inline processing in the controller):
 *   - Gives us 5 retries with exponential backoff for transient
 *     downstream errors (deadlock on fiscal_sequence_no, Pricing
 *     service hiccup, etc.) without echoing the failure to the
 *     platform.
 *   - Decouples the platform's webhook timeout (Uber Eats: 8s)
 *     from our own ingest latency (typically 50-300ms).
 *   - Makes the controller measurable in isolation (≤200ms target).
 *
 * Idempotency contract:
 *   - The job re-reads the persisted DeliveryWebhookEvent row from
 *     DB (defensive: the row could have been already processed by a
 *     previous attempt that was killed mid-handle).
 *   - If `processed_at` is non-null we short-circuit: the work has
 *     already happened, this retry is a no-op.
 *   - Inside the IngestionService the (platform, external_id) UNIQUE
 *     index on `delivery_platform_external_orders` is the second line
 *     of defense — duplicate inserts surface as a QueryException 1062
 *     which the service catches and turns into a "return existing".
 */
class ProcessDeliveryPlatformWebhookJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /** Backoff in seconds: 10s, 30s, 90s, 5min, 15min. */
    public array $backoff = [10, 30, 90, 300, 900];

    /** Match $backoff length so the failed() callback fires last. */
    public int $tries = 5;

    public function __construct(public int $webhookEventId)
    {
        $this->onQueue('high');
    }

    public function handle(
        DeliveryOrderIngestionService $ingestion,
        DeliveryOrderCancellationService $cancellation,
        PlatformAdapterRegistry $registry,
    ): void {
        /** @var DeliveryWebhookEvent|null $event */
        $event = DeliveryWebhookEvent::query()->find($this->webhookEventId);

        if ($event === null) {
            Log::warning('[ProcessDeliveryPlatformWebhookJob] event row missing', [
                'webhook_event_id' => $this->webhookEventId,
            ]);
            return;
        }

        if ($event->processed_at !== null) {
            // Idempotency short-circuit — already done.
            return;
        }

        // [PARALLEL-TRACK-1.3 / Phase 3] Route by event type. The Phase 2
        // IngestionService deliberately short-circuits on non-create events
        // (DeliveryOrderIngestionService.php:103). Cancellation flows must
        // therefore travel through DeliveryOrderCancellationService, which
        // applies OrderStateMachine::apply(CANCELED) on the existing
        // FrontendOrder and dispatches OrderStatusChanged after commit.
        $eventType = $this->classifyEvent($event, $registry);

        if ($eventType === 'order.cancelled') {
            $cancelResult = $cancellation->cancel($event);
            $event->forceFill([
                'delivery_platform_external_order_id' => $cancelResult['external_order_id'] ?? null,
                'processed_at'                        => now(),
                'processing_error'                    => null,
            ])->save();
            return;
        }

        $result = $ingestion->ingest($event);

        $event->forceFill([
            'delivery_platform_external_order_id' => $result['external_order_id'] ?? null,
            'processed_at'                        => now(),
            'processing_error'                    => null,
        ])->save();
    }

    /**
     * Resolve the event type from the persisted body via the adapter,
     * falling back to the row's `event_type` column when the adapter
     * cannot decide (we still want forensic events to be classified
     * sensibly).
     */
    private function classifyEvent(DeliveryWebhookEvent $event, PlatformAdapterRegistry $registry): string
    {
        try {
            $adapter = $registry->for((string) $event->platform);
            $type    = $adapter->eventTypeFrom((array) ($event->body ?? []));
            if ($type !== '' && $type !== 'order.unknown') {
                return $type;
            }
        } catch (\Throwable $e) {
            Log::warning('[ProcessDeliveryPlatformWebhookJob] eventTypeFrom failed', [
                'webhook_event_id' => $this->webhookEventId,
                'error'            => $e->getMessage(),
            ]);
        }

        return (string) $event->event_type;
    }

    public function failed(\Throwable $exception): void
    {
        $event = DeliveryWebhookEvent::query()->find($this->webhookEventId);
        if ($event !== null) {
            $event->forceFill([
                'processing_error' => substr($exception->getMessage(), 0, 1000),
            ])->save();
        }

        Log::error('[ProcessDeliveryPlatformWebhookJob] final failure', [
            'webhook_event_id' => $this->webhookEventId,
            'error'            => $exception->getMessage(),
        ]);
    }
}
