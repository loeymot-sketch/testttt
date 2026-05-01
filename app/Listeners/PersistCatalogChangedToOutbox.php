<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\CatalogChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DomainEvent;
use App\Services\Menu\MenuSnapshot;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class PersistCatalogChangedToOutbox
{
    public function __construct(
        private readonly MenuSnapshot $snapshot,
    ) {
    }

    public function handle(object $event): void
    {
        $catalogEvent = CatalogChanged::fromMenuMutation($event);
        if ($catalogEvent === null) {
            return;
        }

        $branchIds = $catalogEvent->branchId !== null
            ? collect([(int) $catalogEvent->branchId])
            : Branch::query()
                ->where('status', Status::ACTIVE)
                ->pluck('id')
                ->map(fn ($branchId): int => (int) $branchId);

        if ($branchIds->isEmpty()) {
            return;
        }

        $correlationId = $this->resolveCorrelationId();
        $domainEventIds = [];

        foreach ($branchIds as $branchId) {
            if ($this->alreadyPersisted($catalogEvent, $branchId, $correlationId)) {
                continue;
            }

            $domainEvent = DomainEvent::query()->create([
                'event_type' => EventType::CATALOG_CHANGED,
                'aggregate_type' => $catalogEvent->entityType,
                'aggregate_id' => $catalogEvent->entityId,
                'branch_id' => $branchId,
                'payload' => [
                    'entity_type' => $catalogEvent->entityType,
                    'entity_id' => $catalogEvent->entityId,
                    'change_type' => $catalogEvent->changeType,
                    'branch_id' => $branchId,
                    'snapshot_version' => $this->snapshot->current($branchId),
                    'payload_diff' => $catalogEvent->payloadDiff,
                ],
                'channel' => json_encode(['private-branch.' . $branchId]),
                'broadcast_as' => 'CatalogChanged',
                'correlation_id' => $correlationId,
                'occurred_at' => now(),
            ]);

            $domainEventIds[] = (int) $domainEvent->id;
        }

        if ($domainEventIds === []) {
            return;
        }

        DB::afterCommit(function () use ($domainEventIds): void {
            foreach ($domainEventIds as $domainEventId) {
                DispatchDomainEventsJob::dispatch($domainEventId);
            }
        });
    }

    private function alreadyPersisted(CatalogChanged $event, int $branchId, string $correlationId): bool
    {
        return DomainEvent::query()
            ->where('event_type', EventType::CATALOG_CHANGED)
            ->where('aggregate_type', $event->entityType)
            ->where('aggregate_id', $event->entityId)
            ->where('branch_id', $branchId)
            ->where('correlation_id', $correlationId)
            ->exists();
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
