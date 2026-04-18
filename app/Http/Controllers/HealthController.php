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

        return response()->json([
            'status' => $allOk ? 'ok' : 'degraded',
            'version' => config('app.version', 'dev'),
            'timestamp' => now()->toIso8601String(),
            'subsystems' => $checks,
        ]);
    }

    public function live(): Response
    {
        return response('OK', 200);
    }

    public function ready(): JsonResponse
    {
        $checks = [
            'db' => $this->checkDb(),
            'redis' => $this->checkRedis(),
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
        $csv = env('HEALTH_IPS_ALLOWED', '');
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
}
