<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;

/**
 * [PRE-PROD HARDENING / SYN-2 / P0-7]
 *
 * Computes the "Pusher delivery ratio" — the fraction of envelopes
 * dispatched on the outbox that produced at least one client ack on
 * the same window. The companion `MonitorPusherDeliveryRatio` console
 * command runs this on a 5-minute schedule and alerts when the ratio
 * drops below 90% (default).
 *
 * Distinct vs. total: `domain_events.dispatched_at` is set once per
 * envelope by `DispatchDomainEventsJob`. Several clients (POS + KDS +
 * OSS + kiosk) may all ack the same envelope. We count DISTINCT
 * `correlation_id` on the ack side and compare with the dispatched
 * count — by construction `acked_distinct ≤ dispatched`. A ratio of
 * 1.0 means every dispatched event was acked by ≥1 client.
 *
 * Small-N tolerance: with a low traffic floor (< 5 dispatched events
 * in 5 minutes) the ratio is statistically meaningless and we report
 * `healthy = true`. Otherwise a single missing ack at 3 a.m. would
 * page the operator — exactly the kind of false positive that erodes
 * trust in the monitor.
 *
 * Driver-portability: Laravel's `->distinct(...)->count(...)` chain is
 * brittle across drivers (it has been re-implemented several times
 * upstream). We sidestep it with an explicit `COUNT(DISTINCT col)`
 * raw expression, which is identical SQL on MySQL, MariaDB, Postgres,
 * SQLite and SQL Server.
 */
class PusherDeliveryRatioMonitor
{
    public const DEFAULT_THRESHOLD = 0.90;

    public const SMALL_N_TOLERANCE = 5;

    /**
     * @param  int       $windowMinutes  Sliding window size, in minutes.
     * @param  int|null  $branchId       Restrict to a single branch (null = global).
     * @return array{
     *   window_minutes:int,
     *   branch_id:?int,
     *   dispatched:int,
     *   acked_distinct:int,
     *   ratio:float,
     *   threshold:float,
     *   healthy:bool,
     *   computed_at:string
     * }
     */
    public function calculate(int $windowMinutes = 5, ?int $branchId = null): array
    {
        $since = now()->subMinutes($windowMinutes);

        // Count envelopes flushed by DispatchDomainEventsJob.
        $dispatchedQuery = DB::table('domain_events')
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '>=', $since);

        if ($branchId !== null) {
            $dispatchedQuery->where('branch_id', $branchId);
        }

        $dispatched = (int) $dispatchedQuery->count();

        // Count DISTINCT correlation_ids that produced at least one ack.
        // Use a raw expression: ->distinct()->count() is portable but its
        // semantics keep shifting between Laravel minor versions, so we
        // pin the SQL.
        $ackedQuery = DB::table('pusher_event_acks')
            ->where('received_at', '>=', $since);

        if ($branchId !== null) {
            $ackedQuery->where('branch_id', $branchId);
        }

        $ackedDistinct = (int) (clone $ackedQuery)
            ->select(DB::raw('COUNT(DISTINCT correlation_id) AS cnt'))
            ->value('cnt');

        $ratio = $dispatched > 0 ? ($ackedDistinct / $dispatched) : 1.0;

        $healthy = $dispatched < self::SMALL_N_TOLERANCE
            || $ratio >= self::DEFAULT_THRESHOLD;

        return [
            'window_minutes' => $windowMinutes,
            'branch_id'      => $branchId,
            'dispatched'     => $dispatched,
            'acked_distinct' => $ackedDistinct,
            'ratio'          => round($ratio, 3),
            'threshold'      => self::DEFAULT_THRESHOLD,
            'healthy'        => $healthy,
            'computed_at'    => now()->toIso8601String(),
        ];
    }
}
