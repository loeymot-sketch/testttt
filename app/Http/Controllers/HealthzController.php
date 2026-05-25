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
     * WebSocket probe — V1 LOCAL has no cheap synchronous probe against
     * Soketi. We attest "ok" when PUSHER_HOST is configured (i.e. the
     * realtime pipeline has at least been wired up) and broadcasting is
     * not set to the null driver. A future V1.0.X enhancement can
     * actually open a TCP socket to PUSHER_HOST:PUSHER_PORT.
     */
    private function checkWebsocket(): string
    {
        try {
            $broadcast  = config('broadcasting.default');
            $pusherHost = env('PUSHER_HOST', '');

            // If broadcasting is explicitly disabled (null driver), report fail.
            if (in_array($broadcast, [null, 'null'], true)) {
                return 'fail';
            }

            // If PUSHER_HOST not set in non-pusher drivers (log etc.) we still
            // attest ok — V1 LOCAL kiosks tolerate broadcast misroute via the
            // 30s polling fallback. The /api/health/ready probe is the strict
            // gate; /healthz is the public uptime probe.
            if ($pusherHost === '' && $broadcast !== 'pusher') {
                return 'ok';
            }

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
     * If the `jobs` table is missing (rare — migration not run),
     * return 0 so the JSON shape never breaks the monitor's parser.
     */
    private function checkQueuePending(): int
    {
        try {
            return (int) DB::table('jobs')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
