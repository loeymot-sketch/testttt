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
