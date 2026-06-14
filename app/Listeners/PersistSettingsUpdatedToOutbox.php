<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\SettingsUpdated;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * [Wave 5G R9 heal 2026-05-17] Persiste un row `domain_events` par branche
 * lors d'un {@see SettingsUpdated}. Mirror du pattern adopté par
 * {@see PersistCatalogChangedToOutbox} et {@see PersistCouponChangedToOutbox}
 * (canal `private-branch.{id}`, broadcast_as, correlation_id, dispatch via
 * DispatchDomainEventsJob).
 *
 * Si $branchId est fourni : un seul row sur cette branche.
 * Sinon : fan-out sur toutes les branches actives (settings globaux).
 */
class PersistSettingsUpdatedToOutbox
{
    use \App\Listeners\Concerns\GuardsOutboxPersistence;

    public function handle(SettingsUpdated $event): void
    {
        $this->runOutboxPersistenceGuarded(self::class, fn () => $this->project($event));
    }

    private function project(SettingsUpdated $event): void
    {
        $branchIds = $event->branchId !== null
            ? collect([(int) $event->branchId])
            : Branch::query()
                // Accept legacy literal 1 alongside Status::ACTIVE (parité Catalog/Coupon listener).
                ->whereIn('status', [Status::ACTIVE, 1])
                ->pluck('id')
                ->map(fn ($branchId): int => (int) $branchId);

        if ($branchIds->isEmpty()) {
            return;
        }

        // Idempotency-stable: ordre des keys ne doit pas influer (advisor note 5).
        $sortedKeys = $event->changedKeys;
        sort($sortedKeys);
        $keysFingerprint = implode(',', $sortedKeys);

        $correlationId = $this->resolveCorrelationId();
        $domainEventIds = [];

        foreach ($branchIds as $branchId) {
            $idempotencyKey = sha1(implode('|', [
                EventType::SETTINGS_UPDATED,
                (int) $branchId,
                $keysFingerprint,
                $correlationId,
            ]));

            $domainEvent = DomainEvent::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'event_type'     => EventType::SETTINGS_UPDATED,
                    'aggregate_type' => 'settings',
                    'aggregate_id'   => 0,
                    'branch_id'      => $branchId,
                    'payload'        => [
                        'changed_keys' => $sortedKeys,
                        'branch_id'    => $branchId,
                    ],
                    'channel'        => json_encode(['private-branch.' . $branchId]),
                    'broadcast_as'   => 'SettingsUpdated',
                    'correlation_id' => $correlationId,
                    'occurred_at'    => now(),
                ]
            );

            if ($domainEvent->wasRecentlyCreated) {
                $domainEventIds[] = (int) $domainEvent->id;
            }
        }

        if ($domainEventIds === []) {
            return;
        }

        DB::afterCommit(function () use ($domainEventIds): void {
            foreach ($domainEventIds as $domainEventId) {
                // Best-effort broadcast (sibling defense — outbox retry cron
                // picks up rows with dispatched_at=null when broadcaster recovers).
                try {
                    DispatchDomainEventsJob::dispatch($domainEventId);
                } catch (\Throwable $broadcastException) {
                    Log::warning('[Outbox] DispatchDomainEventsJob inline dispatch failed (non-blocking)', [
                        'gate'            => 'wave-5g-r9-heal',
                        'domain_event_id' => $domainEventId,
                        'event_type'      => EventType::SETTINGS_UPDATED,
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
