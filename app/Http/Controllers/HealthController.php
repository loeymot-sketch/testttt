<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class HealthController extends Controller
{
    public function full(): JsonResponse
    {
        $this->assertFullHealthIpAllowed();

        $checks = [
            'db' => $this->checkDb(),
            'redis' => $this->checkRedis(),
            'queue' => $this->checkQueue(),
            'broadcast' => $this->checkBroadcast(),
        ];

        $allOk = collect($checks)->every(fn ($c) => $c['status'] === 'ok');

        // [OPS-2 2026-06-04] Return 503 when degraded so a pager `curl -f`
        // actually fires. Previously this endpoint ALWAYS returned 200 with
        // `status:degraded` in the body — a monitor doing an HTTP-code probe
        // (the common case) never saw the outage. `broadcast: warning`
        // (null driver) is NOT ok, so it correctly degrades to 503 too.
        // Mirrors the /health/ready 503 policy.
        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'version' => config('app.version', 'dev'),
            'timestamp' => now()->toIso8601String(),
            'subsystems' => $checks,
        ], $allOk ? 200 : 503);
    }

    public function live(): Response
    {
        return response('OK', 200);
    }

    public function ready(): JsonResponse
    {
        // [AUDIT-F-015] Production blocker safety net for the outbox pattern.
        // queue_worker probe alerts when too many domain_events rows are stale
        // (worker likely down). broadcast_config probe alerts when prod is
        // misconfigured (sync queue or null/log broadcast driver). Both close
        // the silent-failure trap documented in
        // plans/PLAN_AUDIT_F015_QUEUE_CONFIG_PROD_2026-05-07.md.
        $checks = [
            'db' => $this->checkDb(),
            'redis' => $this->checkRedis(),
            'queue_worker' => $this->checkQueueWorker(),
            'broadcast_config' => $this->checkBroadcastConfig(),
            // [F-SCHEDULER-DEADMAN 2026-07-15 / P1] Dead-man runtime du scheduler + fraîcheur
            // backup NF525. Advisory hors production (les box dev ne lancent pas le daemon
            // schedule:run) → surfacés dans le rapport mais ne font PAS basculer /ready en 503 ;
            // en PRODUCTION ils gardent la readiness → UptimeRobot voit 503 si le scheduler meurt
            // ou si le dernier backup dépasse 26 h (mort silencieuse jusqu'ici invisible des sondes).
            'scheduler' => $this->checkScheduler(),
            'backup_age' => $this->checkBackupAge(),
            // [2026-09-02 · Codex P1-A] `backup_age` ne dit QUE l'âge du fichier. Un
            // `.sql.gz` de deux heures qu'on n'a jamais réussi à remonter ne protège de
            // rien — et c'est le cas le plus dangereux, parce qu'il s'affiche en vert.
            // Consultatif hors production comme ses deux voisins : un poste de
            // développement ne lance pas le planificateur, donc jamais le drill.
            'restore_drill' => $this->checkRestoreDrill(),
        ];

        $gating = app()->environment('production')
            ? $checks
            : array_diff_key($checks, ['scheduler' => 1, 'backup_age' => 1, 'restore_drill' => 1]);
        $allOk = collect($gating)->every(fn ($c) => $c['status'] === 'ok');

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'subsystems' => $checks,
        ], $allOk ? 200 : 503);
    }

    /**
     * [ONB-13 2026-08-28] Une panne se dit, ses coordonnees ne se publient pas.
     *
     * Les quatre sondes renvoyaient `$e->getMessage()` tel quel sur une route
     * PUBLIQUE (`routes/api.php:148`). Un message PDO porte l'hote, le nom de la
     * base et l'utilisateur SQL : le jour ou la base tombe — c'est-a-dire le jour ou
     * quelqu'un regarde — l'endpoint publiait les coordonnees de connexion.
     *
     * On garde le statut `error` : savoir QU'UN sous-systeme est tombe est le but de
     * la sonde. Le detail va au journal, ou l'exploitant le lira, avec la classe
     * d'exception qui suffit presque toujours a orienter le diagnostic.
     *
     * @return array{status: string, message: string}
     */
    private function panne(\Throwable $e): array
    {
        Log::error('[health] sonde en echec', [
            'exception' => get_class($e),
            'message'   => $e->getMessage(),
        ]);

        return [
            'status'  => 'error',
            // Volontairement sans detail : c'est une reponse publique.
            'message' => 'indisponible',
        ];
    }

    /**
     * Filtre IP du rapport complet.
     *
     * ⚠️ [ONB-13 2026-08-28] CETTE GARDE EST INERTE PAR DEFAUT, et son ancien
     * docblock promettait le contraire (« only listed IPs may call the full health
     * report »). Quand `HEALTH_IPS_ALLOWED` est vide — sa valeur par defaut dans
     * `config/app.php:127` ET dans `.env.example` — elle laisse tout passer.
     *
     * On la conserve telle quelle : fermer une sonde de vivacite casse les
     * deploiements et la supervision, et un correctif « securise » qui casse le
     * deploiement se fait desactiver la semaine suivante. La protection reelle est
     * desormais ailleurs : le rapport ne contient plus rien de confidentiel
     * (voir `panne()`).
     *
     * Remplir la variable reste utile en production, mais ce n'est plus ce qui
     * empeche une fuite.
     */
    private function assertFullHealthIpAllowed(): void
    {
        $csv = config('app.health_ips_allowed', '');
        if ($csv === '' || $csv === null) {
            return;
        }

        $ips = array_values(array_filter(array_map('trim', explode(',', $csv))));
        $ip = request()->ip();

        if (! in_array($ip, $ips, true)) {
            abort(403);
        }
    }

    private function checkDb(): array
    {
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');

            return ['status' => 'ok'];
        } catch (\Throwable $e) {
            return $this->panne($e);
        }
    }

    private function checkRedis(): array
    {
        try {
            $pong = Redis::ping();
            $ok = $pong === true || $pong === 'PONG' || $pong === '+PONG';
            if (! $ok && (is_string($pong) || (is_object($pong) && method_exists($pong, '__toString')))) {
                $ok = strtoupper((string) $pong) === 'PONG';
            }

            return ['status' => $ok ? 'ok' : 'error'];
        } catch (\Throwable $e) {
            return $this->panne($e);
        }
    }

    private function checkQueue(): array
    {
        // [GOAL CONSOLIDATION 2026-08-25] Rapporter CHAQUE file surveillée, pas deux d'entre elles.
        // Voir reports/audit/P0_FILE_NOTIFICATIONS_ORPHELINE_2026-08-25.md.
        try {
            $tailles = [];
            $total = 0;
            foreach ((array) config('queue.monitored_queues', ['default', 'high']) as $file) {
                $n = (int) Queue::size((string) $file);
                $tailles[(string) $file . '_size'] = $n;
                $total += $n;
            }

            return array_merge(['status' => 'ok', 'total_size' => $total], $tailles);
        } catch (\Throwable $e) {
            return $this->panne($e);
        }
    }

    private function checkBroadcast(): array
    {
        $driver = config('broadcasting.default');
        if (in_array($driver, [null, 'null'], true)) {
            return ['status' => 'warning', 'driver' => 'null'];
        }

        return ['status' => 'ok', 'driver' => $driver];
    }

    /**
     * [AUDIT-F-015] Detect a stalled outbox pipeline.
     *
     * If more than 10 `domain_events` rows created in the last 24h and
     * older than 30s are still `dispatched_at = NULL`, the queue worker is
     * most likely down or lagging far behind. (The 24h recency floor keeps
     * ancient orphan rows from a past incident from pinning the gate — see
     * checkQueueWorker body.) Surfacing this at /ready lets supervisors and
     * load-balancer probes pull the node out of rotation BEFORE the
     * 30s polling fallback masks the silent failure to operators.
     *
     * Stays cheap: a single indexed COUNT() against `idx_pending`.
     * Threshold is intentionally NOT configurable here — the operator
     * tuneable lives on the `foodking:outbox:monitor` command (--threshold).
     * /ready is a binary "rotate me out" probe, not a tuning surface.
     */
    /**
     * [F-SCHEDULER-DEADMAN 2026-07-15 / P1] Le scheduler écrit `scheduler:last_tick` toutes
     * les 5 min (HealthzCheckCommand). Si le tick est absent ou > 10 min, le daemon schedule:run
     * est probablement mort → backup NF525 + filets fiscaux (Z-close, retry-alloc, outbox:rescue)
     * ne tournent plus silencieusement.
     */
    private function checkScheduler(): array
    {
        try {
            $lastTick = Cache::get('scheduler:last_tick');
        } catch (\Throwable $e) {
            return ['status' => 'degraded', 'detail' => 'cache unavailable for scheduler tick'];
        }
        if ($lastTick === null) {
            return ['status' => 'degraded', 'detail' => 'no scheduler tick recorded yet'];
        }
        $ageMin = (now()->timestamp - (int) $lastTick) / 60;
        return $ageMin > 10
            ? ['status' => 'degraded', 'detail' => sprintf('scheduler last tick %.0fmin ago (>10)', $ageMin)]
            : ['status' => 'ok'];
    }

    /**
     * [F-SCHEDULER-DEADMAN 2026-07-15 / P1] Fraîcheur du dernier backup quotidien NF525.
     * Sur la box locale (DB fiscale courante), un backup > 26 h = fenêtre de perte de données
     * réelle en cas de panne disque. Rend l'oubli du backup visible avant l'incident.
     */
    private function checkBackupAge(): array
    {
        $dir = storage_path('backups/db-daily');
        $files = @glob($dir.'/*.sql.gz') ?: [];
        if (empty($files)) {
            return ['status' => 'degraded', 'detail' => 'no daily backup found'];
        }
        $newest = max(array_map('filemtime', $files));
        $ageHours = (now()->timestamp - (int) $newest) / 3600;
        return $ageHours > 26
            ? ['status' => 'degraded', 'detail' => sprintf('newest backup %.1fh old (>26h)', $ageHours)]
            : ['status' => 'ok'];
    }

    /**
     * [2026-09-02 · Codex P1-A] Le verdict de la restauration de vérification (5 h), tel
     * que `backup:verify-restore` le persiste désormais. Jamais joué, échoué ou périmé →
     * `degraded` : l'absence de preuve n'est pas une preuve.
     */
    private function checkRestoreDrill(): array
    {
        $etat = \App\Support\Backup\RestoreDrillResult::current();

        if ($etat['status'] === 'green') {
            return ['status' => 'ok', 'detail' => sprintf(
                'restore verified %.1fh ago (%s)',
                (float) ($etat['age_hours'] ?? 0),
                $etat['file'] ?? 'unknown file'
            )];
        }

        return [
            'status' => 'degraded',
            'detail' => \App\Support\Backup\RestoreDrillResult::alerte($etat) ?? 'restore drill status unknown',
        ];
    }

    private function checkQueueWorker(): array
    {
        try {
            // [Outbox dead-letter fix — 2026-07-07] Exclude terminal CONTRACT
            // VIOLATIONS from the worker-lag signal. DispatchDomainEventsJob
            // short-circuits PayloadMismatchException via $this->fail() on the
            // first failure (app/Jobs/DispatchDomainEventsJob.php:168-187): the
            // row freezes at dispatched_at=NULL and is NEVER retried, so it is
            // NOT evidence of a down/lagging worker. Counting it made
            // /health/ready flap to a FALSE 503 once poison rows accumulated
            // (17 immortal rows observed on prod). Genuinely-pending rows
            // (last_error NULL) and retrying runtime failures still count.
            // [Outbox recency-floor fix — 2026-07-11] Second immortal-row class:
            // rows stuck at attempts=0 / last_error=NULL (their DispatchDomainEventsJob
            // was never queued or was lost during a *past* worker-down window) are NOT
            // evidence the worker is down NOW — yet with no upper age bound they counted
            // FOREVER, pinning /health/ready to a false 503 long after recovery (20
            // orphans from a June worker-down incident observed doing exactly this).
            // Bound the signal to the active retry window (24h, matching
            // `foodking:outbox:retry-failed --since=24h`): a genuine current outage still
            // piles >10 rows within 24h, but ancient orphans (handled by outbox:rescue /
            // :prune, not readiness) no longer flap the gate.
            $staleCount = (int) DB::table('domain_events')
                ->where('created_at', '<', now()->subSeconds(30))
                ->where('created_at', '>=', now()->subDay())
                ->whereNull('dispatched_at')
                ->where(function ($q) {
                    $q->whereNull('last_error')
                        ->orWhere('last_error', 'not like', 'contract_violation%');
                })
                ->count();

            if ($staleCount > 10) {
                return [
                    'status' => 'error',
                    'stale_count' => $staleCount,
                    'message' => "Queue worker appears down or lagging: {$staleCount} stale outbox events (>10).",
                ];
            }

            return ['status' => 'ok', 'stale_count' => $staleCount];
        } catch (\Throwable $e) {
            // [AUDIT-F-015] If the probe itself crashes (e.g. missing table
            // during a migration window), we DO NOT want /ready to flap to
            // 503 — that would block deployments. Degrade silently here:
            // the operator will see the error in subsystems but the gate
            // stays open. Aligned with checkDb / checkRedis convention.
            return $this->panne($e);
        }
    }

    /**
     * [AUDIT-F-015] Detect production misconfiguration of the broadcast
     * pipeline. After the outbox refactor, `QUEUE_CONNECTION=sync` is
     * incompatible with `DispatchDomainEventsJob` (events still dispatch
     * but the API request thread blocks on broadcast). `BROADCAST_DRIVER`
     * = null/log silently disables realtime entirely.
     *
     * Gate runs in production ONLY. Outside production (local dev, CI,
     * staging without realtime) sync + log are valid configurations and
     * must not flip /ready to 503.
     */
    private function checkBroadcastConfig(): array
    {
        $queue = config('queue.default');
        $broadcast = config('broadcasting.default');

        if (! app()->environment('production')) {
            return ['status' => 'ok', 'queue' => $queue, 'broadcast' => $broadcast];
        }

        if ($queue === 'sync') {
            return [
                'status' => 'error',
                'queue' => $queue,
                'broadcast' => $broadcast,
                'message' => 'QUEUE_CONNECTION=sync is incompatible with the outbox pattern in production. '
                    . 'Set QUEUE_CONNECTION=redis (or database) and run `php artisan queue:work --queue=high` '
                    . 'as a daemon. See docs/REALTIME_SETUP.md.',
            ];
        }

        if (in_array($broadcast, [null, 'null', 'log'], true)) {
            return [
                'status' => 'error',
                'queue' => $queue,
                'broadcast' => $broadcast ?? 'null',
                'message' => "BROADCAST_DRIVER={$broadcast} disables realtime broadcasts in production. "
                    . 'Set BROADCAST_DRIVER=pusher (or compatible Soketi/Ably) and configure PUSHER_* env. '
                    . 'See docs/REALTIME_SETUP.md.',
            ];
        }

        return ['status' => 'ok', 'queue' => $queue, 'broadcast' => $broadcast];
    }
}
