<?php

namespace App\Console\Commands;

use App\Services\Fiscal\AuditLogService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * [GAP-HUNT 2026-05-25 Phase A.1 / OPS-GATE-1]
 *
 * CLI mirror of `GET /api/healthz`. Exits 0 when every check passes,
 * exit 1 when at least one check fails. Mounted on the Laravel
 * scheduler every 5 minutes via `appendOutputTo(heartbeat.log)`,
 * giving the host a passive heartbeat trail independent of the
 * external uptime probe.
 *
 * Why a separate command and not just `curl /api/healthz` in cron?
 *   - Cron must NOT depend on a running PHP-FPM (post-restart window).
 *   - Avoids tight-coupling between the heartbeat lane and the
 *     web stack: a healthy DB+Redis matters even if nginx is down.
 *   - Exit codes (0/1) are easier to alert on than HTTP body parsing.
 *
 * NF525 / frozen-zone: read-only (no fiscal writes). Diff = 0.
 */
class HealthzCheckCommand extends Command
{
    /**
     * @var string
     */
    protected $signature = 'healthz:check {--json : Emit a machine-parseable JSON line}';

    /**
     * @var string
     */
    protected $description = 'Run the /healthz subsystem checks from the CLI (exit 0 ok / 1 fail)';

    public function handle(): int
    {
        // [F-SCHEDULER-DEADMAN 2026-07-15 / P1] Battement de cœur du scheduler : cette lane
        // tourne toutes les 5 min via schedule:run. Si le scheduler MEURT sur la box (cron/
        // launchd absent — cas réel : backup NF525 mort 21 j sans alerte), ce timestamp se
        // fige → HealthController::ready() le détecte (check `scheduler`) et rend la mort
        // VISIBLE pour UptimeRobot. Cache::forever (pas de TTL) : c'est la fraîcheur qui compte.
        try {
            \Illuminate\Support\Facades\Cache::forever('scheduler:last_tick', now()->timestamp);
        } catch (\Throwable $e) {
            // best-effort : ne jamais faire échouer la lane santé sur l'écriture du battement.
        }

        $checks = [
            'db'            => $this->checkDb(),
            'redis'         => $this->checkRedis(),
            'websocket'     => $this->checkWebsocket(),
            'fiscal_chain'  => $this->checkFiscalChain(),
            'queue_pending' => $this->checkQueuePending(),
        ];

        $statusChecks = [
            $checks['db'],
            $checks['redis'],
            $checks['websocket'],
            $checks['fiscal_chain'],
        ];
        if (($checks['queue_pending'] ?? null) === 'unknown') {
            $statusChecks[] = 'fail';
        }

        $okCount   = count(array_filter($statusChecks, fn ($v) => $v === 'ok'));
        $failCount = count(array_filter($statusChecks, fn ($v) => $v === 'fail'));

        if ($failCount === 0) {
            $status   = 'ok';
            $exitCode = 0;
        } elseif ($okCount === 0) {
            $status   = 'fail';
            $exitCode = 1;
        } else {
            $status   = 'degraded';
            // Lenient V1 matching HTTP probe — degraded still exits 0 so
            // the cron tail (heartbeat.log) doesn't churn the operator
            // with cosmetic alerts. The HTTP probe is the source of truth
            // for paging; this lane is a passive trail.
            $exitCode = 0;
        }

        $payload = [
            'status'    => $status,
            'checks'    => $checks,
            'timestamp' => now()->toIso8601String(),
        ];

        // [PILOTAGE 2026-08-09] Le résultat est conservé pour que l'écran
        // « État du système » de l'administration puisse l'afficher. Sans ça, ce
        // diagnostic n'existait que dans la sortie console et pour la sonde
        // externe : le logiciel se surveillait sans jamais rien en dire au
        // propriétaire. Cache::forever, sans expiration — c'est l'horodatage
        // porté par la charge utile qui dit si la mesure est fraîche, et une
        // clé expirée effacerait justement l'information « plus rien ne mesure ».
        try {
            \Illuminate\Support\Facades\Cache::forever('healthz:last', $payload);
        } catch (\Throwable $e) {
            // Un cache indisponible ne doit pas faire échouer la sonde de santé.
        }

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                '[%s] healthz=%s db=%s redis=%s ws=%s fiscal=%s queue=%s',
                $payload['timestamp'],
                $status,
                $checks['db'],
                $checks['redis'],
                $checks['websocket'],
                $checks['fiscal_chain'],
                $checks['queue_pending'],
            ));
        }

        return $exitCode;
    }

    private function checkDb(): string
    {
        try {
            DB::connection()->getPdo();
            DB::select('SELECT 1');

            return 'ok';
        } catch (\Throwable $e) {
            return 'fail';
        }
    }

    private function checkRedis(): string
    {
        try {
            $pong = Redis::ping();
            $ok = $pong === true
                || $pong === 'PONG'
                || $pong === '+PONG'
                || (is_string($pong) && strtoupper($pong) === 'PONG')
                || (is_object($pong) && method_exists($pong, '__toString') && strtoupper((string) $pong) === 'PONG');

            return $ok ? 'ok' : 'fail';
        } catch (\Throwable $e) {
            return 'fail';
        }
    }

    /**
     * [OPS-2 2026-06-04] Delegate to the shared honest probe so the CLI
     * heartbeat lane and the HTTP /healthz surface never report different
     * websocket health. Real TCP connect to the pusher host:port.
     */
    private function checkWebsocket(): string
    {
        return \App\Http\Controllers\HealthzController::probeWebsocket();
    }

    private function checkFiscalChain(): string
    {
        try {
            /** @var AuditLogService $service */
            $service = app(AuditLogService::class);
            $tamperedId = $service->verifyChain(1);

            return $tamperedId === null ? 'ok' : 'fail';
        } catch (\Throwable $e) {
            return 'fail';
        }
    }

    /**
     * [OPS-2 2026-06-04] Driver-agnostic queue depth (default+high) via the
     * shared probe — the old `jobs` table count was always 0 under redis.
     */
    private function checkQueuePending(): int|string
    {
        try {
            return \App\Http\Controllers\HealthzController::probeQueuePending();
        } catch (\Throwable $e) {
            return 'unknown';
        }
    }
}
