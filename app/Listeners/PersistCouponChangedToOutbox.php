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
                ->where('status', Status::ACTIVE)
                ->pluck('id')
                ->map(fn ($branchId): int => (int) $branchId);

        if ($branchIds->isEmpty()) {
            return;
        }

        $correlationId = $this->resolveCorrelationId();
        $domainEventIds = [];

        foreach ($branchIds as $branchId) {
            if ($this->alreadyPersisted($event, $branchId, $correlationId)) {
                continue;
            }

            $domainEvent = DomainEvent::query()->create([
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

    private function alreadyPersisted(CouponChanged $event, int $branchId, string $correlationId): bool
    {
        return DomainEvent::query()
            ->where('event_type', EventType::COUPON_CHANGED)
            ->where('aggregate_type', 'coupon')
            ->where('aggregate_id', $event->couponId)
            ->where('branch_id', $branchId)
            ->where('correlation_id', $correlationId)
            ->where('payload->change_type', $event->changeType)
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
