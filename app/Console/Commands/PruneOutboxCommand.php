<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [RED-team P0 — Outbox unbounded growth 2026-05-17]
 *
 * Prunes `domain_events` rows that have been safely dispatched OR have
 * exhausted retry attempts past the rescue/retry-failed window.
 *
 * Safe-set (UNION):
 *   (A) dispatched_at IS NOT NULL AND dispatched_at < cutoff
 *       → broadcast succeeded, row is pure history.
 *   (B) attempts >= 6 AND created_at < cutoff
 *       → terminal failure past the retry-failed --since=24h lane. The
 *         staleness monitor (`foodking:outbox:monitor`) pages humans for
 *         these BEFORE prune (90d default = far past any operational
 *         triage window). Without this clause, abandoned failures grow
 *         forever.
 *   (C) dispatched_at IS NULL AND last_error LIKE 'contract_violation%'
 *       AND created_at < cutoff
 *       → terminal CONTRACT VIOLATION. DispatchDomainEventsJob
 *         short-circuits PayloadMismatchException via `$this->fail()` on the
 *         FIRST failure (app/Jobs/DispatchDomainEventsJob.php:168-187), so
 *         the row freezes with dispatched_at=NULL AND a low attempts count
 *         (2-4, never reaching 6). It therefore matches NEITHER (A) nor (B)
 *         and would live FOREVER (17 such legacy rows observed immortal since
 *         2026-06-17). These are malformed payloads that can never dispatch;
 *         purge them once past the same retention cutoff. NF525-safe: they
 *         hold no fiscal value (a rejected envelope was never a fiscal event).
 *
 * NF525 invariant: `domain_events` is an OPERATIONAL outbox, NOT a fiscal
 * audit table. `audit_logs` + `z_reports` (6y retention) are NEVER touched
 * by this command. See CLAUDE.md §8.
 *
 * Idempotent (safe re-run) — second invocation matches an empty set.
 * Chunked delete (LIMIT batch) prevents long table locks on large
 * backlogs. Cursor-stable: we delete by predicate, not by ID range.
 */
class PruneOutboxCommand extends Command
{
    protected $signature = 'foodking:outbox:prune'
        . ' {--older-than-days=90 : Rows older than N days are eligible}'
        . ' {--batch=1000 : Max rows deleted per chunk (lock-window control)}'
        . ' {--dry-run : Report counts without deleting}';

    protected $description = 'Prune dispatched + terminally-failed domain_events rows (NF525-safe).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('older-than-days'));
        $batch = max(100, (int) $this->option('batch'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        // Safe-set query — kept in a closure so dry-run + delete share one source of truth.
        $applyPredicate = function ($query) use ($cutoff) {
            return $query->where(function ($q) use ($cutoff) {
                // (A) LIVRÉ avec succès — historique pur au-delà de la fenêtre.
                // [SYNC-P2-1 2026-08-04] Clé sur `broadcast_at` (LIVRAISON réelle) et NON
                // `dispatched_at` (CLAIM) : sinon un orphelin claimé-mais-jamais-livré (worker
                // tué en plein broadcast, broadcast_at null) était SUPPRIMÉ à 90j comme « livré »
                // → perte d'événement définitive. NB : cette lane A ne prune que ce qui a réellement
                // été diffusé ; les échecs TERMINAUX non-livrés sont gérés séparément par (B)/(C)
                // (attempts>=6 / contract_violation) après 90j de paging = DLQ cleanup voulu.
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNotNull('broadcast_at')
                        ->where('broadcast_at', '<', $cutoff);
                // (B) terminal runtime failure — retries exhausted (attempts >= 6).
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->where('attempts', '>=', 6)
                        ->where('created_at', '<', $cutoff);
                // (C) terminal CONTRACT VIOLATION — short-circuited via $this->fail()
                //     on the first failure, so it freezes at dispatched_at=NULL with
                //     attempts < 6 and matches neither (A) nor (B). See class docblock.
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->whereNull('dispatched_at')
                        ->where('last_error', 'like', 'contract_violation%')
                        ->where('created_at', '<', $cutoff);
                });
            });
        };

        $total = (int) $applyPredicate(DB::table('domain_events'))->count();

        if ($dryRun) {
            $this->info(sprintf(
                '[dry-run] %d domain_events row(s) eligible (older than %d day(s), batch=%d).',
                $total,
                $days,
                $batch
            ));
            Log::channel('observability')->info('outbox.prune.dry_run', [
                'event' => 'outbox.prune.dry_run',
                'eligible' => $total,
                'older_than_days' => $days,
            ]);

            return self::SUCCESS;
        }

        $deletedTotal = 0;
        do {
            $deleted = $applyPredicate(DB::table('domain_events'))
                ->limit($batch)
                ->delete();
            $deletedTotal += $deleted;
        } while ($deleted > 0);

        $this->info(sprintf(
            'Outbox pruned: %d row(s) deleted / %d eligible (older than %d day(s)).',
            $deletedTotal,
            $total,
            $days
        ));

        Log::channel('observability')->info('outbox.prune.completed', [
            'event' => 'outbox.prune.completed',
            'deleted' => $deletedTotal,
            'eligible' => $total,
            'older_than_days' => $days,
        ]);

        return self::SUCCESS;
    }
}
