<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Services\Recommendation\UpsellRecommendationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * V2-3 Phase A — AI Upsell recommendation endpoint (greenfield).
 *
 * `POST /api/frontend/recommendations/upsell`
 *
 * Cohabitation V1.x :
 *   - `UpsellController::suggest` (route GET /api/frontend/upsell) reste
 *     l'endpoint kiosk autoritaire pour les upsell admin-curated.
 *   - Ce controller sert le nouvel endpoint POST séparé, lui-même
 *     configuré via `config('recommendation.strategy')`.
 *
 * Auth: `auth:sanctum` + `kiosk:order` ability (parité avec UpsellController
 *   existant). Throttle: 30 requêtes/minute.
 *
 * Voir `plans/PLAN_DESIGN_V2_3_AI_UPSELL_2026-05-08.md`.
 */
class UpsellRecommendationController extends Controller
{
    public function __construct(private readonly UpsellRecommendationService $service)
    {
    }

    public function recommend(Request $request): JsonResponse
    {
        // Auth gate aligné avec UpsellController existant.
        $user = $request->user();
        if (!$user || !$user->tokenCan('kiosk:order')) {
            return response()->json([
                'status'  => false,
                'message' => 'Accès kiosk requis.',
            ], 403);
        }

        $validated = $request->validate([
            'cart'                 => 'required|array|min:1',
            'cart.*.item_id'       => 'required|integer|min:1',
            'cart.*.quantity'      => 'required|integer|min:1',
            'cart.*.category_id'   => 'sometimes|integer|min:1',
            'cart.*.price'         => 'sometimes|numeric|min:0',
            'branch_id'            => 'required|integer|min:1',
        ]);

        // [SEC-HEAL-2026-05-08 iter2] Ultra-review P1 finding : kiosk authentifié
        // pouvait requérir des recommendations cross-branche via branch_id body.
        // Item / ItemCategory / ItemBranchAvailability n'ont pas de BranchScope
        // global → menu cross-branch leak exploitable. Pattern imité de
        // UpsellPreviewController Wave A1 (branch_id=0 = head-office, sinon
        // strict equality). CLAUDE.md non-négociable #8 : "Branch isolation
        // must never be weakened."
        $userBranchId = (int) ($user->branch_id ?? 0);
        if ($userBranchId !== 0 && $userBranchId !== (int) $validated['branch_id']) {
            return response()->json([
                'status'  => false,
                'message' => 'Branch scope denied — kiosk lié à une autre branche.',
            ], 403);
        }

        $recommendations = $this->service->recommend(
            $validated['cart'],
            (int) $validated['branch_id'],
            $user->id ? (int) $user->id : null
        );

        return response()->json([
            'data' => $recommendations,
        ]);
    }
}
