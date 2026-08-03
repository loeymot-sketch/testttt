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
 * Wiring (corrigé 2026-07-02 — le docblock disait « intentionally unwired », c'était FAUX) :
 *   - **CÂBLÉ** : `EventServiceProvider::$listen` mappe
 *     `OutboxBroadcastSwallowedEvent::class => [EscalateOutboxBroadcastSwallowed::class]`
 *     (EventServiceProvider.php:327-329). Le listener émet
 *     `Log::channel('fiscal')->critical` avec le payload structuré (pager-grade
 *     alarm V1 LOCAL, HEAL B.2 2026-05-19). Un tiers d'alerting (Sentry, Datadog)
 *     peut s'ajouter à cette liste sans toucher le site de dispatch.
 *   - Nested-try au site de dispatch absorbe l'échec de CET event aussi —
 *     l'observabilité ne doit JAMAIS re-casser la cascade.
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
