<?php

namespace App\Http\Controllers\Admin\Observability;

use App\Http\Controllers\Admin\AdminController;
use App\Http\Requests\Admin\Observability\StoreClientMetricsRequest;
use App\Services\Observability\SyncMetricsRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * [NEW-04] Read-only admin observability surface (P50/P95/P99 rollups) +
 * write endpoint for batched client telemetry uploads.
 *
 * Percentile computation is in-memory (sort + nearest-rank) on the result
 * set capped at 50 000 rows. This is intentional for the v1 dashboard:
 *   - keeps the controller free of vendor-specific SQL
 *   - SQLite-compatible for tests
 * Migrate to a percentile materialized view if cardinality grows beyond
 * 100k metrics/hour (out of scope for NEW-04).
 */
class SyncOverviewController extends AdminController
{
    /**
     * [NEW-04 / Audit T G2] SELECT cap. Documented in the response payload as
     * `truncated=true` when the result set hits this ceiling so dashboards can
     * surface the approximation instead of silently skewing percentiles.
     */
    private const SELECT_LIMIT = 50000;

    public function __construct()
    {
        parent::__construct();
        // [NEW-04 / Audit T G2 + Audit Claude A3] Same Spatie permission as the
        // KDS sync surface guards BOTH the read (index) and write (clientMetrics)
        // actions. Symmetric gating prevents a Branch Manager (no KDS perm) from
        // posting telemetry that nobody on the same role can ever observe — and
        // makes the surface uniformly testable.
        $this->middleware(['permission:kitchen-display-system'])->only('index', 'clientMetrics');
    }

    public function index(Request $request): JsonResponse
    {
        $since = $this->parseSince($request->query('since'));
        $branchId = $this->resolveBranchScope($request);

        if ($branchId instanceof JsonResponse) {
            return $branchId;
        }

        // Format Carbon as a portable datetime string so SQLite (memory mode in
        // CI) compares lexicographically against the stored `occurred_at` column.
        // Convert to the app timezone first because rows are stored using
        // now() (app TZ) while incoming `since` is typically a UTC ISO-8601
        // ('Z' suffix) — failing to align the TZ off-shifts the boundary by
        // up to ±14h depending on deployment.
        $sinceForQuery = $since->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s');

        $metricsQuery = DB::table('sync_metrics')
            ->where('occurred_at', '>=', $sinceForQuery);

        if ($branchId !== null) {
            $metricsQuery->where('branch_id', $branchId);
        }

        $metrics = $metricsQuery
            ->select(['id', 'metric_type', 'branch_id', 'value', 'labels', 'correlation_id', 'occurred_at'])
            // [Audit T G3] Deterministic order required so that, when the SELECT
            // hits SELECT_LIMIT, the truncation always slices the SAME tail and
            // p95/p99 do not jitter across calls. occurred_at + id (PK) is a
            // total order even when many rows share an `occurred_at` value.
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit(self::SELECT_LIMIT)
            ->get();
        $truncated = $metrics->count() === self::SELECT_LIMIT;

        $dispatchLatencies = $metrics
            ->where('metric_type', SyncMetricsRecorder::METRIC_OUTBOX_DISPATCH_LATENCY_MS)
            ->pluck('value')
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $kdsIntervals = $metrics
            ->where('metric_type', SyncMetricsRecorder::METRIC_KDS_SYNC_FALLBACK_INTERVAL_MS)
            ->pluck('value')
            ->map(fn ($value) => (int) $value)
            ->values()
            ->all();

        $byEventType = $metrics
            ->where('metric_type', SyncMetricsRecorder::METRIC_OUTBOX_DISPATCH_LATENCY_MS)
            ->groupBy(function ($metric): string {
                $labels = $this->decodeLabels($metric->labels);

                return (string) ($labels['event_type'] ?? 'unknown');
            })
            ->map(function ($group, string $eventType): array {
                $latencies = $group->pluck('value')->map(fn ($value) => (int) $value)->values()->all();

                return [
                    'event_type' => $eventType,
                    'count' => count($latencies),
                    'latency_p95_ms' => $this->percentile($latencies, 95),
                ];
            })
            ->values()
            ->all();

        $failuresQuery = DB::table('domain_events')
            ->whereNotNull('last_error')
            ->whereNull('dispatched_at')
            ->where('occurred_at', '>=', $sinceForQuery);

        if ($branchId !== null) {
            $failuresQuery->where('branch_id', $branchId);
        }

        $recentFailures = $failuresQuery
            ->select(['id', 'event_type', 'aggregate_type', 'aggregate_id', 'branch_id', 'attempts', 'last_error', 'correlation_id', 'occurred_at'])
            ->orderByDesc('occurred_at')
            ->limit(10)
            ->get()
            ->map(fn ($failure) => [
                'id' => $failure->id,
                'event_type' => $failure->event_type,
                'aggregate_type' => $failure->aggregate_type,
                'aggregate_id' => $failure->aggregate_id,
                'branch_id' => $failure->branch_id,
                'attempts' => $failure->attempts,
                'last_error' => $failure->last_error,
                'correlation_id' => $failure->correlation_id,
                'occurred_at' => $failure->occurred_at,
            ])
            ->all();

        return response()->json([
            'generated_at' => now()->toISOString(),
            'since' => $since->toISOString(),
            'branch_id' => $branchId,
            'truncated' => $truncated,
            'select_limit' => self::SELECT_LIMIT,
            'summary' => [
                'outbox_dispatch_latency_ms_p50' => $this->percentile($dispatchLatencies, 50),
                'outbox_dispatch_latency_ms_p95' => $this->percentile($dispatchLatencies, 95),
                'outbox_dispatch_latency_ms_p99' => $this->percentile($dispatchLatencies, 99),
                'outbox_events_count' => count($dispatchLatencies),
                'ws_auth_failures_count' => $metrics->where('metric_type', SyncMetricsRecorder::METRIC_WS_AUTH_FAILURE)->count(),
                'kds_fallback_count' => $metrics->where('metric_type', SyncMetricsRecorder::METRIC_KDS_SYNC_FALLBACK_INTERVAL_MS)->count(),
                'kds_fallback_interval_ms_p50' => $this->percentile($kdsIntervals, 50),
            ],
            'by_event_type' => $byEventType,
            'recent_failures' => $recentFailures,
        ]);
    }

