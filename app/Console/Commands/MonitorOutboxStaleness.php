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

        $staleCount = (int) DB::table('domain_events')
            ->where('created_at', '<', $cutoff)
            ->whereNull('dispatched_at')
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

        $crashClaimedCount = (int) DB::table('domain_events')
            ->whereNotNull('dispatched_at')
            ->whereNotNull('last_error')
            ->where('attempts', '>=', 5)
            ->where('dispatched_at', '<', $orphanCutoff)
            ->count();

        if ($staleCount <= $threshold && $crashClaimedCount === 0) {
            $this->info("[OK] {$staleCount} stale outbox events (threshold: {$threshold}, stale_after: {$staleAfter}s). 0 crash-claimed orphans.");

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

        // [H3] Oldest crash-claimed orphan — surfaced so ops can re-drive it
        // manually (it cannot be reached by any automatic re-queue lane).
        $oldestOrphan = DB::table('domain_events')
            ->whereNotNull('dispatched_at')
            ->whereNotNull('last_error')
            ->where('attempts', '>=', 5)
            ->where('dispatched_at', '<', $orphanCutoff)
            ->orderBy('dispatched_at')
            ->first(['id', 'event_type', 'dispatched_at', 'attempts', 'last_error']);

        $context = [
            'event' => 'outbox.staleness.alert',
            'stale_count' => $staleCount,
            'crash_claimed_count' => $crashClaimedCount,
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

        $message = "[OUTBOX STALE] {$staleCount} undispatched events older than {$staleAfter}s "
            . "(threshold: {$threshold}) + {$crashClaimedCount} crash-claimed orphans. "
            . 'If stale_count is high: queue worker may be down — verify '
            . '`php artisan queue:work --queue=high` is running (docs/REALTIME_SETUP.md). '
            . 'If crash_claimed_count is high: those rows are claimed-but-never-broadcast '
            . 'and are UNREACHABLE by retry-failed/rescue — re-drive them MANUALLY '
            . '(e.g. `DispatchDomainEventsJob::dispatch($id)` after nulling dispatched_at).';

        Log::error($message, $context);
        $this->error($message);

        return self::FAILURE;
    }
}
