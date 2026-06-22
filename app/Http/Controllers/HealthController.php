<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
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
        ];

        $allOk = collect($checks)->every(fn ($c) => $c['status'] === 'ok');

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'subsystems' => $checks,
        ], $allOk ? 200 : 503);
    }

    /**
     * When HEALTH_IPS_ALLOWED is non-empty, only listed IPs may call the full health report.
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
            return ['status' => 'error', 'message' => $e->getMessage()];
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
            return ['status' => 'error', 'message' => $e->getMessage()];
        }
    }

    private function checkQueue(): array
    {
        try {
            $defaultSize = Queue::size('default');
            $highSize = Queue::size('high');

            return ['status' => 'ok', 'default_size' => $defaultSize, 'high_size' => $highSize];
        } catch (\Throwable $e) {
            return ['status' => 'error', 'message' => $e->getMessage()];
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
     * If more than 10 `domain_events` rows older than 30s are still
     * `dispatched_at = NULL`, the queue worker is most likely down or
     * lagging far behind. Surfacing this at /ready lets supervisors and
     * load-balancer probes pull the node out of rotation BEFORE the
     * 30s polling fallback masks the silent failure to operators.
     *
     * Stays cheap: a single indexed COUNT() against `idx_pending`.
     * Threshold is intentionally NOT configurable here — the operator
     * tuneable lives on the `foodking:outbox:monitor` command (--threshold).
     * /ready is a binary "rotate me out" probe, not a tuning surface.
     */
    private function checkQueueWorker(): array
    {
        try {
            $staleCount = (int) DB::table('domain_events')
                ->where('created_at', '<', now()->subSeconds(30))
                ->whereNull('dispatched_at')
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
            return ['status' => 'error', 'message' => $e->getMessage()];
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