    /**
     * [NEW-04 / Audit T G2] Branch-isolation gate.
     *
     * - Global admin (branch_id === 0): may pass `?branch_id=N` to scope, OR
     *   omit it to view ALL branches in aggregate.
     * - Branch-scoped operator: forced to their own branch_id; passing
     *   `?branch_id=other` returns 403 (no silent down-scoping that would mask
     *   a probing attempt). Omitting `?branch_id=` is interpreted as the
     *   user's own branch_id (NOT global aggregate).
     *
     * Returns either an int branch filter (or null for global aggregate)
     * OR a JsonResponse 403 to short-circuit the controller.
     */
    private function resolveBranchScope(Request $request): int|null|JsonResponse
    {
        $user = $request->user();
        $userBranchId = (int) ($user->branch_id ?? 0);
        $requested = $request->query('branch_id');
        $requestedInt = ($requested !== null && $requested !== '') ? (int) $requested : null;

        if ($userBranchId === 0) {
            // [Audit Claude A1] NULL/0 branch_id only promotes to global admin
            // when the user actually holds the 'Admin' Spatie role. Otherwise
            // a misconfigured branch-scoped user (Chef/POS Operator created
            // without a branch_id) would silently see ALL branches' metrics.
            // Returns 403 instead of empty result to surface the data-integrity
            // bug instead of masking it.
            $isGlobalAdmin = $user !== null && method_exists($user, 'hasRole') && $user->hasRole('Admin');
            if (! $isGlobalAdmin) {
                return response()->json([
                    'message' => 'Branch context is required for non-admin operators.',
                ], 403);
            }
            return $requestedInt;
        }

        if ($requestedInt !== null && $requestedInt !== $userBranchId) {
            return response()->json([
                'message' => 'Cross-branch access is forbidden.',
            ], 403);
        }

        // Branch-scoped operator: ALWAYS scoped to their own branch.
        return $userBranchId;
    }

    public function clientMetrics(StoreClientMetricsRequest $request, SyncMetricsRecorder $recorder): JsonResponse
    {
        $user = $request->user();
        $branchId = $user !== null && isset($user->branch_id) && $user->branch_id !== null
            ? (int) $user->branch_id
            : null;
        $correlationId = $request->header('X-Correlation-ID');
        $metrics = $request->validated('metrics');

        foreach ($metrics as $metric) {
            $recorder->recordClientMetric(
                $metric['type'],
                $branchId,
                (int) $metric['value'],
                $metric['labels'] ?? [],
                is_string($correlationId) ? $correlationId : null
            );
        }

        return response()->json(['received' => count($metrics)], 202);
    }

    private function parseSince(mixed $since): CarbonImmutable
    {
        if (is_string($since) && $since !== '') {
            return CarbonImmutable::parse($since);
        }

        return CarbonImmutable::now()->subHour();
    }

    private function percentile(array $values, int $percentile): ?int
    {
        $count = count($values);

        if ($count === 0) {
            return null;
        }

        sort($values, SORT_NUMERIC);
        $index = (int) ceil(($percentile / 100) * $count) - 1;
        $index = max(0, min($index, $count - 1));

        return (int) $values[$index];
    }

    private function decodeLabels(mixed $labels): array
    {
        if ($labels === null || $labels === '') {
            return [];
        }

        if (is_array($labels)) {
            return $labels;
        }

        $decoded = json_decode((string) $labels, true);

        return is_array($decoded) ? $decoded : [];
    }
}
