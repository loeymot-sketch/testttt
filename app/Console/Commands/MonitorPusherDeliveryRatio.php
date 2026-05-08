<?php

namespace App\Console\Commands;

use App\Services\Sync\PusherDeliveryRatioMonitor;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * [PRE-PROD HARDENING / SYN-2 / P0-7]
 *
 * Scheduled tick (every 5 minutes via Console\Kernel) that asks
 * `PusherDeliveryRatioMonitor` for the current ratio and emits a
 * `Log::error` line — and a non-zero exit code — when the ratio drops
 * below threshold.
 *
 * The exit code matters: when wrapped in a supervisor / systemd /
 * Datadog-monitored cron, a non-zero return surfaces as a metric
 * `pusher_monitor.failure` that operators can alert on. The log line is
 * the human-readable companion.
 *
 * `--threshold` is exposed for manual debugging; production schedule
 * uses the service's `DEFAULT_THRESHOLD` constant via no override.
 */
class MonitorPusherDeliveryRatio extends Command
{
    protected $signature = 'foodking:pusher:monitor
        {--threshold= : Override the default ratio threshold (e.g. 0.95)}
        {--window=5 : Sliding window in minutes}';

    protected $description = 'Alert if Pusher delivery ratio dropped below threshold (SYN-2 P0-7)';

    public function handle(PusherDeliveryRatioMonitor $monitor): int
    {
        $threshold = $this->option('threshold') !== null
            ? (float) $this->option('threshold')
            : PusherDeliveryRatioMonitor::DEFAULT_THRESHOLD;

        $window = max(1, (int) $this->option('window'));

        $result = $monitor->calculate($window);

        // Re-evaluate `healthy` against the runtime threshold so the
        // operator can probe with `--threshold=0.95` without changing
        // the stored monitor default.
        $healthy = $result['dispatched'] < PusherDeliveryRatioMonitor::SMALL_N_TOLERANCE
            || $result['ratio'] >= $threshold;

        $msg = sprintf(
            '[PUSHER %s] ratio=%s (threshold=%s, window=%dmin, dispatched=%d, acked_distinct=%d)',
            $healthy ? 'OK' : 'DEGRADED',
            $result['ratio'],
            $threshold,
            $window,
            $result['dispatched'],
            $result['acked_distinct']
        );

        if (! $healthy) {
            Log::error($msg, $result);
            $this->error($msg);
            return self::FAILURE;
        }

        $this->info($msg);
        return self::SUCCESS;
    }
}
