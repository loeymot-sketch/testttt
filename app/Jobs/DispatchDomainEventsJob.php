<?php

namespace App\Jobs;

use App\Domain\Events\EventContract;
use App\Exceptions\PayloadMismatchException;
use App\Models\DomainEvent;
use App\Traits\HasCorrelationId;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class DispatchDomainEventsJob implements ShouldQueue
{
    use Dispatchable, HasCorrelationId, InteractsWithQueue, Queueable, SerializesModels;

    public array $backoff = [1, 5, 30, 300];

    public int $tries = 5;

    public function __construct(public int $domainEventId)
    {
        $this->onQueue('high');
        $this->initializeCorrelationId();
    }

    public function handle(): void
    {
        $this->restoreCorrelationContext();

        $domainEvent = DomainEvent::query()->find($this->domainEventId);

        if (! $domainEvent || $domainEvent->dispatched_at !== null) {
            return;
        }

        $domainEvent->increment('attempts');
        $domainEvent->refresh();

        if ($domainEvent->channel !== null && $domainEvent->broadcast_as !== null) {
            $channels = json_decode($domainEvent->channel, true);

            if (! is_array($channels)) {
                $channels = [$domainEvent->channel];
            }

            $envelope = EventContract::buildEnvelope($domainEvent);

            // Validate the envelope against the V1 contract before broadcast.
            // A PayloadMismatchException short-circuits this job and marks the
            // outbox row as failed, preventing garbage from reaching the clients.
            try {
                EventContract::assertEnvelopeValid($envelope, $domainEvent->event_type);
            } catch (PayloadMismatchException $exception) {
                Log::error('[DispatchDomainEventsJob] Envelope contract violation', [
                    'domain_event_id' => $domainEvent->id,
                    'event_type' => $domainEvent->event_type,
                    'errors' => $exception->errors,
                ]);
                $domainEvent->forceFill([
                    'last_error' => 'contract_violation: ' . $exception->getMessage(),
                ])->save();
                throw $exception;
            }

            /** @var \Illuminate\Broadcasting\Broadcasters\PusherBroadcaster $connection */
            $connection = app(BroadcastManager::class)->connection('pusher');

            // In test/CI environments without a running Reverb/Pusher instance,
            // PUSHER_APP_KEY is empty and hitting the broadcaster would raise
            // a cURL error and fail the sync queue job. We skip the real
            // network call only when credentials are genuinely missing AND
            // the BroadcastManager was NOT swapped out by a test mock
            // (Mockery binds a proxy whose class name contains "Mockery_").
            // Mirrors the FCM "server key not configured — skipping" pattern
            // elsewhere in the codebase.
            $pusherKey = (string) config('broadcasting.connections.pusher.key', '');
            $isRealManager = get_class(app(BroadcastManager::class)) === BroadcastManager::class;

            if ($pusherKey === '' && $isRealManager) {
                Log::info('[DispatchDomainEventsJob] Pusher not configured — skipping broadcast', [
                    'domain_event_id' => $domainEvent->id,
                    'event_type' => $domainEvent->event_type,
                    'channels' => $channels,
                ]);
            } else {
                $connection->getPusher()->trigger($channels, $domainEvent->broadcast_as, $envelope);
            }
        }

        $domainEvent->forceFill([
            'dispatched_at' => now(),
            'last_error' => null,
        ])->save();

        // [V1 SYNC_ROBUSTNESS 3.3] Heartbeat for HealthController.ready() —
        // proves a worker actually drained an event from the outbox.
        // 60s TTL keeps the read-side simple (stale > 60s = degraded).
        Cache::put('ws:heartbeat', now()->toIso8601String(), 60);
    }

    public function failed(\Throwable $exception): void
    {
        $domainEvent = DomainEvent::query()->find($this->domainEventId);

        if (! $domainEvent) {
            return;
        }

        $domainEvent->forceFill([
            'last_error' => $exception->getMessage(),
        ])->save();

        Log::warning('[DispatchDomainEventsJob] Final failure dispatching domain event', [
            'domain_event_id' => $domainEvent->id,
            'event_type' => $domainEvent->event_type,
            'error' => $exception->getMessage(),
        ]);
    }
}
