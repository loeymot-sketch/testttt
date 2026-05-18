<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PosLoyaltyRedeemRequest;
use App\Models\Order;
use App\Services\Loyalty\PosRedemptionException;
use App\Services\Loyalty\PosRedemptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] V1 cashier-facing loyalty redeem
 * endpoint per LOCK §3 Option B (separate controller, 0 frozen-zone touch
 * on PosController.php which is currently DIRTY).
 *
 * Endpoint :
 *   POST /api/admin/pos-order/{order}/redeem-loyalty
 *   Body : { "points": int, "loyalty_code": string }
 *   Auth : sanctum + permission:pos.redeem-loyalty (via PosLoyaltyRedeemRequest)
 *   Middleware : route-level `idempotency` (anti-doublon)
 *
 * Returns 200 + { status: true, data: { discount_eur, balance_after, ... } }
 * on success, or a stable error envelope { status: false, code, message } on
 * any anti-fraud violation (see PosRedemptionException for the error_code list).
 */
final class PosLoyaltyController extends Controller
{
    public function __construct(
        private readonly PosRedemptionService $redemptionService,
    ) {
    }

    public function redeem(PosLoyaltyRedeemRequest $request, int $orderId): JsonResponse
    {
        // Bypass branch global scope so a cashier on branch_id=N can redeem
        // for an order he/she just opened (branch_id is already on the route
        // model; if FormRequest authz is required the permission gate above
        // already filtered). We deliberately scope-minimal here.
        $order = Order::withoutGlobalScopes()->find($orderId);
        if (!$order) {
            return response()->json([
                'status'  => false,
                'code'    => 'ORDER_NOT_FOUND',
                'message' => 'Commande introuvable',
            ], 404);
        }

        try {
            $result = $this->redemptionService->applyToOrder(
                $order,
                (int) $request->input('points'),
                (string) $request->input('loyalty_code'),
                (int) ($request->user()?->id ?? 0) ?: null,
            );

            return response()->json([
                'status' => true,
                'data'   => [
                    'discount_eur'  => $result['discount_eur'],
                    'balance_after' => $result['balance_after'],
                    'order'         => [
                        'id'                    => $result['order']->id,
                        'subtotal'              => (float) $result['order']->subtotal,
                        'discount'              => (float) $result['order']->discount,
                        'total'                 => (float) $result['order']->total,
                        'loyalty_customer_code' => $result['order']->loyalty_customer_code,
                    ],
                    'transaction_id' => $result['transaction']->id,
                ],
            ], 200);
        } catch (PosRedemptionException $e) {
            return response()->json([
                'status'  => false,
                'code'    => $e->errorCode,
                'message' => $e->getMessage(),
            ], $e->httpStatus);
        } catch (\Throwable $e) {
            Log::error('[PosLoyaltyRedeem] ' . $e->getMessage(), [
                'order_id'   => $orderId,
                'cashier_id' => $request->user()?->id,
            ]);
            return response()->json([
                'status'  => false,
                'code'    => 'INTERNAL_ERROR',
                'message' => 'Erreur serveur',
            ], 500);
        }
    }
}
