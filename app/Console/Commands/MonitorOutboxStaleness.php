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

        if ($staleCount <= $threshold) {
            $this->info("[OK] {$staleCount} stale outbox events (threshold: {$threshold}, stale_after: {$staleAfter}s).");

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

        $context = [
            'event' => 'outbox.staleness.alert',
            'stale_count' => $staleCount,
            'threshold' => $threshold,
            'stale_after_seconds' => $staleAfter,
            'oldest_id' => $oldest->id ?? null,
            'oldest_event_type' => $oldest->event_type ?? null,
            'oldest_created_at' => $oldest->created_at ?? null,
            'oldest_attempts' => $oldest->attempts ?? null,
            'oldest_last_error' => $oldest->last_error ?? null,
        ];

        $message = "[OUTBOX STALE] {$staleCount} undispatched events older than {$staleAfter}s "
            . "(threshold: {$threshold}). Queue worker may be down. "
            . 'Verify `php artisan queue:work --queue=high` is running and check docs/REALTIME_SETUP.md.';

        Log::error($message, $context);
        $this->error($message);

        return self::FAILURE;
    }
}
