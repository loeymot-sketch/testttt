<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Services\Kiosk\KioskMenuService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Kiosk Design V1 — Phase 1.4
 *
 * Endpoint unifié `GET /api/frontend/menu` pour le kiosk.
 *
 * Contrat :
 *  - Auth Sanctum + ability `kiosk:order`.
 *  - branch_id lu depuis `KioskMachine` de l'utilisateur — jamais payload.
 *  - Réponse : enveloppe `{status, data: {branch, categories, items, upsell_rules}}`.
 *  - Cache 60s par branche, invalidé par les events d'availability (future).
 *  - Pas de pagination V1 (menu entier). À surveiller via R5 du plan.
 */
class MenuController extends Controller
{
    public function __construct(private readonly KioskMenuService $menuService = new KioskMenuService())
    {
    }

    public function kiosk(Request $request): JsonResponse
    {
        $user = $request->user();
        if (!$user || !$user->tokenCan('kiosk:order')) {
            return response()->json([
                'status' => false,
                'message' => 'Accès kiosk requis.',
            ], 403);
        }

        $kioskMachine = KioskMachine::query()
            ->where('user_id', $user->id)
            ->first();

        if (!$kioskMachine) {
            return response()->json([
                'status'  => false,
                'message' => 'Borne non associée.',
                'code'    => 'KIOSK_MACHINE_NOT_FOUND',
            ], 503);
        }

        $branchId = (int) $kioskMachine->branch_id;
        $branch = Branch::query()->find($branchId);
        if (!$branch) {
            return response()->json([
                'status'  => false,
                'message' => 'Branche introuvable.',
                'code'    => 'BRANCH_NOT_FOUND',
            ], 503);
        }

        $ttl = (int) config('kiosk.menu_cache_ttl', 60);
        $cacheKey = "kiosk.menu.branch.{$branchId}";

        try {
            $payload = $ttl > 0
                ? Cache::remember($cacheKey, $ttl, fn () => $this->menuService->build($branch))
                : $this->menuService->build($branch);

            return response()->json([
                'status' => true,
                'data'   => $payload,
            ], 200);
        } catch (Exception $e) {
            Log::error('[KioskMenu] '.$e->getMessage(), [
                'branch_id' => $branchId,
                'user_id'   => Auth::id(),
            ]);
            return response()->json([
                'status'  => false,
                'message' => 'Erreur serveur.',
            ], 500);
        }
    }
}
