<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Models\FrontendOrder;
use App\Models\Order;
use App\Models\OrderRating;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * [WAVE-ALPHA-A3 / M-3] CSAT 5-star inline endpoint.
 *
 * POST /api/frontend/order/{orderId}/rating
 *
 * Reçoit une note 1..5 + commentaire optionnel ; idempotent par
 * (order_id, order_type) via updateOrCreate. Throttle externe
 * (10/min) défini dans routes/api.php pour limiter le spam.
 *
 * @see database/migrations/2026_05_08_050000_create_order_ratings_table.php
 */
class OrderRatingController extends Controller
{
    public function store(Request $request, $orderId): JsonResponse
    {
        $request->validate([
            'rating'     => 'required|integer|min:1|max:5',
            'comment'    => 'nullable|string|max:500',
            'order_type' => 'sometimes|in:Order,FrontendOrder',
            'source'     => 'sometimes|in:kiosk,web,mobile,pos',
        ]);

        $orderType = $request->input('order_type', 'FrontendOrder');
        $orderClass = $orderType === 'Order' ? Order::class : FrontendOrder::class;

        // [SEC] Lecture branch-scoped (BranchScope global) — ne JAMAIS strip
        // le scope ici, sinon n'importe quel sanctum-user d'une autre
        // branche pourrait noter (et écraser) la commande d'une branche
        // tierce. Le kiosk est lié à un user dont `branch_id` matche
        // l'order via KioskMachine, donc le find() résout correctement.
        // CLAUDE.md non-négociable #8 : "Branch isolation must never be weakened."
        $order = $orderClass::find($orderId);
        if (!$order) {
            return response()->json([
                'status'  => false,
                'message' => 'Order not found',
            ], 404);
        }

        try {
            $rating = OrderRating::updateOrCreate(
                [
                    'order_id'   => (int) $orderId,
                    'order_type' => $orderType,
                ],
                [
                    'branch_id'      => (int) $order->branch_id,
                    'rating'         => (int) $request->input('rating'),
                    'comment'        => $request->input('comment'),
                    'source_surface' => $request->input('source', 'kiosk'),
                ]
            );

            return response()->json([
                'status' => true,
                'data'   => $rating,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('[OrderRating] write failed', [
                'order_id' => $orderId,
                'error'    => $e->getMessage(),
            ]);
            return response()->json([
                'status'  => false,
                'message' => 'Could not save rating',
            ], 500);
        }
    }
}
