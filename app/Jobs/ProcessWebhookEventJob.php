<?php

namespace App\Jobs;

use App\Models\WebhookEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [Sprint H3 P1-Z8-02 2026-05-17] Re-run webhook handler logic from a
 * stored `webhook_events` row. Idempotency is preserved by the row id —
 * `markProcessed` / `markFailed` keep the ledger consistent on replay.
 *
 * This is a thin orchestrator: it dispatches by provider to the existing
 * gateway handler class. Each provider class exposes a public
 * `handleFromStoredEvent(WebhookEvent $event)` method (added in this
 * same sprint). New providers register a case in the match below.
 *
 * Companion command: `app/Console/Commands/OutboxWebhookRetryFailedCommand.php`
 * Schedule: hourly in `app/Console/Kernel.php` (mirrors outbox retry-failed cadence).
 */
class ProcessWebhookEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /**
     * Exponential-ish backoff before re-dispatch on inner failure.
     *
     * @var array<int,int>
     */
    public array $backoff = [10, 60, 300];

    public function __construct(public int $webhookEventId)
    {
    }

    public function handle(): void
    {
        $event = WebhookEvent::find($this->webhookEventId);
        if ($event === null) {
            Log::channel('fiscal')->warning('webhook.dlq.missing_row', [
                'event'             => 'webhook_dlq_missing_row',
                'webhook_event_id'  => $this->webhookEventId,
            ]);

            return;
        }

        // Idempotent short-circuit — a concurrent replay or the live
        // webhook endpoint may have already processed this row.
        if ($event->status === WebhookEvent::STATUS_PROCESSED) {
            return;
        }

        try {
            $this->dispatchToProviderHandler($event);
        } catch (Throwable $e) {
            // Inner handler is responsible for `markFailed` on its own
            // error paths, but if it throws bare we record it here so
            // the row never silently drops out of the DLQ.
            if ($event->status !== WebhookEvent::STATUS_FAILED) {
                $event->markFailed($e->getMessage());
            }
            throw $e; // Queue retry via $tries + $backoff.
        }
    }

    private function dispatchToProviderHandler(WebhookEvent $event): void
    {
        $handlerClass = match ($event->provider) {
            WebhookEvent::PROVIDER_STRIPE    => \App\Http\PaymentGateways\Gateways\Stripe::class,
            WebhookEvent::PROVIDER_SENANGPAY => \App\Http\PaymentGateways\Gateways\Senangpay::class,
            default                          => null,
        };

        if ($handlerClass === null || ! method_exists($handlerClass, 'handleFromStoredEvent')) {
            Log::channel('fiscal')->warning('webhook.dlq.no_provider_handler', [
                'event'            => 'webhook_dlq_no_provider_handler',
                'provider'         => $event->provider,
                'webhook_event_id' => $event->id,
            ]);
            $event->markFailed('No DLQ handler for provider: ' . $event->provider);

            return;
        }

        app($handlerClass)->handleFromStoredEvent($event);
    }
}
