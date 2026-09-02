<?php

namespace App\Http\Controllers\Admin\Observability;

use App\Http\Controllers\Admin\AdminController;
use App\Http\Requests\Admin\Observability\StoreClientMetricsRequest;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Services\Observability\SyncMetricsRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;

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

    /**
     * [GOAL-2026-05-29 F5 / v2] Manual "Retry failed" AGE cap — bounds infinite
     * resurrection of a chronically-failing outbox row WITHOUT blocking the
     * legitimate use (an admin retrying an event that exhausted its auto-retries
     * after fixing the root infra). Rows older than the window age out of manual
     * retry and are left to the prune/escalation lane. No attempts ceiling: that
     * over-corrected and broke retry-of-exhausted (OutboxOverviewControllerTest).
     */
    private const RETRY_FAILED_MAX_AGE_DAYS = 7;

    public function __construct()
    {
        parent::__construct();
        // [NEW-04 / Audit T G2 + Audit Claude A3] Same Spatie permission as the
        // KDS sync surface guards BOTH the read (index) and write (clientMetrics)
        // actions. Symmetric gating prevents a Branch Manager (no KDS perm) from
        // posting telemetry that nobody on the same role can ever observe — and
        // makes the surface uniformly testable.
        $this->middleware(['permission:kitchen-display-system'])->only('index', 'clientMetrics');

        // [CV1-OBSERVABILITY-OUTBOX-001] Outbox pipeline dashboard surfaces failed
        // jobs, queue backlog and dispatched-event latency aggregated across ALL
        // branches — Chef/POS Operator have no business reading global pipeline
        // health, so this slice is locked to Admin / Tenant Admin roles only.
        // Retry / drain are mutating actions and inherit the same gate.
        $this->middleware(['role:Admin|Tenant Admin'])->only(
            'outboxOverview',
            'outboxRetryFailed',
            'outboxDrainFailed',
            // Avant : un caissier avec « dashboard » lisait le cockpit global.
            'systemHealth'
        );
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

    /**
     * [CV1-OBSERVABILITY-OUTBOX-001] Outbox pipeline dashboard.
     *
     * Five sections aggregated for the ops dashboard:
     *   1. domain_events pending — count + latest 50 rows (for visual triage)
     *   2. domain_events dispatched — count last 24h + p95 dispatch latency
     *   3. jobs queue=high — count + age of oldest reserved job
     *   4. failed_jobs — count + last 20 with truncated exception line
     *   5. health probes — websockets:serve and queue:work UP/DOWN heuristic
     *
     * Trade-off: the health probes are HEURISTICS, not real process pings.
     *   - websockets:serve UP iff a recent `ws:heartbeat` cache key exists
     *     (set by the broadcaster pulse) OR `dispatched_at` advanced in the
     *     last 60s — the same signal that tells us events left the box.
     *   - queue:work UP iff the oldest reserved job in `jobs` was reserved
     *     within the last 90s OR a domain_event was dispatched in the same
     *     window. A worker that hasn't reserved in 90s on a non-empty queue
     *     is the canonical "queue stuck" signature.
     *
     * Real process probing (pgrep / supervisor RPC) is out of scope for V1
     * — see runbook OBSERVABILITY_OUTBOX_DASHBOARD.md §3.
     */
    public function outboxOverview(Request $request): JsonResponse
    {
        $now = now();

        $pendingQuery = DB::table('domain_events')->whereNull('dispatched_at');
        $pendingCount = (clone $pendingQuery)->count();
        $pendingRows = $pendingQuery
            ->select(['id', 'event_type', 'aggregate_type', 'aggregate_id', 'branch_id', 'attempts', 'last_error', 'occurred_at', 'created_at'])
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map(fn ($row) => [
                'id' => (int) $row->id,
                'event_type' => (string) $row->event_type,
                'aggregate_type' => (string) $row->aggregate_type,
                'aggregate_id' => (int) $row->aggregate_id,
                'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
                'attempts' => (int) ($row->attempts ?? 0),
                'last_error' => $row->last_error,
                'occurred_at' => $row->occurred_at,
                'created_at' => $row->created_at,
            ])
            ->all();

        $dispatchedSince = $now->copy()->subDay();
        $dispatchedCount = DB::table('domain_events')
            ->whereNotNull('dispatched_at')
            ->where('dispatched_at', '>=', $dispatchedSince->format('Y-m-d H:i:s'))
            ->count();

        // p95 dispatch latency from sync_metrics over the same window — same
        // method as index() but isolated to the outbox metric type.
        $latencies = DB::table('sync_metrics')
            ->where('metric_type', SyncMetricsRecorder::METRIC_OUTBOX_DISPATCH_LATENCY_MS)
            ->where('occurred_at', '>=', $dispatchedSince->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'))
            ->limit(self::SELECT_LIMIT)
            ->pluck('value')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();

        $queueHigh = $this->describeQueueLane('high', $now);
        $failedJobsSummary = $this->describeFailedJobs();
        $health = $this->probeHealth($now);

        return response()->json([
            'generated_at' => $now->toISOString(),
            'pending' => [
                'count' => $pendingCount,
                'rows' => $pendingRows,
            ],
            'dispatched_24h' => [
                'count' => $dispatchedCount,
                'latency_p50_ms' => $this->percentile($latencies, 50),
                'latency_p95_ms' => $this->percentile($latencies, 95),
                'latency_p99_ms' => $this->percentile($latencies, 99),
                'samples' => count($latencies),
            ],
            'queue_high' => $queueHigh,
            'failed_jobs' => $failedJobsSummary,
            'health' => $health,
        ]);
    }

    /**
     * [CV1-OBSERVABILITY-OUTBOX-001] "Retry failed" admin action.
     *
     * Resets attempts/last_error/dispatched_at on at most BATCH events and
     * re-queues each via DispatchDomainEventsJob — same flow as the
     * `foodking:outbox:retry-failed` console command. We DO NOT delete or
     * mutate fiscal/audit data; the action is idempotent (a retry on an
     * already-dispatched event is a no-op the worker handles).
     */
    public function outboxRetryFailed(Request $request): JsonResponse
    {
        $batch = (int) ($request->input('limit', 50));
        if ($batch < 1 || $batch > 200) {
            $batch = 50;
        }

        // [GOAL-2026-05-29 F5 / v2 — corrected after OutboxOverviewControllerTest]
        // AGE cap (not an attempts cap) so manual "Retry failed" cannot INFINITELY
        // resurrect a row. Each retry resets attempts->0 + last_error->NULL; with no
        // bound a row that keeps failing is reset on every click and never ages out
        // of the prune/escalation lane. An AGE window bounds resurrection: rows older
        // than the window age out of manual retry and are left to escalate/prune.
        // We deliberately do NOT cap on attempts — manually retrying an event that
        // EXHAUSTED its auto-retries (attempts=5) after fixing the root infra is the
        // PRIMARY legitimate use of this button (locked by OutboxOverviewControllerTest).
        $events = DomainEvent::query()
            ->whereNull('dispatched_at')
            ->whereNotNull('last_error')
            ->where('created_at', '>=', now()->subDays(self::RETRY_FAILED_MAX_AGE_DAYS))
            ->orderBy('id')
            ->limit($batch)
            ->get();

        $requeued = 0;
        foreach ($events as $event) {
            $event->forceFill([
                'attempts' => 0,
                'last_error' => null,
            ])->save();

            DispatchDomainEventsJob::dispatch($event->id);
            $requeued++;
        }

        return response()->json([
            'requeued' => $requeued,
            'limit' => $batch,
        ]);
    }

    /**
     * [CV1-OBSERVABILITY-OUTBOX-001] "Drain failed jobs" admin action.
     *
     * SAFE-BY-DEFAULT: deletes only `failed_jobs` entries older than the
     * `older_than_hours` parameter (default 24h). Never touches
     * `domain_events` / fiscal tables. The endpoint refuses to operate when
     * `older_than_hours < 1` to make accidental "wipe everything" calls a
     * 422 instead of silent data loss.
     */
    public function outboxDrainFailed(Request $request): JsonResponse
    {
        $olderThan = (int) ($request->input('older_than_hours', 24));
        if ($olderThan < 1) {
            return response()->json([
                'message' => 'older_than_hours must be >= 1 — refusing to drain recent failed jobs.',
            ], 422);
        }

        $cutoff = now()->subHours($olderThan)->format('Y-m-d H:i:s');
        $deleted = DB::table('failed_jobs')
            ->where('failed_at', '<', $cutoff)
            ->delete();

        return response()->json([
            'deleted' => (int) $deleted,
            'older_than_hours' => $olderThan,
        ]);
    }

    private function describeQueueLane(string $queue, \Illuminate\Support\Carbon $now): array
    {
        try {
            $count = (int) Queue::size($queue);
        } catch (\Throwable $e) {
            return ['available' => false, 'count' => 0, 'oldest_age_seconds' => null];
        }

        $oldestAge = null;
        if (Schema::hasTable('jobs')) {
            $oldest = DB::table('jobs')
                ->where('queue', $queue)
                ->orderBy('available_at')
                ->value('available_at');
            $oldestAge = $oldest !== null ? max(0, $now->getTimestamp() - (int) $oldest) : null;
        }

        return [
            'available' => true,
            'count' => $count,
            'oldest_age_seconds' => $oldestAge,
        ];
    }

    private function describeFailedJobs(): array
    {
        if (! Schema::hasTable('failed_jobs')) {
            return ['available' => false, 'count' => 0, 'rows' => []];
        }

        $count = DB::table('failed_jobs')->count();
        $rows = DB::table('failed_jobs')
            ->select(['id', 'uuid', 'queue', 'connection', 'failed_at', 'exception'])
            ->orderByDesc('failed_at')
            ->limit(20)
            ->get()
            ->map(function ($row) {
                $exception = (string) $row->exception;
                $firstLine = strtok($exception, "\n");
                return [
                    'id' => (int) $row->id,
                    'uuid' => (string) $row->uuid,
                    'queue' => (string) $row->queue,
                    'connection' => (string) $row->connection,
                    'failed_at' => $row->failed_at,
                    // Truncate to keep payload bounded for the UI.
                    'exception_first_line' => mb_substr($firstLine ?: '', 0, 500),
                ];
            })
            ->all();

        return [
            'available' => true,
            'count' => $count,
            'rows' => $rows,
        ];
    }

    private function probeHealth(\Illuminate\Support\Carbon $now): array
    {
        // queue:work heuristic — last reserved job within 90s OR a
        // domain_event was dispatched in the same window.
        $queueWorkUp = false;
        $queueLastSignalAgo = null;

        if (Schema::hasTable('jobs')) {
            $reserved = DB::table('jobs')
                ->whereNotNull('reserved_at')
                ->orderByDesc('reserved_at')
                ->value('reserved_at');
            if ($reserved !== null) {
                $age = max(0, $now->getTimestamp() - (int) $reserved);
                $queueLastSignalAgo = $age;
                if ($age <= 90) {
                    $queueWorkUp = true;
                }
            }
        }

        $lastDispatched = DB::table('domain_events')
            ->whereNotNull('dispatched_at')
            ->orderByDesc('dispatched_at')
            ->value('dispatched_at');
        if ($lastDispatched !== null) {
            $age = max(0, $now->getTimestamp() - strtotime((string) $lastDispatched));
            if ($queueLastSignalAgo === null || $age < $queueLastSignalAgo) {
                $queueLastSignalAgo = $age;
            }
            if ($age <= 90) {
                $queueWorkUp = true;
            }
        }

        // websockets:serve heuristic — cache heartbeat key, fallback to
        // "events were dispatched recently → broadcaster is presumably alive".
        $wsHeartbeat = null;
        try {
            $wsHeartbeat = Cache::get('ws:heartbeat');
        } catch (\Throwable $e) {
            $wsHeartbeat = null;
        }
        $wsLastSignalAgo = null;
        $wsUp = false;
        if ($wsHeartbeat !== null) {
            $ts = is_numeric($wsHeartbeat) ? (int) $wsHeartbeat : strtotime((string) $wsHeartbeat);
            if ($ts > 0) {
                $age = max(0, $now->getTimestamp() - $ts);
                $wsLastSignalAgo = $age;
                $wsUp = $age <= 60;
            }
        }
        // Fallback: a successful broadcast in the last 60s is positive evidence.
        if (! $wsUp && $lastDispatched !== null) {
            $age = max(0, $now->getTimestamp() - strtotime((string) $lastDispatched));
            if ($age <= 60) {
                $wsUp = true;
                $wsLastSignalAgo = $wsLastSignalAgo ?? $age;
            }
        }

        return [
            'queue_work' => [
                'status' => $queueWorkUp ? 'up' : 'down',
                'last_signal_age_seconds' => $queueLastSignalAgo,
                'method' => 'heuristic_jobs_reserved_or_event_dispatched_within_90s',
            ],
            'websockets_serve' => [
                'status' => $wsUp ? 'up' : 'down',
                'last_signal_age_seconds' => $wsLastSignalAgo,
                'method' => 'heuristic_cache_heartbeat_or_recent_dispatch_within_60s',
            ],
        ];
    }

    /**
     * [PILOTAGE 2026-08-09] « Est-ce que ça va ? » — la réponse en un appel.
     *
     * Le logiciel SAVAIT déjà s'il allait bien : `healthz:check` contrôle cinq
     * sous-systèmes toutes les minutes, la sauvegarde tourne à 3 h et une
     * restauration de vérification à 5 h. Rien de tout ça n'était visible dans
     * l'administration, qui n'exposait qu'un seul écran d'observabilité — la
     * file d'expédition. Autrement dit : le système se surveillait, et ne vous
     * le disait pas.
     *
     * Cette route agrège ce qui existe déjà. Elle n'invente aucune mesure.
     */
    public function systemHealth(Request $request): JsonResponse
    {
        $sante = Cache::get('healthz:last', []);

        // Fraîcheur des sauvegardes : c'est la DATE du fichier le plus récent qui
        // compte, pas leur nombre. Dix sauvegardes vieilles d'un mois ne valent rien.
        $dossier = storage_path('backups'.DIRECTORY_SEPARATOR.'db-daily');
        $dernier = null;
        $ageHeures = null;
        if (is_dir($dossier)) {
            $fichiers = glob($dossier.DIRECTORY_SEPARATOR.'*.sql.gz') ?: [];
            if ($fichiers !== []) {
                usort($fichiers, fn ($a, $b) => filemtime($b) <=> filemtime($a));
                $dernier = basename($fichiers[0]);
                $ageHeures = (int) round((time() - filemtime($fichiers[0])) / 3600);
            }
        }

        // Battement du planificateur : s'il s'arrête, TOUT s'arrête en silence —
        // sauvegardes, relances de file, vérification de la chaîne fiscale.
        // C'est déjà arrivé sur le VPS (jamais lancé, réparé le 27 juillet).
        $tic = Cache::get('scheduler:last_tick');
        $ticAgeMin = $tic ? (int) round((time() - (int) $tic) / 60) : null;

        // [2026-09-02] Fraîcheur de la mesure elle-même. `HealthzCheckCommand` écrit
        // `Cache::forever('healthz:last', ...)` — SANS expiration — et rien ne comparait
        // jusqu'ici `timestamp` à l'heure courante. Les cartes pouvaient donc afficher
        // « en service » en vert à partir d'une mesure arbitrairement vieille. La fraîcheur
        // était vérifiée pour la sauvegarde et pour le planificateur, pas pour les contrôles.
        $mesureLe = $sante['timestamp'] ?? null;
        $mesureAgeMin = null;
        if ($mesureLe !== null) {
            $horodatage = is_numeric($mesureLe) ? (int) $mesureLe : strtotime((string) $mesureLe);
            if ($horodatage) {
                $mesureAgeMin = (int) round((time() - $horodatage) / 60);
            }
        }

        $verdict = 'ok';
        $alertes = [];

        // [2026-09-02] Le `foreach` ci-dessous itère sur un tableau VIDE quand la sonde n'a
        // pas tourné : aucune carte, aucun message, aucune alerte. Mesuré sur cette machine :
        // `controles: []` renvoyé pendant que 1 521 messages attendaient en file (seuil du
        // code : 50) — dont 1 511 notifications clients. Et c'est justement le planificateur
        // mort qui empêche `healthz:check` de tourner : la panne qui casse la surveillance
        // efface aussi le rapport sur elle-même. Un panneau qui ne mesure rien doit le dire.
        if ((array) ($sante['checks'] ?? []) === []) {
            $alertes[] = "contrôles de santé : aucune mesure disponible — la sonde n'a pas tourné";
        } elseif ($mesureAgeMin !== null && $mesureAgeMin > 30) {
            $alertes[] = $mesureAgeMin > 120
                ? 'contrôles de santé : mesure vieille de '.((int) round($mesureAgeMin / 60)).' h'
                : "contrôles de santé : mesure vieille de {$mesureAgeMin} min";
        }

        foreach ((array) ($sante['checks'] ?? []) as $quoi => $etat) {
            if ($quoi === 'queue_pending') {
                if ($etat === 'unknown') {
                    $alertes[] = "file d'attente : mesure impossible";
                    continue;
                }
                if ((int) $etat > 50) {
                    $alertes[] = "file d'attente : {$etat} messages";
                }
                continue;
            }
            if ($etat !== 'ok') {
                $alertes[] = "{$quoi} : {$etat}";
            }
        }
        if ($ageHeures === null) {
            $alertes[] = 'aucune sauvegarde trouvée';
        } elseif ($ageHeures > 26) {
            // Même unité que la carte de l'écran : « 111 h » dans l'alerte et
            // « 5 jours » sur la carte décrivaient le même fait de deux façons,
            // ce qui fait douter de l'un des deux.
            $alertes[] = $ageHeures > 48
                ? 'dernière sauvegarde il y a '.((int) round($ageHeures / 24)).' jours'
                : "dernière sauvegarde il y a {$ageHeures} h";
        }
        if ($ticAgeMin === null) {
            $alertes[] = 'planificateur : aucun battement enregistré';
        } elseif ($ticAgeMin > 10) {
            $alertes[] = "planificateur muet depuis {$ticAgeMin} min";
        }
        if ($alertes !== []) {
            $verdict = 'attention';
        }

        return response()->json([
            'verdict'    => $verdict,
            'alertes'    => $alertes,
            'controles'  => (array) ($sante['checks'] ?? []),
            'mesure_le'  => $mesureLe,
            // Permet à l'écran de distinguer une mesure fraîche d'une mesure figée.
            'mesure_age_min'    => $mesureAgeMin,
            'mesure_attendu_max_min' => 30,
            'sauvegarde' => [
                'dernier_fichier' => $dernier,
                'age_heures'      => $ageHeures,
                'attendu_max_h'   => 26,
            ],
            'planificateur' => [
                'dernier_battement_min' => $ticAgeMin,
                'attendu_max_min'       => 10,
            ],
        ]);
    }
}
