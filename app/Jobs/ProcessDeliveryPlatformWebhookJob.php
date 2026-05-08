<?php

namespace App\Jobs;

use App\Models\DeliveryWebhookEvent;
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

    public function handle(DeliveryOrderIngestionService $ingestion): void
    {
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

        $result = $ingestion->ingest($event);

        $event->forceFill([
            'delivery_platform_external_order_id' => $result['external_order_id'] ?? null,
            'processed_at'                        => now(),
            'processing_error'                    => null,
        ])->save();
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
