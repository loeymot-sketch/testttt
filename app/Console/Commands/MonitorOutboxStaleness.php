<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [AUDIT-F-015] Outbox staleness monitor.
 *
 * Complements `OutboxRescueCommand` (which RE-QUEUES stale events when the
 * queue is alive but a single message got stuck). This command instead
 * RAISES AN ALERT when the count of stale events crosses an operator-tunable
 * threshold — the "queue worker is down entirely" signal.
 *
 * Why a separate command from rescue:
 *   - rescue uses `DispatchDomainEventsJob::dispatch($id)` which itself
 *     enqueues onto the same `high` lane. If the worker is down, rescue
 *     also goes nowhere → no alert is ever surfaced.
 *   - This command never enqueues; it only reads + logs. A failing exit
 *     code propagates to the supervisor (cron / Horizon scheduler),
 *     which then fires whatever pager backend the operator has wired.
 *
 * Scheduled every minute in `app/Console/Kernel.php` with
 * `withoutOverlapping()` + `onOneServer()` so the alert latency is bounded
 * and we don't double-page across nodes.
 */
class MonitorOutboxStaleness extends Command
{
    protected $signature = 'foodking:outbox:monitor
                            {--threshold=10 : Stale event count alert threshold}
                            {--stale-after=30 : Seconds after which an undispatched event counts as stale}';

    protected $description = 'Monitor outbox staleness and raise an alert (Log::error + non-zero exit) when the queue worker pipeline is degraded.';

