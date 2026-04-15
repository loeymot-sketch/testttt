<?php

namespace App\Traits;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;

trait HasDomainEvents
{
    protected array $pendingDomainEvents = [];

    public function recordDomainEvent(
        string $eventType,
        array $payload,
        ?string $channel = null,
        ?string $broadcastAs = null
    ): void {
        $this->pendingDomainEvents[] = [
            'event_type' => $eventType,
            'payload' => $payload,
            'channel' => $channel,
            'broadcast_as' => $broadcastAs,
        ];
    }

    public static function bootHasDomainEvents(): void
    {
        static::saved(function ($model): void {
            $events = $model->pendingDomainEvents ?? [];

            if (empty($events)) {
                return;
            }

            $model->pendingDomainEvents = [];

            $ids = [];

            foreach ($events as $event) {
                $domainEvent = DomainEvent::create([
                    'event_type' => $event['event_type'],
                    'aggregate_type' => get_class($model),
                    'aggregate_id' => $model->getKey(),
                    'branch_id' => $model->branch_id ?? null,
                    'payload' => $event['payload'],
                    'channel' => $event['channel'],
                    'broadcast_as' => $event['broadcast_as'],
                    'occurred_at' => now(),
                ]);

                $ids[] = $domainEvent->id;
            }

            DB::afterCommit(function () use ($ids): void {
                foreach ($ids as $id) {
                    DispatchDomainEventsJob::dispatch($id)->onQueue('high');
                }
            });
        });
    }
}
