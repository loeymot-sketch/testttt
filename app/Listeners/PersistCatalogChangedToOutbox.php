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
            // [iter15-P1b — ref iter14 SPECIALIST-2 pattern]
            // Replaces the prior `alreadyPersisted()` exists() probe (race-non-atomic
            // under concurrent listener fires) with a deterministic key + UNIQUE
            // index dedupe. Key includes branch_id because the listener fans out to
            // one row per branch; without it the second branch's firstOrCreate would
            // collide with the first row and silently lose N-1 broadcasts. Also
            // includes change_type so a created→deleted sequence on the same entity
            // in the same request retains both rows (legitimate distinct events).
            $idempotencyKey = sha1(implode('|', [
                EventType::CATALOG_CHANGED,
                (string) $catalogEvent->entityType,
                (int) $catalogEvent->entityId,
                (int) $branchId,
                (string) $catalogEvent->changeType,
                $correlationId,
            ]));

            $domainEvent = DomainEvent::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
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
                ]
            );

            // Only schedule a dispatch when firstOrCreate actually inserted; on a
            // replay the existing row was already enqueued by the original fire.
            if ($domainEvent->wasRecentlyCreated) {
                $domainEventIds[] = (int) $domainEvent->id;
            }
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
