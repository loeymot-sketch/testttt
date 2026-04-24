<?php

namespace App\Services\Observability;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [NEW-04] Non-blocking telemetry recorder for sync invariants.
 *
 * SLO targets surfaced via /api/admin/observability/sync-overview:
 *   - outbox.dispatch_latency_ms p95 < 2 000 ms (steady state)
 *   - kds.sync_fallback_interval_ms p50 < 5 000 ms during WS outages
 *   - ws.auth_failure count → cross-checked with F-12 SESSION_INVALID
 *
 * Invariants:
 *   - Internal try/catch around DB::insert() guarantees a metric write failure
 *     NEVER bubbles up into the calling business flow (outbox dispatch, WS
 *     handler, KDS sync). On failure: log warning, return.
 *   - Client metric names are strictly whitelisted (ALLOWED_CLIENT_METRICS)
 *     to keep cardinality bounded and prevent ingestion abuse.
 *   - Correlation ID falls back to the inbound X-Correlation-ID header so
 *     the metric can be joined end-to-end with the trace already attached
 *     to the originating request (see HasCorrelationId trait).
 */
class SyncMetricsRecorder
{
    public const METRIC_OUTBOX_DISPATCH_LATENCY_MS = 'outbox.dispatch_latency_ms';
    public const METRIC_WS_AUTH_FAILURE = 'ws.auth_failure';
    public const METRIC_KDS_SYNC_FALLBACK_INTERVAL_MS = 'kds.sync_fallback_interval_ms';

    /**
     * [NEW-04 / Audit T G4 + Audit Claude A5] `ws.auth_failure` is part of the
     * CLIENT whitelist because today the only producer is the frontend
     * (WebSocketService.handleSubscriptionError() → MetricsBatcher → POST
     * /client-metrics → recordClientMetric()).
     *
     * The server-side `recordWebSocketAuthFailure()` method below is currently
     * UNCALLED. If a future caller is wired (e.g. from a Pusher channel auth
     * handler in BroadcastServiceProvider), DO add a `source` label
     * ('client'|'server') to BOTH paths and update dashboards to filter or
     * union accordingly — otherwise ws_auth_failures_count will double-count
     * the same logical event.
     */
    public const ALLOWED_CLIENT_METRICS = [
        'ws.connect_latency_ms',
        'ws.disconnect_event',
        'ws.reconnect_storm',
        'ws.auth_failure',
        'kds.sync_fallback_interval_ms',
    ];

    public function recordEventDispatched(string $eventType, ?int $branchId, int $latencyMs, ?string $correlationId = null): void
    {
        $this->insertMetric(
            self::METRIC_OUTBOX_DISPATCH_LATENCY_MS,
            $branchId,
            $latencyMs,
            ['event_type' => $eventType],
            $correlationId
        );
    }

    public function recordWebSocketAuthFailure(?int $branchId, ?string $correlationId = null): void
    {
        $this->insertMetric(
            self::METRIC_WS_AUTH_FAILURE,
            $branchId,
            1,
            [],
            $correlationId
        );
    }

    public function recordKdsSyncFallback(?int $branchId, int $intervalMs, ?string $reason = null, ?string $correlationId = null): void
    {
        $labels = [];

        if ($reason !== null) {
            $labels['reason'] = $reason;
        }

        $this->insertMetric(
            self::METRIC_KDS_SYNC_FALLBACK_INTERVAL_MS,
            $branchId,
            $intervalMs,
            $labels,
            $correlationId
        );
    }

    public function recordClientMetric(string $metricType, ?int $branchId, int $value, array $labels, ?string $correlationId = null): void
    {
        if (! in_array($metricType, self::ALLOWED_CLIENT_METRICS, true)) {
            Log::warning('Rejected unknown client sync metric type.', [
                'metric_type' => $metricType,
                'branch_id' => $branchId,
                'correlation_id' => $this->resolveCorrelationId($correlationId),
            ]);

            return;
        }

        $this->insertMetric($metricType, $branchId, $value, $labels, $correlationId);
    }

    private function insertMetric(string $metricType, ?int $branchId, int $value, array $labels = [], ?string $correlationId = null): void
    {
        try {
            DB::table('sync_metrics')->insert([
                'metric_type' => $metricType,
                'branch_id' => $branchId,
                'value' => $value,
                'correlation_id' => $this->resolveCorrelationId($correlationId),
                'labels' => empty($labels) ? null : json_encode($labels, JSON_THROW_ON_ERROR),
                'occurred_at' => now(),
            ]);
        } catch (Throwable $exception) {
            // Non-blocking invariant: telemetry MUST NOT break the calling flow.
            Log::warning('Failed to persist sync metric.', [
                'metric_type' => $metricType,
                'branch_id' => $branchId,
                'exception' => $exception::class,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    private function resolveCorrelationId(?string $correlationId): ?string
    {
        if ($correlationId !== null && $correlationId !== '') {
            return $correlationId;
        }

        try {
            if (function_exists('request') && request()) {
                $header = request()->header('X-Correlation-ID');

                return is_string($header) && $header !== '' ? $header : null;
            }
        } catch (Throwable) {
            return null;
        }

        return null;
    }
}
