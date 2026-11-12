<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\FrontendOrder;
use App\Services\Reorder\ReorderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [PROFIL APP 2026-11-12] `GET /api/frontend/order/{frontendOrder}/reorder` — composition d'une ANCIENNE commande du
 * client, prête à remettre au panier, avec les prix d'aujourd'hui calculés par le serveur. Lecture seule.
 *
 * Isolation : un client ne lit QUE ses propres commandes (403 sinon, sans rien révéler de la commande d'autrui).
 */
class ReorderController extends Controller
{
    public function __construct(private readonly ReorderService $reorder = new ReorderService())
    {
    }

    public function show(Request $request, FrontendOrder $frontendOrder): JsonResponse
    {
        $userId = (int) ($request->user()?->id ?? 0);
        if ($userId <= 0 || (int) $frontendOrder->user_id !== $userId) {
            return response()->json(['status' => false, 'message' => __('all.message.unauthorized')], 403);
        }

        return response()->json(['status' => true, 'data' => $this->reorder->linesFor($frontendOrder, $userId)]);
    }
}
