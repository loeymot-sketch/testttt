<?php

namespace App\Services\Outbox;

use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Services\Fiscal\AuditLogService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Sub 5.2 · Codex P1-K]
 *
 * UN seul chemin pour rejouer un événement outbox, que l'ordre vienne du cron
 * (`foodking:outbox:retry-failed`) ou d'un humain dans le cockpit (`POST
 * /admin/observability/outbox/retry-failed`). Avant : deux boucles divergentes — la
 * commande tenait un verrou et écrivait une ligne `audit_logs` NF525 par relance, le
 * bouton web ne faisait ni l'un ni l'autre et remettait `attempts=0`/`last_error=NULL`
 * (le flapping que le heal B.1 du 2026-05-19 avait justement supprimé côté commande).
 *
 * Invariants conservés ici (verrouillés par OutboxConcurrentRetryLockTest,
 * OutboxReplayAuditTest, OutboxRetryFailedAttemptsPreservedTest) :
 *  - audit AVANT dispatch : pas de ligne d'audit → pas de relance de CET événement,
 *    mais la boucle continue ;
 *  - dispatch qui lève → la ligne d'audit reste (elle dit qu'une relance a été TENTÉE),
 *    la boucle continue ;
 *  - `attempts` monotone (le job l'incrémente au claim), `last_error` conservé jusqu'à
 *    ce que le job le remplace ou l'efface en Phase 3a ;
 *  - seul `dispatched_at` est remis à NULL pour que la Phase 1 (`lockForUpdate` +
 *    garde `dispatched_at`) puisse re-claimer la ligne ;
 *  - dispatch-après-commit : `DispatchDomainEventsJob::dispatch()` est appelé hors de
 *    toute transaction ouverte par ce service.
 */
final class OutboxReplayService
{
    /** Même clé que la commande : cron et bouton web s'excluent mutuellement. */
    public const LOCK_KEY = 'outbox.retry-failed.lock';

    public const LOCK_TTL_SECONDS = 300;

    public function __construct(private readonly AuditLogService $auditLog)
    {
    }

    public function lock(): Lock
    {
        return Cache::lock(self::LOCK_KEY, self::LOCK_TTL_SECONDS);
    }

    /**
     * Rejoue les événements donnés, dans l'ordre.
     *
     * @param  Collection<int, DomainEvent>  $events
     * @param  string  $source  Identifiant du chemin d'appel, écrit dans l'audit
     *                          (`foodking:outbox:retry-failed` ou `admin:outbox:retry-failed`).
     * @param  int|null  $actorId  Utilisateur humain à l'origine de la relance ; null pour le cron.
     * @return array{requeued:int, audit_failed:int, dispatch_failed:int}
     */
    public function replay(Collection $events, string $source, ?int $actorId = null): array
    {
        $requeued = 0;
        $auditFailed = 0;
        $dispatchFailed = 0;

        foreach ($events as $event) {
            try {
                $this->auditLog->write([
                    'branch_id' => (int) ($event->branch_id ?? 0),
                    'user_id' => $actorId,
                    'action' => 'outbox.replay',
                    'resource' => 'domain_event',
                    'resource_id' => (int) $event->id,
                    'payload' => [
                        'command' => $source,
                        'event_id' => (int) $event->id,
                        'event_type' => (string) $event->event_type,
                        'aggregate_type' => (string) ($event->aggregate_type ?? ''),
                        'aggregate_id' => (int) ($event->aggregate_id ?? 0),
                        'correlation_id' => (string) ($event->correlation_id ?? ''),
                        'attempts_before' => (int) ($event->attempts ?? 0),
                        'last_error_before' => $event->last_error !== null ? mb_substr((string) $event->last_error, 0, 200) : null,
                    ],
                ]);
            } catch (\Throwable $e) {
                Log::channel('fiscal')->error('Outbox replay audit_log write failed', [
                    'event_id' => (int) $event->id,
                    'source' => $source,
                    'error' => $e->getMessage(),
                ]);
                $auditFailed++;

                continue; // pas d'audit → pas de relance de cet événement
            }

            try {
                $event->forceFill(['dispatched_at' => null])->save();
                DispatchDomainEventsJob::dispatch($event->id);
                $requeued++;
            } catch (\Throwable $e) {
                Log::channel('fiscal')->error('Outbox replay dispatch failed (audit row exists)', [
                    'event_id' => (int) $event->id,
                    'source' => $source,
                    'error' => $e->getMessage(),
                ]);
                $dispatchFailed++;
            }
        }

        return [
            'requeued' => $requeued,
            'audit_failed' => $auditFailed,
            'dispatch_failed' => $dispatchFailed,
        ];
    }
}
