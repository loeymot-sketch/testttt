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
                $q->where(function ($inner) use ($cutoff) {
                    $inner->whereNotNull('dispatched_at')
                        ->where('dispatched_at', '<', $cutoff);
                })->orWhere(function ($inner) use ($cutoff) {
                    $inner->where('attempts', '>=', 6)
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
