<?php

namespace App\Jobs;

use App\Models\DomainEvent;
use App\Traits\HasCorrelationId;
use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
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

            $envelope = [
                'version' => 1,
                'type' => $domainEvent->event_type,
                'aggregate_id' => $domainEvent->aggregate_id,
                'branch_id' => $domainEvent->branch_id,
                'occurred_at' => $domainEvent->occurred_at?->toIso8601String(),
                'correlation_id' => $domainEvent->correlation_id,
                'payload' => $domainEvent->payload,
            ];

            /** @var \Illuminate\Broadcasting\Broadcasters\PusherBroadcaster $connection */
            $connection = app(BroadcastManager::class)->connection('pusher');
            $connection->getPusher()->trigger($channels, $domainEvent->broadcast_as, $envelope);
        }

        $domainEvent->forceFill([
            'dispatched_at' => now(),
            'last_error' => null,
        ])->save();
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
