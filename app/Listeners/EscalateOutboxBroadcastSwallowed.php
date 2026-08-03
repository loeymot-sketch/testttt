<?php

namespace App\Listeners;

use App\Events\OutboxBroadcastSwallowedEvent;
use Illuminate\Support\Facades\Log;

/**
 * [HEAL B.2 2026-05-19] V1 LOCAL signal-tier escalation for outbox
 * broadcast swallows. Closes RED-Z3 finding B-3 P1.
 *
 * Why this listener exists:
 *   - `OutboxBroadcastSwallowedEvent` (commit c82c912ec WJ-4 P1) was
 *     intentionally unwired — only the inline `Log::error`/`Log::warning`
 *     in the originating listeners carried the signal. Production-tier
 *     alerting greps the `fiscal` channel (storage/logs/fiscal.log) for
 *     `critical` lines; warnings get drowned in noise.
 *
 * Why `fiscal` channel (not `observability` or `stack`):
 *   - The `fiscal` channel is provisioned with 400-day retention
 *     (config/logging.php:182-187) — outbox broadcast swallows are
 *     fiscal-adjacent (the broadcast that was lost may have carried an
 *     OrderPaymentStatusChanged that paired with a fiscal sequence
 *     allocation). Co-locating with fiscal evidence simplifies audit.
 *   - The operator's nightly log-grep cron already greps `fiscal.log`
 *     for `critical` lines — V1 LOCAL single-host has no Sentry/Datadog
 *     pager. SaaS V1.0.2 can replace this listener with a
 *     provider-specific bridge (Sentry::captureMessage, Datadog event)
 *     without touching the dispatch sites in `PersistOrderCreatedToOutbox`,
 *     `PersistOrderStatusChangedToOutbox`,
 *     `PersistOrderPaymentStatusChangedToOutbox`.
 *
 * Why NOT `ShouldQueue`:
 *   - Listener fires inside the originating listener's `DB::afterCommit`
 *     hook. Queuing the escalation would route it through the same
 *     `DispatchDomainEventsJob` pipeline that just failed, defeating the
 *     purpose. Synchronous + cheap (one Log call) is the right shape.
 *   - Failure of THIS listener is absorbed by the nested try/catch in the
 *     dispatch sites — observability MUST NOT itself re-break the cascade.
 */
final class EscalateOutboxBroadcastSwallowed
{
    public function handle(OutboxBroadcastSwallowedEvent $event): void
    {
        Log::channel('fiscal')->critical('[Outbox] broadcast swallowed', [
            'event'           => 'outbox.broadcast.swallowed',
            'severity'        => 'pager_grade',
            'domain_event_id' => $event->domainEventId,
            'event_type'      => $event->eventType,
            'aggregate_id'    => $event->aggregateId,
            'branch_id'       => $event->branchId,
            'listener'        => $event->listener,
            'error_message'   => $event->errorMessage,
            'failed_at'       => $event->failedAt->format(\DateTimeInterface::ATOM),
        ]);
    }
}