    public function handle(): int
    {
        $threshold = max(0, (int) $this->option('threshold'));
        $staleAfter = max(1, (int) $this->option('stale-after'));

        $cutoff = now()->subSeconds($staleAfter);

        // [NUIT-A 2026-07-03 / P2 alarme désensibilisée] Le signal « worker down » ne doit compter QUE les
        // events encore dans la fenêtre de re-queue automatique (attempts < 5 = repris par outbox:rescue).
        // Auparavant il comptait TOUS les pending → les orphelins terminaux (attempts >= 5, jamais repris par
        // aucune lane) s'accumulaient et maintenaient l'alarme en FAILURE permanent → fatigue d'alerte →
        // une VRAIE panne worker était masquée. Les terminaux sont désormais une dimension DEAD-LETTER
        // distincte (ci-dessous), pas le signal « worker down ».
        $staleCount = (int) DB::table('domain_events')
            ->where('created_at', '<', $cutoff)
            ->whereNull('dispatched_at')
            ->where('attempts', '<', 5)
            ->count();

        // [GOAL-sync-ordertaking 2026-05-29 H3] Crash-claimed orphan class.
        // A worker that died between Phase-1 claim (dispatched_at set) and a
        // successful Phase-2 broadcast leaves dispatched_at != NULL WITH
        // last_error set (from a prior attempt). `scopePending` (dispatched_at
        // IS NULL) excludes these, so the staleness count above is blind to
        // them — AND so is `outbox:retry-failed` (scopeFailed -> pending ->
        // whereNull), while `outbox:rescue` lane-B only re-queues attempts<5.
        // A row with attempts>=5 + last_error set therefore falls through EVERY
        // re-queue lane and the operator is never paged. Count it as a distinct
        // alarm dimension.
        //
        // [RED-team A.2 fix 2026-05-29] The age gate MUST be longer than
        // stale-after (30s) — otherwise a LIVE worker re-driven by
        // outbox:retry-failed (which nulls dispatched_at then re-claims,
        // carrying the prior last_error since Phase 1 does NOT clear it) that
        // hangs >30s on a slow Pusher broadcast would be falsely paged as an
        // orphan. Reuse rescue lane-B's 10-min threshold: it exceeds the worst
        // backoff curve (1+5+15+60+300 ≈ 6.4 min) + a broadcast hang, so a row
        // still claimed past 10 min cannot belong to a healthy in-flight worker.
        // Precision: DispatchDomainEventsJob Phase-3a clears last_error on
        // success, so a healthy dispatched row never matches regardless of age.
        $orphanCutoff = now()->subSeconds(max($staleAfter, 600));

        // [SYNC-P2-1 2026-08-04] Détection via `broadcast_at IS NULL` (marqueur de LIVRAISON réelle)
        // au lieu de `last_error NOT NULL` + `attempts >= 5`. L'ancien filtre RATAIT l'orphelin le
        // plus dangereux : un worker tué EN PLEIN broadcast laisse `dispatched_at` posé, `last_error`
        // NULL — jamais alerté, puis pruné à 90j comme « livré ». Désormais : claimé (dispatched_at)
        // il y a > orphanCutoff SANS livraison (broadcast_at null) = orphelin, quelle que soit
        // l'erreur/le compteur. Un worker sain pose broadcast_at en secondes → jamais faux positif.
        // [SYNC-P2-1 fix 2026-08-05 · audit RED L4] Exclure les POISON (contract_violation) comme le
        // font TOUS les siblings (staleCount:53, rescue, HealthController). Le rekeying broadcast_at
        // avait laissé tomber cette exclusion → un CV historique (dispatched_at posé, broadcast_at
        // NULL — cf. backfill migration) devenait une ALARME ÉTERNELLE (rescue skip CV, prune ne peut
        // pas retirer un dispatched_at-posé) = fatigue d'alerte masquant une VRAIE panne worker. Un CV
        // est un échec DÉLIBÉRÉ non-recouvrable, pas un orphelin crash à récupérer.
        $crashClaimedCount = (int) DB::table('domain_events')
            ->whereNotNull('dispatched_at')
            ->whereNull('broadcast_at')
            ->where('dispatched_at', '<', $orphanCutoff)
            ->where(function ($q): void {
                $q->whereNull('last_error')->orWhere('last_error', 'not like', 'contract_violation%');
            })
            ->count();

        // [NUIT-A 2026-07-03 / P2] DEAD-LETTER : pending + attempts >= 5. Ces rows ont épuisé la fenêtre de
        // re-queue (outbox:rescue ne reprend que attempts < 5) sans jamais réussir, et prune ne supprime
        // qu'à attempts >= 6 → elles restent orphelines. Dimension distincte du « worker down » : elles
        // exigent une action MANUELLE (re-drive), pas un redémarrage de worker. Gate d'âge (orphanCutoff)
        // pour ne pas alerter sur un event tout juste passé à attempts=5 encore en cours de traitement.
        $deadLetterCount = (int) DB::table('domain_events')
            ->whereNull('dispatched_at')
            ->where('attempts', '>=', 5)
            ->where('created_at', '<', $orphanCutoff)
            ->count();

        if ($staleCount <= $threshold && $crashClaimedCount === 0 && $deadLetterCount === 0) {
            $this->info("[OK] {$staleCount} stale outbox events (threshold: {$threshold}, stale_after: {$staleAfter}s). 0 crash-claimed orphans, 0 dead-letter.");

            return self::SUCCESS;
        }

        // [AUDIT-F-015] Pull the oldest stuck row so the operator sees the
        // age + event_type in the alert payload. Single targeted query —
        // does not scan the table beyond what `idx_pending` already covers.
        $oldest = DB::table('domain_events')
            ->where('created_at', '<', $cutoff)
            ->whereNull('dispatched_at')
            ->orderBy('created_at')
            ->first(['id', 'event_type', 'created_at', 'attempts', 'last_error']);

        // [H3] Oldest crash-claimed orphan — surfaced so ops can re-drive it manually.
        // [SYNC-P2-1 2026-08-04] Predicate MUST mirror $crashClaimedCount (broadcast_at IS
        // NULL) — otherwise a first-attempt crash orphan (last_error NULL) trips the count
        // but yields a null detail in the alert payload. Consistency = actionable page.
        $oldestOrphan = DB::table('domain_events')
            ->whereNotNull('dispatched_at')
            ->whereNull('broadcast_at')
            ->where('dispatched_at', '<', $orphanCutoff)
            ->where(function ($q): void {
                // Miroir EXACT de $crashClaimedCount (dont l'exclusion CV) → le détail reste cohérent.
                $q->whereNull('last_error')->orWhere('last_error', 'not like', 'contract_violation%');
            })
            ->orderBy('dispatched_at')
            ->first(['id', 'event_type', 'dispatched_at', 'attempts', 'last_error']);

        $context = [
            'event' => 'outbox.staleness.alert',
            'stale_count' => $staleCount,
            'crash_claimed_count' => $crashClaimedCount,
            'dead_letter_count' => $deadLetterCount,
            'threshold' => $threshold,
            'stale_after_seconds' => $staleAfter,
            'oldest_id' => $oldest->id ?? null,
            'oldest_event_type' => $oldest->event_type ?? null,
            'oldest_created_at' => $oldest->created_at ?? null,
            'oldest_attempts' => $oldest->attempts ?? null,
            'oldest_last_error' => $oldest->last_error ?? null,
            'oldest_orphan_id' => $oldestOrphan->id ?? null,
            'oldest_orphan_event_type' => $oldestOrphan->event_type ?? null,
            'oldest_orphan_dispatched_at' => $oldestOrphan->dispatched_at ?? null,
            'oldest_orphan_attempts' => $oldestOrphan->attempts ?? null,
        ];

        $message = "[OUTBOX STALE] {$staleCount} undispatched retryable events older than {$staleAfter}s "
            . "(threshold: {$threshold}) + {$crashClaimedCount} crash-claimed orphans + {$deadLetterCount} dead-letter. "
            . 'If stale_count is high: queue worker may be down — verify '
            . '`php artisan queue:work --queue=high,default` is running (docs/REALTIME_SETUP.md). '
            . 'If crash_claimed_count is high: those rows are claimed-but-never-broadcast '
            . 'and are UNREACHABLE by retry-failed/rescue — re-drive them MANUALLY '
            . '(e.g. `DispatchDomainEventsJob::dispatch($id)` after nulling dispatched_at). '
            . 'If dead_letter_count is high: pending rows that exhausted the rescue window (attempts>=5) '
            . 'and are not worker-down — re-drive or purge them manually.';

        Log::error($message, $context);
        $this->error($message);

        return self::FAILURE;
    }
}
