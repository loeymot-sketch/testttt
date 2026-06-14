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
    use \App\Listeners\Concerns\GuardsOutboxPersistence;

    public function __construct(
        private readonly MenuSnapshot $snapshot,
    ) {
    }

    public function handle(object $event): void
    {
        $this->runOutboxPersistenceGuarded(self::class, fn () => $this->project($event));
    }

    private function project(object $event): void
    {
        $catalogEvent = CatalogChanged::fromMenuMutation($event);
        if ($catalogEvent === null) {
            return;
        }

        $branchIds = $catalogEvent->branchId !== null
            ? collect([(int) $catalogEvent->branchId])
            : Branch::query()
                // [ultra-goal A3 heal 2026-05-13] Accept legacy literal `1` alongside
                // Status::ACTIVE (=5). Production DB seeded long before the enum
                // migration still has status=1 for active branches. Listener
                // filter was silently dropping all branchId=null fan-out (e.g.
                // MenuResetLeCayenneCommand CategoryCreated/Updated/Deleted).
                // Owner action: data migration `UPDATE branches SET status=5 WHERE status=1`.
                ->whereIn('status', [Status::ACTIVE, 1])
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
                // [test-e2e fix E-001 round-3 cluster-8 2026-05-11] broadcast best-effort;
                // do not fail HTTP on Pusher dispatch error. Cluster 6 (762bf7812) wrapped
                // the 3 Persist*AvailabilityChangedToOutbox listeners but missed THIS
                // listener even though it ALSO subscribes to ItemAvailabilityChanged
                // (EventServiceProvider:175). Under sync queue any broadcaster failure
                // (Pusher unreachable in dev) bubbled up to AvailabilityController as
                // HTTP 500 even though the outbox row IS persisted. Outbox retry cron
                // (foodking:outbox:retry-failed) picks up rows with dispatched_at=null
                // when broadcaster recovers.
                try {
                    DispatchDomainEventsJob::dispatch($domainEventId);
                } catch (\Throwable $broadcastException) {
                    Log::warning('[Outbox] DispatchDomainEventsJob inline dispatch failed (non-blocking)', [
                        'gate'            => 'test-e2e-fix-E-001-round-3',
                        'domain_event_id' => $domainEventId,
                        'event_type'      => EventType::CATALOG_CHANGED,
                        'aggregate_id'    => null,
                        'branch_id'       => null,
                        'error'           => $broadcastException->getMessage(),
                    ]);
                }
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
