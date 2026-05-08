<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

/**
 * [P0-5 / KIO-1] Kiosk boot healthcheck endpoint.
 *
 * Lightweight ping used by the kiosk frontend during boot to confirm the
 * backend is reachable BEFORE allowing the cashier/customer to open a
 * payment session. Combined with the frontend hardware healthcheck
 * (kioskBootHealthcheck.js → kioskHardware.healthcheck()), this closes
 * the KIO-1 hidden risk where:
 *
 *   isKioskBridge() === true (Electron present)
 *   ⨯ TPE drivers fail init silently
 *   = tpeCharge returns ok-stub-like → phantom card transactions
 *
 * Public route (apiKey-protected, NOT auth:sanctum) because it runs
 * before the kiosk has authenticated as a KioskMachine. Anonymous-safe.
 *
 * Response contract :
 *  - 200 OK    : { status: "ok", timestamp: "ISO8601" }
 *  - 503       : { status: "degraded", error: "<short>" }
 *
 * The endpoint MUST stay cheap : it only validates DB+cache reachability,
 * NEVER touches business state. The boot screen polls this on retry, so
 * an expensive query here would multiply load when the operator hits
 * "Réessayer" repeatedly during a network blip.
 *
 * @see resources/js/services/kioskBootHealthcheck.js
 * @see plans/PRE_PROD_DEEP_RISK_AUDIT_2026-05-08.md §KIO-1 / §P0-5
 */
class KioskHealthController extends Controller
{
    /**
     * GET /api/frontend/kiosk/health
     *
     * Quick liveness probe for the boot healthcheck overlay.
     */
    public function status(): JsonResponse
    {
        try {
            // 1. DB reachable (cheap : driver-level select).
            DB::connection()->getPdo();
            DB::select('SELECT 1');

            // 2. Cache reachable (best-effort : a write that auto-expires).
            //    This ensures the cache backend (redis/array/file) is wired
            //    correctly. Failures here downgrade the response to degraded.
            $cacheKey = 'kiosk_health_ping';
            Cache::put($cacheKey, now()->toIso8601String(), 60);

            return response()->json([
                'status' => 'ok',
                'timestamp' => now()->toIso8601String(),
            ], 200);
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'degraded',
                'error' => 'backend_subsystem_down',
                'detail' => substr($e->getMessage(), 0, 200),
            ], 503);
        }
    }
}
