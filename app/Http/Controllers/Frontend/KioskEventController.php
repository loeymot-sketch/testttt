<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\ActionLog;
use App\Models\KioskMachine;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * [C6] Kiosk observability endpoint.
 *
 * Receives structured events from the kiosk frontend and persists them
 * in ActionLog so operators can correlate offline failures, auth errors,
 * and other kiosk-specific issues without requiring Sentry.
 *
 * Auth: kiosk:order ability (Sanctum token from KioskMachineLoginController).
 * Route: POST /api/frontend/kiosk-event
 */
class KioskEventController extends Controller
{
    // Allowed event types — whitelist prevents log spam from malformed clients
    private const ALLOWED_TYPES = [
        'order_abandoned',   // offline order gave up after max sync attempts
        'sync_failed',       // offline sync attempt failed
        'auth_error',        // 401 on kiosk route
        'menu_cache_used',   // menu served from IndexedDB snapshot (offline)
        'admin_action',      // staff action via KioskAdminComponent
    ];

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'type'      => ['required', 'string', 'max:50'],
            'count'     => ['nullable', 'integer', 'min:0'],
            'branch_id' => ['nullable', 'integer'],
            'details'   => ['nullable', 'string', 'max:500'],
        ]);

        $type = $request->input('type');

        if (!in_array($type, self::ALLOWED_TYPES, true)) {
            return response()->json(['status' => false, 'message' => 'Unknown event type'], 422);
        }

        $user = Auth::user();
        $machine = $user ? KioskMachine::where('user_id', $user->id)->first() : null;

        $details = sprintf(
            'type=%s | count=%s | branch=%s | machine=%s | %s',
            $type,
            $request->input('count', 1),
            $request->input('branch_id', $machine?->branch_id ?? 'unknown'),
            $machine?->username ?? 'unknown',
            $request->input('details', '')
        );

        try {
            ActionLog::create([
                'user_id'  => $user?->id,
                'action'   => 'Kiosk event: ' . $type,
                'resource' => 'Borne',
                'details'  => $details,
            ]);
        } catch (\Throwable $e) {
            // Non-blocking — log failure must not break the kiosk
            Log::warning('[C6] KioskEvent ActionLog failed: ' . $e->getMessage());
        }

        return response()->json(['status' => true], 200);
    }
}
