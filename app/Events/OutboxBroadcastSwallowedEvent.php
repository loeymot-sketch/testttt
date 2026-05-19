<?php

namespace App\Events;

/**
 * Lightweight observability event fired when one of the Outbox persist
 * listeners (`PersistOrderCreatedToOutbox`,
 * `PersistOrderStatusChangedToOutbox`,
 * `PersistOrderPaymentStatusChangedToOutbox`) catches a `Throwable` from
 * `DispatchDomainEventsJob::dispatch(...)` inside its `DB::afterCommit` hook.
 *
 * [WJ-4 WI-5 OBS-OUTBOX-01 — 2026-05-19] Outbox swallow alarm.
 *
 * Rationale:
 *   - The Outbox DomainEvent row is already persisted in the DB by the time
 *     the afterCommit hook runs — the cron retry path (`outbox:retry-failed`)
 *     will pick it up and re-dispatch, so the broadcast is NOT lost.
 *   - BUT if BOTH the queue worker AND the cron run are simultaneously down
 *     (deploy window, infra failure, dropped systemd unit) the previous
 *     `Log::warning` emit was the only signal — production alerting tiers
 *     typically only catch `Log::error` / structured events, so the failure
 *     was effectively silent.
 *
 * Mirror policy of {@see StockDecrementFailedEvent}:
 *   - Intentionally **unwired** (no listener registered in
 *     `EventServiceProvider`). Ops alerting (Sentry breadcrumb, Datadog
 *     pipeline, custom listener) can subscribe later. Until then the
 *     structured `Log::error` tier in the originating listener is the
 *     visible signal — this event class is the typed hook for that drift.
 *   - Nested-try at the dispatch site absorbs failure of THIS event too —
 *     observability MUST NOT itself re-break the cascade.
 */
final class OutboxBroadcastSwallowedEvent
{
    public function __construct(
        public readonly int $domainEventId,
        public readonly string $eventType,
        public readonly int $aggregateId,
        public readonly int $branchId,
        public readonly string $listener,
        public readonly string $errorMessage,
        public readonly \DateTimeImmutable $failedAt,
    ) {
    }
}
