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

        if ($this->option('json')) {
            $this->line((string) json_encode($payload, JSON_UNESCAPED_SLASHES));
        } else {
            $this->line(sprintf(
                '[%s] healthz=%s db=%s redis=%s ws=%s fiscal=%s queue=%d',
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

    private function checkWebsocket(): string
    {
        try {
            $broadcast  = config('broadcasting.default');
            $pusherHost = env('PUSHER_HOST', '');

            if (in_array($broadcast, [null, 'null'], true)) {
                return 'fail';
            }

            if ($pusherHost === '' && $broadcast !== 'pusher') {
                return 'ok';
            }

            return 'ok';
        } catch (\Throwable $e) {
            return 'fail';
        }
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

    private function checkQueuePending(): int
    {
        try {
            return (int) DB::table('jobs')->count();
        } catch (\Throwable $e) {
            return 0;
        }
    }
}
