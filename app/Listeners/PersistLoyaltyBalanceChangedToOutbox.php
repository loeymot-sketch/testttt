<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\LoyaltyBalanceChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * [GOAL LOYALTY_UNIFIED_SYNC L2 2026-06-11]
 *
 * Mirrors {@see PersistKdsOrderRecalledToOutbox} exactly (the canonical
 * outbox listener shape): domain_events row + inline DispatchDomainEventsJob
 * after commit, cron replay on failure (no data loss invariant, SYNC_CONTRACT §7).
 *
 * Idempotency: keyed by (event_type, user_id, balance_after, reason,
 * correlation_id) — a listener replay collapses; a genuine second movement in
 * another request carries a new correlation id and gets a fresh row.
 */
class PersistLoyaltyBalanceChangedToOutbox
{
    use \App\Listeners\Concerns\GuardsOutboxPersistence;

    public function handle(LoyaltyBalanceChanged $event): void
    {
        $this->runOutboxPersistenceGuarded(self::class, fn () => $this->project($event));
    }

    private function project(LoyaltyBalanceChanged $event): void
    {
        $correlationId = $event->correlationId ?: $this->resolveCorrelationId();

        $idempotencyKey = sha1(implode('|', [
            EventType::LOYALTY_BALANCE_CHANGED,
            $event->userId,
            $event->balanceAfter,
            $event->reason,
            $correlationId,
        ]));

        $domainEvent = DomainEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type'     => EventType::LOYALTY_BALANCE_CHANGED,
                'aggregate_type' => 'App\\Models\\User',
                'aggregate_id'   => $event->userId,
                'branch_id'      => $event->branchId,
                'payload'        => [
                    // Balance-only payload — NO PII (no name/phone/loyalty_code).
                    'user_id'       => $event->userId,
                    'branch_id'     => $event->branchId,
                    'balance_after' => $event->balanceAfter,
                    'delta'         => $event->delta,
                    'reason'        => $event->reason,
                ],
                'channel'        => json_encode(['private-branch.' . $event->branchId]),
                'broadcast_as'   => 'LoyaltyBalanceChanged',
                'correlation_id' => $correlationId,
                'occurred_at'    => now(),
            ]
        );

        if (! $domainEvent->wasRecentlyCreated) {
            return;
        }

        DB::afterCommit(function () use ($domainEvent): void {
            try {
                DispatchDomainEventsJob::dispatch($domainEvent->id);
            } catch (\Throwable $broadcastException) {
                Log::error('[Outbox] DispatchDomainEventsJob inline dispatch failed for LoyaltyBalanceChanged (non-blocking, retries via cron)', [
                    'gate'            => 'LOYALTY-SYNC-L2',
                    'domain_event_id' => $domainEvent->id,
                    'error'           => $broadcastException->getMessage(),
                ]);
            }
        });
    }

    private function resolveCorrelationId(): string
    {
        return (string) (request()?->header('X-Correlation-Id') ?: Str::uuid());
    }
}
