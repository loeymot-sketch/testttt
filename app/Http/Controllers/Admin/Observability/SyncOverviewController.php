<?php

namespace App\Http\Controllers\Admin\Observability;

use App\Http\Controllers\Admin\AdminController;
use App\Http\Requests\Admin\Observability\StoreClientMetricsRequest;
use App\Jobs\DispatchDomainEventsJob;
use App\Models\DomainEvent;
use App\Services\Fiscal\AuditLogService;
use App\Services\Observability\SyncMetricsRecorder;
use App\Services\Outbox\OutboxReplayService;
use App\Support\Backup\RestoreDrillResult;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

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

    /**
     * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Codex P1-J] Un claim (`dispatched_at` posé,
     * `broadcast_at` nul) plus vieux que ceci est un orphelin — même seuil que la lane B
     * crash-claimed d'OutboxRescueCommand (10 min > pire courbe de backoff 381 s + hang
     * broadcast ~60 s).
     */
    private const CLAIM_STALE_MINUTES = 10;

    /** [Codex P1-K] Borne d'une purge : au-delà, on rejoue le bouton. */
    private const DRAIN_BATCH_CAP = 500;

    /**
     * [G2 2026-09-03 · T2.3] Seuil par défaut de la purge — le MÊME que celui qu'annonce
     * le compteur `purgeable_failed_jobs` du cockpit. Deux valeurs écrites séparément
     * finissent par diverger, et alors le bouton promet autre chose que ce qu'il fait.
     */
    private const DRAIN_DEFAULT_OLDER_THAN_HOURS = 24;

    /**
     * [G2 2026-09-03 · T2.5 / T2.6] La file et le nombre d'essais sont LUS SUR LE JOB,
     * jamais recopiés ici. `DispatchDomainEventsJob::__construct` appelle `onQueue(...)`
     * et la classe déclare `$tries` + sa courbe de backoff : si l'un des deux change, la
     * mesure suit sans qu'on ait à s'en souvenir. Une sonde qui recopie une constante
     * finit par surveiller une file que plus personne n'utilise.
     */
    private static function outboxJobBlueprint(): DispatchDomainEventsJob
    {
        return new DispatchDomainEventsJob(0);
    }

    private static function outboxQueueName(): string
    {
        $queue = self::outboxJobBlueprint()->queue;

        return is_string($queue) && $queue !== '' ? $queue : 'default';
    }

    private static function outboxJobTries(): int
    {
        return max(1, (int) self::outboxJobBlueprint()->tries);
    }

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
     * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Codex P1-J] Sémantique de LIVRAISON.
     * Depuis la migration 2026_08_04, le job pose `dispatched_at` au CLAIM (Phase 1,
     * AVANT la diffusion) et `broadcast_at` seulement quand la diffusion a réussi
     * (Phase 3a, `DispatchDomainEventsJob.php:151-154`). Ce cockpit comptait encore sur
     * `dispatched_at` : un worker tué entre claim et broadcast faisait disparaître
     * l'événement des « en attente », le comptait parmi les « dispatchés » et laissait
     * les sondes queue/websocket en UP. Mesuré sur la base servie le 2026-09-02 :
     * 2 149 lignes claimées jamais diffusées, invisibles de cet écran.
     *
     * États exposés (disjoints) :
     *   - pending           : jamais claimé (`dispatched_at IS NULL`), 50 dernières lignes
     *   - terminal_failures : sous-ensemble de pending avec `last_error` (+ contract_violation,
     *                         non rejouables — voir OutboxRetryFailedCommand SEC MISSION-27)
     *   - in_flight         : claimé depuis < CLAIM_STALE_MINUTES, pas encore diffusé
     *   - stale_claimed     : claimé depuis ≥ CLAIM_STALE_MINUTES, jamais diffusé — MÊME
     *                         seuil que `OutboxRescueCommand` lane B (crash-claimed), sinon
     *                         deux écrans diraient deux choses
     *   - delivered_24h     : `broadcast_at` dans les 24 h + latences p50/p95/p99
     *   - queue_high, failed_jobs, health : voir describeQueueLane / describeFailedJobs /
     *     probeHealth — les sondes ne prennent plus un claim pour un signal positif.
     */
    public function outboxOverview(Request $request): JsonResponse
    {
        $now = now();
        $claimStaleCutoff = $now->copy()->subMinutes(self::CLAIM_STALE_MINUTES)->format('Y-m-d H:i:s');

        $columns = ['id', 'event_type', 'aggregate_type', 'aggregate_id', 'branch_id', 'attempts', 'last_error', 'occurred_at', 'created_at', 'dispatched_at'];
        $rowShape = fn ($row) => [
            'id' => (int) $row->id,
            'event_type' => (string) $row->event_type,
            'aggregate_type' => (string) $row->aggregate_type,
            'aggregate_id' => (int) $row->aggregate_id,
            'branch_id' => $row->branch_id !== null ? (int) $row->branch_id : null,
            'attempts' => (int) ($row->attempts ?? 0),
            'last_error' => $row->last_error,
            'occurred_at' => $row->occurred_at,
            'created_at' => $row->created_at,
            'dispatched_at' => $row->dispatched_at,
        ];

        $pendingQuery = DB::table('domain_events')->whereNull('dispatched_at');
        $pendingCount = (clone $pendingQuery)->count();
        $pendingRows = (clone $pendingQuery)
            ->select($columns)
            ->orderByDesc('id')
            ->limit(50)
            ->get()
            ->map($rowShape)
            ->all();

        // [G2 2026-09-03 · T2.6 · défaut V-10] « Terminal » exige des essais ÉPUISÉS.
        // Le job écrit `last_error` ET relâche le claim dès la PREMIÈRE des `$tries`
        // tentatives (Phase 3b, DispatchDomainEventsJob) : compter tout `last_error`
        // comme terminal gonflait ce chiffre de tous les événements en cours de reprise
        // automatique — la courbe [1,5,15,60,300] leur laisse encore ~6 min.
        // Exception : une violation de contrat est terminale dès le premier essai, parce
        // que le job appelle `$this->fail()` — un payload malformé ne guérit pas au rejeu
        // (sentinelle PayloadMismatchFailOnceSentinelTest).
        $tries = self::outboxJobTries();
        $terminalQuery = DB::table('domain_events')
            ->whereNull('dispatched_at')
            ->whereNotNull('last_error')
            ->where(function ($q) use ($tries) {
                $q->where('attempts', '>=', $tries)
                    ->orWhere('last_error', 'like', 'contract_violation%');
            });
        $terminalCount = (clone $terminalQuery)->count();
        $contractViolations = (clone $terminalQuery)->where('last_error', 'like', 'contract_violation%')->count();

        // [G2 2026-09-03 · T2.3 · défaut V-04] Le compteur qui gouverne le bouton
        // « Rejouer » doit être celui de ce que le bouton rejouera VRAIMENT : la même
        // sélection que `outboxRetryFailed`, pas le compteur d'échecs terminaux (qui
        // inclut les violations de contrat, non rejouables, et exclut les événements
        // encore dans leur courbe de reprise, eux parfaitement rejouables à la main
        // quand le worker est mort).
        $replayableQuery = DB::table('domain_events');
        self::applyReplayableCriteria($replayableQuery);
        $replayableCount = $replayableQuery->count();

        $claimedQuery = DB::table('domain_events')->whereNotNull('dispatched_at')->whereNull('broadcast_at');
        $inFlightCount = (clone $claimedQuery)->where('dispatched_at', '>=', $claimStaleCutoff)->count();
        $staleQuery = (clone $claimedQuery)->where('dispatched_at', '<', $claimStaleCutoff);
        $staleCount = (clone $staleQuery)->count();
        $staleRows = (clone $staleQuery)
            ->select($columns)
            ->orderByDesc('dispatched_at')
            ->limit(20)
            ->get()
            ->map($rowShape)
            ->all();

        $deliveredSince = $now->copy()->subDay();
        $deliveredCount = DB::table('domain_events')
            ->whereNotNull('broadcast_at')
            ->where('broadcast_at', '>=', $deliveredSince->format('Y-m-d H:i:s'))
            ->count();

        // p95 dispatch latency from sync_metrics over the same window — same
        // method as index() but isolated to the outbox metric type.
        $latencies = DB::table('sync_metrics')
            ->where('metric_type', SyncMetricsRecorder::METRIC_OUTBOX_DISPATCH_LATENCY_MS)
            ->where('occurred_at', '>=', $deliveredSince->setTimezone(config('app.timezone'))->format('Y-m-d H:i:s'))
            ->limit(self::SELECT_LIMIT)
            ->pluck('value')
            ->map(fn ($v) => (int) $v)
            ->values()
            ->all();

        $outboxQueue = self::outboxQueueName();
        $queueHigh = $this->describeQueueLane($outboxQueue, $now);
        $failedJobsSummary = $this->describeFailedJobs();
        // [G2 2026-09-03 · T2.3 + T2.7] Ce que la purge supprimerait MAINTENANT, mesuré
        // par le MÊME helper que la purge elle-même : le bouton ne peut donc plus
        // promettre un nombre que l'action ne tiendra pas.
        $purgeableCandidates = $this->outboxFailedJobCandidates(
            $now->copy()->subHours(self::DRAIN_DEFAULT_OLDER_THAN_HOURS)->format('Y-m-d H:i:s')
        );
        $health = $this->probeHealth($now, $outboxQueue);

        return response()->json([
            'generated_at' => $now->toISOString(),
            'pending' => [
                'count' => $pendingCount,
                'rows' => $pendingRows,
            ],
            'terminal_failures' => [
                'count' => $terminalCount,
                'contract_violations' => $contractViolations,
                'attempts_threshold' => $tries,
            ],
            // Compteurs d'ACTION (ce qu'un clic ferait), distincts des compteurs d'ÉTAT.
            'replayable_events' => [
                'count' => $replayableCount,
                'max_age_days' => self::RETRY_FAILED_MAX_AGE_DAYS,
            ],
            'purgeable_failed_jobs' => [
                'count' => $purgeableCandidates->count(),
                'older_than_hours' => self::DRAIN_DEFAULT_OLDER_THAN_HOURS,
                // La purge traite au plus DRAIN_BATCH_CAP candidats par clic : au-delà,
                // le compte affiché est un plancher, pas un total.
                'capped' => $purgeableCandidates->count() >= self::DRAIN_BATCH_CAP,
            ],
            'in_flight' => [
                'count' => $inFlightCount,
                'stale_after_minutes' => self::CLAIM_STALE_MINUTES,
            ],
            'stale_claimed' => [
                'count' => $staleCount,
                'rows' => $staleRows,
            ],
            'delivered_24h' => [
                'count' => $deliveredCount,
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
     * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Codex P1-K] Passe par OutboxReplayService :
     * MÊME verrou que le cron (`outbox.retry-failed.lock`, conflit → 409 explicite au lieu
     * d'un double rejeu silencieux) et MÊME ligne `audit_logs` NF525 `outbox.replay` par
     * événement, signée par l'opérateur humain. `attempts`/`last_error` sont conservés
     * (heal B.1 2026-05-19) — avant, ce bouton les remettait à zéro à chaque clic.
     *
     * [GOAL-2026-05-29 F5 / v2] Fenêtre d'ÂGE (pas de plafond d'attempts) : rejouer un
     * événement qui a épuisé ses relances automatiques après réparation de l'infra est
     * l'usage légitime premier de ce bouton. Les violations de contrat (payload malformé)
     * sont exclues comme dans la commande : les rejouer n'écrit que des lignes d'audit
     * inutiles.
     */
    public function outboxRetryFailed(Request $request, OutboxReplayService $replay): JsonResponse
    {
        $batch = (int) ($request->input('limit', 50));
        if ($batch < 1 || $batch > 200) {
            $batch = 50;
        }

        $lock = $replay->lock();
        if (! $lock->get()) {
            return response()->json([
                'message' => 'Une relance outbox est déjà en cours (tâche planifiée ou autre opérateur). Réessayez dans quelques minutes.',
            ], 409);
        }

        try {
            $query = DomainEvent::query();
            self::applyReplayableCriteria($query);
            $events = $query->orderBy('id')->limit($batch)->get();

            $result = $replay->replay($events, 'admin:outbox:retry-failed', $request->user()?->id);
        } finally {
            $lock->release();
        }

        return response()->json([
            'requeued' => $result['requeued'],
            'audit_failed' => $result['audit_failed'],
            'dispatch_failed' => $result['dispatch_failed'],
            'limit' => $batch,
        ]);
    }

    /**
     * [CV1-OBSERVABILITY-OUTBOX-001] "Drain failed jobs" admin action.
     *
     * [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Codex P1-K] Avant : `DELETE failed_jobs WHERE
     * failed_at < cutoff`, quel que soit le job, sans export ni trace — depuis un écran
     * « outbox », un clic effaçait la preuve forensique de jobs sans rapport (sur la base
     * servie, le seul job en échec est un listener stock). Maintenant :
     *   1. seuls les `DispatchDomainEventsJob` de plus de `older_than_hours` sont candidats ;
     *   2. les lignes sont EXPORTÉES en JSON (`storage/app/outbox/drained-*.json`) avant tout ;
     *   3. une ligne `audit_logs` NF525 `outbox.drain` (acteur, ids, export) est écrite ;
     *   4. seulement alors les lignes sont supprimées. Pas d'audit → rien n'est supprimé.
     * `older_than_hours < 1` reste refusé (422) — une purge « tout de suite » n'existe pas.
     */
    public function outboxDrainFailed(Request $request, AuditLogService $auditLog): JsonResponse
    {
        $olderThan = (int) ($request->input('older_than_hours', self::DRAIN_DEFAULT_OLDER_THAN_HOURS));
        if ($olderThan < 1) {
            return response()->json([
                'message' => 'older_than_hours must be >= 1 — refusing to drain recent failed jobs.',
            ], 422);
        }

        if (! Schema::hasTable('failed_jobs')) {
            return response()->json(['deleted' => 0, 'older_than_hours' => $olderThan, 'exported_to' => null]);
        }

        $cutoff = now()->subHours($olderThan)->format('Y-m-d H:i:s');
        $candidates = $this->outboxFailedJobCandidates($cutoff);

        if ($candidates->isEmpty()) {
            return response()->json(['deleted' => 0, 'older_than_hours' => $olderThan, 'exported_to' => null]);
        }

        $ids = $candidates->pluck('id')->map(fn ($id) => (int) $id)->all();
        $actorId = $request->user()?->id;
        $exportPath = 'outbox/drained-'.now()->format('Ymd-His').'-'.Str::lower(Str::random(6)).'.json';

        Storage::disk('local')->put($exportPath, json_encode([
            'drained_at' => now()->toIso8601String(),
            'actor_id' => $actorId,
            'older_than_hours' => $olderThan,
            'cutoff' => $cutoff,
            'rows' => $candidates->map(fn ($row) => (array) $row)->all(),
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));

        // [G2 2026-09-03 · T2.4 · défaut V-05] L'audit n'atteste QUE le fait accompli.
        //
        // Avant : `'deleted' => count($ids)` était écrit AVANT le `DELETE`. Un `DELETE`
        // partiel (candidats disparus entre la sélection et la suppression — cron
        // `queue:prune-failed`, autre opérateur) ou en échec laissait une ligne
        // `audit_logs` IMMUABLE et SIGNÉE EN CHAÎNE affirmant une suppression qui n'avait
        // pas eu lieu. Une trace NF525 qui ment est pire qu'une trace absente : elle est
        // opposable, et la chaîne HMAC interdit de la corriger a posteriori.
        //
        // Maintenant : suppression PUIS audit du nombre réel, les deux dans la MÊME
        // transaction. L'audit échoue ⇒ la suppression est annulée — l'invariant « pas
        // d'audit, pas de suppression » (OutboxWebActionsAreAuditedTest) est conservé,
        // et l'écriture d'audit reste un simple AJOUT, jamais une correction.
        try {
            $deleted = DB::transaction(function () use ($ids, $auditLog, $actorId, $olderThan, $cutoff, $candidates, $exportPath): int {
                $deleted = (int) DB::table('failed_jobs')->whereIn('id', $ids)->delete();

                $auditLog->write([
                    'branch_id' => 0,
                    'user_id' => $actorId,
                    'action' => 'outbox.drain',
                    'resource' => 'failed_jobs',
                    'resource_id' => null,
                    'payload' => [
                        'command' => 'admin:outbox:drain-failed',
                        'older_than_hours' => $olderThan,
                        'cutoff' => $cutoff,
                        // Le nombre RÉELLEMENT supprimé, constaté et non prédit…
                        'deleted' => $deleted,
                        // …et, distinctement, ce qui avait été sélectionné : l'écart entre
                        // les deux est en soi une information forensique.
                        'candidates' => count($ids),
                        'failed_job_ids' => $ids,
                        'uuids' => $candidates->pluck('uuid')->map(fn ($u) => (string) $u)->all(),
                        'exported_to' => $exportPath,
                    ],
                ]);

                return $deleted;
            });
        } catch (\Throwable $e) {
            Log::channel('fiscal')->error('Outbox drain annulée : suppression ou trace d\'audit en échec', [
                'actor_id' => $actorId,
                'failed_job_ids' => $ids,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => "Purge annulée : la suppression n'a pas pu être menée à son terme, ou sa trace d'audit n'a pas pu être écrite. Rien n'a été supprimé.",
                'exported_to' => $exportPath,
            ], 500);
        }

        return response()->json([
            'deleted' => $deleted,
            'older_than_hours' => $olderThan,
            'exported_to' => $exportPath,
        ]);
    }

    /**
     * [G2 2026-09-03 · T2.3] Sélection unique des événements que la RELANCE MANUELLE
     * traitera : pendants, porteurs d'un `last_error`, hors violations de contrat (les
     * rejouer n'écrit que des lignes d'audit inutiles) et dans la fenêtre d'âge.
     *
     * Ni plafond ni plancher sur `attempts` — c'est délibéré et documenté
     * (GOAL-2026-05-29 F5 v2) : rejouer un événement qui a épuisé ses relances
     * automatiques APRÈS réparation de l'infrastructure est l'usage premier de ce bouton,
     * et rejouer un événement encore dans sa courbe de reprise est légitime quand le
     * worker est mort — précisément le cas où l'on ouvre ce cockpit.
     *
     * Utilisée par `outboxRetryFailed` (l'action) ET par `outboxOverview` (le compteur du
     * bouton) : deux expressions séparées finiraient par diverger, et alors le bouton
     * promettrait autre chose que ce qu'il fait.
     *
     * @param  \Illuminate\Database\Query\Builder|\Illuminate\Database\Eloquent\Builder  $query
     */
    private static function applyReplayableCriteria($query): void
    {
        $query->whereNull('dispatched_at')
            ->whereNotNull('last_error')
            ->where('last_error', 'not like', 'contract_violation%')
            ->where('created_at', '>=', now()->subDays(self::RETRY_FAILED_MAX_AGE_DAYS));
    }

    /**
     * [G2 2026-09-03 · T2.7 · défaut V-11] Les travaux `failed_jobs` que la PURGE
     * supprimera — filtre AVANT la borne.
     *
     * Avant : `->orderBy('id')->limit(DRAIN_BATCH_CAP)` puis filtre PHP sur la classe.
     * Le plafond s'appliquait donc à des lignes quelconques : 500 travaux en échec
     * étrangers plus anciens (un listener stock, une notification) consommaient tout le
     * lot et aucun candidat outbox n'était jamais atteint. Le bouton répondait
     * « 0 supprimé » indéfiniment, sans dire pourquoi.
     *
     * Le `LIKE` sur le nom court de la classe est un PRÉ-FILTRE portable (MySQL traite
     * `\` comme échappement dans `LIKE`, et le payload JSON double déjà les
     * antislashes : chercher le nom pleinement qualifié serait fragile des deux côtés).
     * Le verdict exact reste `isOutboxFailedJob()`, en PHP, sur les lignes ramenées.
     *
     * Utilisée par `outboxDrainFailed` (l'action) ET par `outboxOverview` (le compteur du
     * bouton) : même ensemble, donc même promesse.
     */
    private function outboxFailedJobCandidates(string $cutoff): \Illuminate\Support\Collection
    {
        if (! Schema::hasTable('failed_jobs')) {
            return collect();
        }

        return DB::table('failed_jobs')
            ->where('failed_at', '<', $cutoff)
            ->where('payload', 'like', '%'.class_basename(DispatchDomainEventsJob::class).'%')
            ->orderBy('id')
            ->limit(self::DRAIN_BATCH_CAP)
            ->get()
            ->filter(fn ($row) => self::isOutboxFailedJob($row))
            ->values();
    }

    /**
     * Un `failed_jobs` est « outbox » si son payload porte la classe du job de diffusion.
     * Laravel écrit `displayName` (et `data.commandName`) dans le payload JSON.
     */
    private static function isOutboxFailedJob(object $row): bool
    {
        $payload = json_decode((string) ($row->payload ?? ''), true);
        if (! is_array($payload)) {
            return false;
        }
        $name = (string) ($payload['displayName'] ?? ($payload['data']['commandName'] ?? ''));

        return $name === DispatchDomainEventsJob::class;
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

    private function probeHealth(\Illuminate\Support\Carbon $now, string $outboxQueue): array
    {
        // queue:work heuristic — last reserved job within 90s OR a domain_event
        // was actually DELIVERED (broadcast_at) in the same window.
        // [GOAL DASHBOARD-CONTRÔLE 2026-09-02 · Codex P1-J] Avant : `dispatched_at`, qui
        // n'est que le CLAIM. Un worker tué juste après le claim laissait la sonde en UP
        // pendant 90 s alors qu'aucun client n'avait rien reçu.
        // [G2 2026-09-03 · T2.5 · défaut V-06] …et la sonde acceptait N'IMPORTE QUELLE
        // ligne `jobs` réservée, sans condition sur `queue`. Le projet a plusieurs files
        // vivantes (`config('queue.monitored_queues')` : default, high, notifications ;
        // 1 490 travaux dormaient sur `notifications` le 2026-08-25). Un worker de
        // notifications bien portant suffisait donc à afficher le worker OUTBOX « en
        // service » alors que sa file était morte — exactement le cas où l'on ouvre cet
        // écran. La sonde est bornée à la file que le job utilise réellement.
        $queueWorkUp = false;
        $queueLastSignalAgo = null;

        if (Schema::hasTable('jobs')) {
            $reserved = DB::table('jobs')
                ->where('queue', $outboxQueue)
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

        $lastDelivered = DB::table('domain_events')
            ->whereNotNull('broadcast_at')
            ->orderByDesc('broadcast_at')
            ->value('broadcast_at');
        if ($lastDelivered !== null) {
            $age = max(0, $now->getTimestamp() - strtotime((string) $lastDelivered));
            if ($queueLastSignalAgo === null || $age < $queueLastSignalAgo) {
                $queueLastSignalAgo = $age;
            }
            if ($age <= 90) {
                $queueWorkUp = true;
            }
        }

        // websockets:serve heuristic — cache heartbeat key, fallback to
        // "an event was DELIVERED recently → broadcaster is alive".
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
        if (! $wsUp && $lastDelivered !== null) {
            $age = max(0, $now->getTimestamp() - strtotime((string) $lastDelivered));
            if ($age <= 60) {
                $wsUp = true;
                $wsLastSignalAgo = $wsLastSignalAgo ?? $age;
            }
        }

        return [
            'queue_work' => [
                'status' => $queueWorkUp ? 'up' : 'down',
                'last_signal_age_seconds' => $queueLastSignalAgo,
                // La méthode NOMME la file sondée : une sonde qui ne dit pas ce qu'elle
                // regarde ne peut pas être contredite.
                'method' => 'heuristic_jobs_reserved_on_queue_'.$outboxQueue.'_or_event_delivered_within_90s',
            ],
            'websockets_serve' => [
                'status' => $wsUp ? 'up' : 'down',
                'last_signal_age_seconds' => $wsLastSignalAgo,
                'method' => 'heuristic_cache_heartbeat_or_recent_delivery_within_60s',
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
        // [2026-09-02 · Codex P1-H] L'âge est gardé en DÉCIMAL pour la comparaison au
        // seuil ; l'arrondi n'intervient qu'à l'affichage. Avant, `(int) round(26.4)`
        // donnait 26, donc « 26 > 26 » était faux et la carte restait verte pendant que
        // HealthController::checkBackupAge, qui compare en décimal, déclarait `degraded`.
        // Deux écrans, deux vérités, pour le même fichier.
        // [G4 2026-09-03 · T4.1] Le « dernier fichier » est désigné par UNE seule règle,
        // partagée avec /health/ready : deux glob() écrits séparément finissent par
        // désigner deux fichiers différents, et alors les deux surfaces se contredisent.
        $cheminDernier = RestoreDrillResult::cheminSauvegardeCourante();
        $dernier = null;
        $ageHeuresExact = null;
        $ageHeures = null;
        if ($cheminDernier !== null) {
            $dernier = basename($cheminDernier);
            $ageHeuresExact = (time() - (int) @filemtime($cheminDernier)) / 3600;
            $ageHeures = (int) round($ageHeuresExact);
        }

        // [2026-09-02 · Codex P1-A] Le RÉSULTAT de la restauration de vérification (5 h),
        // et plus seulement la date du fichier. Une sauvegarde fraîche mais non
        // restaurable ne vaut rien ; jusqu'ici son verdict finissait dans un fichier de
        // log que personne n'ouvre.
        // [G4 2026-09-03 · T4.1 · défaut V-08] …et il faut encore que ce verdict parle DE
        // CE FICHIER. Publier côte à côte « dernier fichier » et « dernière restauration »
        // sans les rapprocher laissait un dump corrompu arrivé après un drill vert
        // s'afficher comme « réellement remonté ».
        $restauration = RestoreDrillResult::rapprocher(RestoreDrillResult::current(), $cheminDernier);

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
        // [2026-09-02 · Codex P1-H] Deuxième passe : un horodatage PRÉSENT mais illisible
        // (`strtotime` → false) ou DANS LE FUTUR donnait un âge nul ou négatif, donc aucune
        // alerte — une mesure dont on ne sait pas dater n'est pas une mesure fraîche.
        $mesureLe = $sante['timestamp'] ?? null;
        $mesureAgeMin = null;
        $horodatageInvalide = false;
        if ($mesureLe !== null) {
            $horodatage = is_numeric($mesureLe) ? (int) $mesureLe : strtotime((string) $mesureLe);
            if ($horodatage === false || $horodatage <= 0) {
                $horodatageInvalide = true;
            } elseif ($horodatage > time() + 60) {
                $horodatageInvalide = true;
            } else {
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
        } elseif ($horodatageInvalide) {
            $alertes[] = "contrôles de santé : horodatage de mesure invalide — impossible de dater ces valeurs";
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
        if ($ageHeuresExact === null) {
            $alertes[] = 'aucune sauvegarde trouvée';
        } elseif ($ageHeuresExact > 26) {
            // Même unité que la carte de l'écran : « 111 h » dans l'alerte et
            // « 5 jours » sur la carte décrivaient le même fait de deux façons,
            // ce qui fait douter de l'un des deux.
            $alertes[] = $ageHeures > 48
                ? 'dernière sauvegarde il y a '.((int) round($ageHeures / 24)).' jours'
                : "dernière sauvegarde il y a {$ageHeures} h";
        }

        // [2026-09-02 · Codex P1-A] Une sauvegarde restaurable est la seule qui compte.
        if (($alerteRestauration = RestoreDrillResult::alerte($restauration)) !== null) {
            $alertes[] = $alerteRestauration;
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
            'mesure_horodatage_invalide' => $horodatageInvalide,
            'mesure_attendu_max_min' => 30,
            'sauvegarde' => [
                'dernier_fichier' => $dernier,
                // [G4 2026-09-03 · T4.4 · défaut N-01] La valeur DÉCIMALE, plus l'arrondi.
                // Publier `(int) round(26,33) === 26` alors que le seuil publié vaut 26
                // laissait l'écran conclure « 26 <= 26 », donc VERT, pendant que la bande
                // d'alertes du même écran — calculée en décimal juste au-dessus — disait
                // que la sauvegarde était en retard. 29 minutes de contradiction par jour.
                'age_heures'      => $ageHeuresExact === null ? null : round($ageHeuresExact, 2),
                'attendu_max_h'   => 26,
                // …et surtout : le VERDICT, décidé ici. L'écran affiche, il ne recalcule
                // plus. Un seuil recopié dans deux langages finit toujours par diverger.
                'fraiche'         => $ageHeuresExact !== null && $ageHeuresExact <= 26,
                // Le fichier existe-t-il ET a-t-il été restauré avec succès récemment ?
                'restauration'    => $restauration,
            ],
            'planificateur' => [
                'dernier_battement_min' => $ticAgeMin,
                'attendu_max_min'       => 10,
            ],
        ]);
    }
}
