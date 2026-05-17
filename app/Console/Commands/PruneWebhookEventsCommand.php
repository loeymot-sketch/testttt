<?php

namespace App\Console\Commands;

use App\Models\WebhookEvent;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [RED-team P0 — webhook_events unbounded growth 2026-05-17]
 *
 * Prunes `webhook_events` rows in terminal/safe states older than N days.
 *
 * Safe-set: status IN ('processed', 'duplicate') AND received_at < cutoff.
 *
 * DO NOT prune:
 *  - status='pending'  → handler may still be retrying.
 *  - status='failed'   → DLQ retry lane (foodking:webhook:retry-failed)
 *                        owns those; deleting silently destroys recovery
 *                        evidence + idempotency anchor for replays.
 *
 * The UNIQUE(provider, webhook_id) idempotency contract is unaffected by
 * pruning processed rows: provider replays older than the retention window
 * are reprocessed as fresh (acceptable behavior per Stripe/SenangPay docs;
 * 90d window exceeds any provider replay horizon).
 *
 * NF525 invariant: `webhook_events` is an OPERATIONAL ledger, NOT a fiscal
 * audit table. Fiscal payment evidence lives on `order_payments` +
 * `audit_logs` (6y retention) — NEVER touched here. See CLAUDE.md §8.
 *
 * Idempotent + chunked + cursor-stable.
 */
class PruneWebhookEventsCommand extends Command
{
    protected $signature = 'foodking:webhook:prune'
        . ' {--older-than-days=90 : Rows older than N days are eligible}'
        . ' {--batch=1000 : Max rows deleted per chunk (lock-window control)}'
        . ' {--dry-run : Report counts without deleting}';

    protected $description = 'Prune processed + duplicate webhook_events rows (DLQ-safe).';

    public function handle(): int
    {
        $days = max(1, (int) $this->option('older-than-days'));
        $batch = max(100, (int) $this->option('batch'));
        $dryRun = (bool) $this->option('dry-run');
        $cutoff = now()->subDays($days);

        $safeStatuses = [
            WebhookEvent::STATUS_PROCESSED,
            WebhookEvent::STATUS_DUPLICATE,
        ];

        $applyPredicate = function ($query) use ($cutoff, $safeStatuses) {
            return $query->whereIn('status', $safeStatuses)
                ->where('received_at', '<', $cutoff);
        };

        $total = (int) $applyPredicate(DB::table('webhook_events'))->count();

        if ($dryRun) {
            $this->info(sprintf(
                '[dry-run] %d webhook_events row(s) eligible (older than %d day(s), batch=%d).',
                $total,
                $days,
                $batch
            ));
            Log::channel('observability')->info('webhook.prune.dry_run', [
                'event' => 'webhook.prune.dry_run',
                'eligible' => $total,
                'older_than_days' => $days,
            ]);

            return self::SUCCESS;
        }

        $deletedTotal = 0;
        do {
            $deleted = $applyPredicate(DB::table('webhook_events'))
                ->limit($batch)
                ->delete();
            $deletedTotal += $deleted;
        } while ($deleted > 0);

        $this->info(sprintf(
            'Webhook events pruned: %d row(s) deleted / %d eligible (older than %d day(s)).',
            $deletedTotal,
            $total,
            $days
        ));

        Log::channel('observability')->info('webhook.prune.completed', [
            'event' => 'webhook.prune.completed',
            'deleted' => $deletedTotal,
            'eligible' => $total,
            'older_than_days' => $days,
        ]);

        return self::SUCCESS;
    }
}
