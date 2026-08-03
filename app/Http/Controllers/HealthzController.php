<?php

namespace App\Http\Controllers;

use App\Services\Fiscal\AuditLogService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Redis;

/**
 * [GAP-HUNT 2026-05-25 Phase A.1 / OPS-GATE-1]
 *
 * Lightweight unauthenticated health probe for external uptime monitors
 * (UptimeRobot / Cronitor / Better Stack). Returns a compact JSON payload
 * with per-subsystem ok|fail status so the monitor can fire a single
 * alert without parsing a verbose body.
 *
 * Contract:
 *   GET /api/healthz → 200/503/200(degraded)
 *   {
 *     "status": "ok|degraded|fail",
 *     "checks": {
 *       "db": "ok|fail",
 *       "redis": "ok|fail",
 *       "websocket": "ok|fail",
 *       "fiscal_chain": "ok|fail",
 *       "queue_pending": <int>
 *     },
 *     "timestamp": "ISO8601"
 *   }
 *
 * HTTP status policy (lenient for V1, per Phase A.1 mandate):
 *   - all subsystem checks ok  → 200 + status=ok
 *   - mixed                    → 200 + status=degraded  (page-but-do-not-rotate)
 *   - all fail                 → 503 + status=fail
 *
 * Companion command: `php artisan healthz:check` (exits 0 ok / 1 fail)
 * Companion cron:    every 5 min → storage/logs/heartbeat.log
 * Companion doc:     scripts/deploy/UPTIMEROBOT_SETUP.md
 *
 * NF525 / frozen-zone: this controller READS the fiscal chain (no writes,
 * no schema touch). Frozen-zone diff = 0.
 */
class HealthzController extends Controller
{
    public function __invoke(): JsonResponse
    {
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

        $okCount   = count(array_filter($statusChecks, fn ($v) => $v === 'ok'));
        $failCount = count(array_filter($statusChecks, fn ($v) => $v === 'fail'));

        if ($failCount === 0) {
            $status = 'ok';
            $http   = 200;
        } elseif ($okCount === 0) {
            $status = 'fail';
            $http   = 503;
        } else {
            // Lenient V1: mixed = degraded but still 200 so UptimeRobot
            // shows a soft alert without flapping the monitor red.
            $status = 'degraded';
            $http   = 200;
        }

        return response()->json([
            'status'    => $status,
            'checks'    => $checks,
            'timestamp' => now()->toIso8601String(),
        ], $http);
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

    /**
     * Redis probe. Tolerates dev/test environments where Redis is replaced
     * by the array cache driver (Redis facade still resolves but ping
     * returns falsy). Match the existing HealthController::checkRedis
     * pattern so a stale localhost dev does not flip the gate to fail.
     */
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
     * WebSocket probe.
     *
     * [OPS-2 2026-06-04] Made HONEST. The previous implementation returned
     * 'ok' for ANY broadcast driver that was not `null` — it NEVER opened a
     * socket to Soketi, so a crashed websocket server reported healthy and
     * the pager stayed silent. Now:
     *   - null driver               → 'fail' (realtime explicitly disabled).
     *   - pusher driver             → real TCP connect to the configured
     *                                 host:port (short timeout). Connection
     *                                 refused / timeout → 'fail'.
     *   - other drivers (log etc.)  → INFORMATIONAL 'ok' — there is no socket
     *                                 to probe and V1 LOCAL tolerates the 30s
     *                                 polling fallback. /api/health/ready is
     *                                 the strict gate; /healthz stays lenient.
     */
    private function checkWebsocket(): string
    {
        return self::probeWebsocket();
    }

    /**
     * Shared honest websocket probe (used by HealthzController and the
     * `healthz:check` CLI mirror) so the two surfaces never drift.
     */
    public static function probeWebsocket(): string
    {
        try {
            $broadcast = config('broadcasting.default');

            if (in_array($broadcast, [null, 'null'], true)) {
                return 'fail';
            }

            if ($broadcast !== 'pusher') {
                // No socket to probe (log/redis/ably handled elsewhere) —
                // informational ok, polling fallback covers realtime.
                return 'ok';
            }

            $host = (string) config('broadcasting.connections.pusher.options.host', '');
            $port = (int) config('broadcasting.connections.pusher.options.port', 0);

            if ($host === '' || $port <= 0) {
                // Pusher selected but unconfigured = a real misconfiguration.
                return 'fail';
            }

            $errno = 0;
            $errstr = '';
            $sock = @fsockopen($host, $port, $errno, $errstr, 1.0);
            if ($sock === false) {
                return 'fail';
            }
            fclose($sock);

            return 'ok';
        } catch (\Throwable $e) {
            return 'fail';
        }
    }

    /**
     * Run AuditLogService::verifyChain on branch 1 (V1 LOCAL Le Cayenne
     * single tenant). Empty chain (verifyChain returns null) = ok.
     * Tampered row (returns an int id) = fail.
     *
     * NF525 invariant: this is read-only (verifyChain re-walks via
     * cursor — no UPDATE / DELETE / INSERT). Frozen-zone diff = 0.
     */
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
     * Pending queue size. Returns an int per the OPS-GATE-1 contract
     * (NOT a string ok|fail) so the monitor can graph the value.
     *
     * [OPS-2 2026-06-04] Made HONEST. The previous implementation counted
     * the `jobs` DB table — but this box runs QUEUE_CONNECTION=redis, so
     * that table is always empty and the metric was a constant 0 (it could
     * never surface a backed-up queue). Now uses the driver-agnostic
     * Queue::size() against the same queues HealthController::checkQueue
     * graphs (default + high). Returns 0 on any driver error so the JSON
     * shape never breaks the monitor's parser.
     */
    private function checkQueuePending(): int
    {
        return self::probeQueuePending();
    }

    /**
     * Shared honest queue-depth probe (used by HealthzController and the
     * `healthz:check` CLI mirror).
     */
    public static function probeQueuePending(): int
    {
        try {
            return (int) \Illuminate\Support\Facades\Queue::size('default')
                + (int) \Illuminate\Support\Facades\Queue::size('high');
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
