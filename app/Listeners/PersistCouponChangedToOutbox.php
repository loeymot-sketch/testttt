<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Enums\Status;
use App\Events\CouponChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\Branch;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * [PROMO-DASH-2026-05-06] Persiste un row `domain_events` par branche concernée
 * lors d'un {@see CouponChanged}. Mirror exact de la forme adoptée par
 * {@see PersistCatalogChangedToOutbox} (canal `private-branch.{id}`,
 * broadcast_as, correlation_id, dispatch via DispatchDomainEventsJob).
 */
class PersistCouponChangedToOutbox
{
    public function handle(CouponChanged $event): void
    {
        // Si le coupon a un scope de branches, on borne aux branches du scope.
        // Sinon, on broadcast à toutes les branches actives.
        $branchIds = !empty($event->branchScope)
            ? collect($event->branchScope)->map(fn ($id) => (int) $id)
            : Branch::query()
                // [ultra-goal A3 heal 2026-05-13] Accept legacy literal 1 alongside Status::ACTIVE.
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
            // index dedupe. Key includes branch_id (one row per branch in fan-out)
            // and change_type (created/updated/deleted/status_toggled distinct
            // legitimate events on same coupon in same request).
            $idempotencyKey = sha1(implode('|', [
                EventType::COUPON_CHANGED,
                (int) $event->couponId,
                (int) $branchId,
                (string) $event->changeType,
                $correlationId,
            ]));

            $domainEvent = DomainEvent::query()->firstOrCreate(
                ['idempotency_key' => $idempotencyKey],
                [
                    'event_type'    => EventType::COUPON_CHANGED,
                    'aggregate_type' => 'coupon',
                    'aggregate_id' => $event->couponId,
                    'branch_id'    => $branchId,
                    'payload'      => [
                        'coupon_id'   => $event->couponId,
                        'code'        => $event->code,
                        'status'      => $event->status,
                        'change_type' => $event->changeType,
                        'branch_id'   => $branchId,
                        'surfaces'    => $event->surfaces,
                        'payload_diff' => $event->payloadDiff,
                    ],
                    'channel'        => json_encode(['private-branch.' . $branchId]),
                    'broadcast_as'   => 'CouponChanged',
                    'correlation_id' => $correlationId,
                    'occurred_at'    => now(),
                ]
            );

            // Only schedule a dispatch when firstOrCreate actually inserted; on
            // a replay the original fire already enqueued the dispatch job.
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
                // do not fail HTTP on Pusher dispatch error (sibling defense — same
                // defect class as PersistItemAvailabilityChangedToOutbox patched
                // in cluster 6 / round 2).
                try {
                    DispatchDomainEventsJob::dispatch($domainEventId);
                } catch (\Throwable $broadcastException) {
                    Log::warning('[Outbox] DispatchDomainEventsJob inline dispatch failed (non-blocking)', [
                        'gate'            => 'test-e2e-fix-E-001-round-3',
                        'domain_event_id' => $domainEventId,
                        'event_type'      => EventType::COUPON_CHANGED,
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
