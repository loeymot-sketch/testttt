<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\PromoValidateRequest;
use App\Models\KioskMachine;
use App\Services\Kiosk\KioskPromoService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;

/**
 * Kiosk Design V1 — Phase 1.6
 *
 * `POST /api/frontend/promo/validate` — validation lecture-seule.
 *
 * Priorité : kiosk_promos (branch-scoped) > coupons globaux.
 * Pas d'increment uses_count ici (lecture pure). La consommation réelle
 * intervient uniquement à la création de commande via FrontendOrderService.
 */
class PromoController extends Controller
{
    public function __construct(private readonly KioskPromoService $service = new KioskPromoService())
    {
    }

    public function check(PromoValidateRequest $request): JsonResponse
    {
        $user = $request->user();

        $kioskMachine = KioskMachine::query()->where('user_id', $user->id)->first();
        if (!$kioskMachine) {
            return response()->json([
                'status'  => false,
                'message' => 'Borne non associée.',
                'code'    => 'KIOSK_MACHINE_NOT_FOUND',
            ], 503);
        }

        $branchId = (int) $kioskMachine->branch_id;
        $data = $request->validated();

        try {
            $result = $this->service->validate(
                branchId: $branchId,
                code: (string) $data['code'],
                cartTotal: (float) $data['cart_total'],
            );

            return response()->json([
                'status' => $result['valid'],
                'data'   => $result,
                'message' => $result['message'],
            ], $result['valid'] ? 200 : 422);
        } catch (Exception $e) {
            Log::error('[PromoValidate] '.$e->getMessage(), [
                'branch_id' => $branchId,
            ]);
            return response()->json([
                'status'  => false,
                'message' => 'Erreur serveur.',
            ], 500);
        }
    }
}
