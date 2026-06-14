<?php

namespace App\Listeners;

use App\Enums\EventType;
use App\Events\BranchStatusChanged;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * [T-6.4 — GOAL_V1_PRODUCTION_PERFECT_PHASE2_2026-05-18.md §8 Sub 6.4]
 *
 * Persiste un row `domain_events` lors d'un {@see BranchStatusChanged}. Mirror
 * du pattern Wave 5G {@see PersistSettingsUpdatedToOutbox} avec 5 adaptations :
 *
 *   1. Single-branch (pas de fan-out — l'événement transporte un scalar branchId)
 *   2. idempotency_key inclut le tuple status ($oldStatus -> $newStatus)
 *      pour qu'un ACTIVE→INACTIVE→ACTIVE legitime ne se collapse pas en 1 row
 *   3. payload = {branch_id, old_status, new_status} (numeric — consumers KDS/POS
 *      parse status directement, pas de string-parsing)
 *   4. Fire sur TOUTE transition réelle (ACTIVE↔INACTIVE des 2 sens), pas seulement
 *      INACTIVE-only comme le sibling {@see RevokeTokensOnBranchDeactivated} —
 *      Outbox est un broadcast contract, pas une action destructive.
 *   5. Registration AFTER RevokeTokensOnBranchDeactivated dans EventServiceProvider
 *      (tokens supprimés AVANT que le job async dispatche le broadcast Pusher).
 *
 * Closes Z7-V1.0.2-P2-01 (BranchStatusChanged not in domain_events — cross-surface
 * consumers ne pouvaient pas react via outbox replay).
 *
 * L'event {@see BranchStatusChanged} utilise déjà le trait DispatchableAfterCommit,
 * donc le listener s'exécute APRÈS commit de la transaction outer (BranchController::update).
 * On NE déclare PAS `ShouldHandleEventsAfterCommit` interface — double-deferral
 * non nécessaire.
 */
class PersistBranchStatusChangedToOutbox
{
    use \App\Listeners\Concerns\GuardsOutboxPersistence;

    public function handle(BranchStatusChanged $event): void
    {
        $this->runOutboxPersistenceGuarded(self::class, fn () => $this->project($event));
    }

    private function project(BranchStatusChanged $event): void
    {
        // Guard 1 : no-op si pas de transition réelle (sibling pattern).
        if ($event->oldStatus === $event->newStatus) {
            return;
        }

        $branchId = (int) $event->branchId;
        $oldStatus = (int) $event->oldStatus;
        $newStatus = (int) $event->newStatus;
        $correlationId = $this->resolveCorrelationId();

        // Idempotency key inclut le tuple status pour distinguer
        // une transition ACTIVE→INACTIVE d'un INACTIVE→ACTIVE ultérieur.
        $idempotencyKey = sha1(implode('|', [
            EventType::BRANCH_STATUS_CHANGED,
            $branchId,
            $oldStatus . '->' . $newStatus,
            $correlationId,
        ]));

        $domainEvent = DomainEvent::query()->firstOrCreate(
            ['idempotency_key' => $idempotencyKey],
            [
                'event_type'     => EventType::BRANCH_STATUS_CHANGED,
                'aggregate_type' => 'branch',
                'aggregate_id'   => $branchId,
                'branch_id'      => $branchId,
                'payload'        => [
                    'branch_id'  => $branchId,
                    'old_status' => $oldStatus,
                    'new_status' => $newStatus,
                ],
                'channel'        => json_encode(['private-branch.' . $branchId]),
                'broadcast_as'   => 'BranchStatusChanged',
                'correlation_id' => $correlationId,
                'occurred_at'    => now(),
            ]
        );

        if (! $domainEvent->wasRecentlyCreated) {
            return; // dedup hit — no log noise, no double-broadcast
        }

        // Forensic trail (sibling RevokeTokensOnBranchDeactivated uses same channel).
        Log::channel(config('logging.channels.security') ? 'security' : 'stack')->info(
            '[T-6.4] BranchStatusChanged outbox persisted',
            [
                'event'           => 'branch.status_changed.outbox_persisted',
                'branch_id'       => $branchId,
                'old_status'      => $oldStatus,
                'new_status'      => $newStatus,
                'correlation_id'  => $correlationId,
                'domain_event_id' => (int) $domainEvent->id,
                'gate'            => 'goal-v1-phase2-t6-4',
            ]
        );

        $domainEventId = (int) $domainEvent->id;

        // Best-effort inline broadcast (sibling defense — outbox retry cron
        // récupère les rows avec dispatched_at=null si broadcaster recovers).
        DB::afterCommit(function () use ($domainEventId): void {
            try {
                DispatchDomainEventsJob::dispatch($domainEventId);
            } catch (\Throwable $broadcastException) {
                Log::warning('[Outbox] DispatchDomainEventsJob inline dispatch failed (non-blocking)', [
                    'gate'            => 'goal-v1-phase2-t6-4',
                    'domain_event_id' => $domainEventId,
                    'event_type'      => EventType::BRANCH_STATUS_CHANGED,
                    'error'           => $broadcastException->getMessage(),
                ]);
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
