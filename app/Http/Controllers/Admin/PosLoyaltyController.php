<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\PosLoyaltyRedeemRequest;
use App\Models\Order;
use App\Models\Scopes\BranchScope;
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
        // [HEAL-A.1 2026-05-19] Z6+Z8 cross-confirmed P0: Spatie permission
        // `pos.redeem-loyalty` is global per-user, NOT branch-bound. Pre-heal
        // a cashier on branch=5 could redeem against an order on branch=3.
        // Mirror PosOrderController::show:113-121 pattern: bypass BranchScope
        // (singular — preserves SoftDeletingScope so soft-deleted orders are
        // not silently leaked, per Z6 P1-Z6-03) then explicit post-fetch
        // branch check. Admin (branch_id=0) bypass per BranchScope:33-36.
        $order = Order::withoutGlobalScope(BranchScope::class)->find($orderId);
        if (!$order) {
            return response()->json([
                'status'  => false,
                'code'    => 'ORDER_NOT_FOUND',
                'message' => 'Commande introuvable',
            ], 404);
        }
        $userBranchId = (int) ($request->user()?->branch_id ?? -1);
        if ($userBranchId !== 0 && $userBranchId !== (int) $order->branch_id) {
            abort(403, 'Cross-branch access denied');
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
                    // [F3-04] cible du filtre solde-live côté modal POS.
                    'customer_user_id' => $result['customer_user_id'] ?? null,
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
