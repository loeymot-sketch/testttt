<?php

namespace App\Http\Controllers\Frontend;

use App\Http\Controllers\Controller;
use App\Http\Requests\Kiosk\PricingPreviewRequest;
use App\Models\KioskMachine;
use App\Services\Kiosk\PricingPreviewService;
use Exception;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

/**
 * Kiosk Design V1 — Phase 1.5
 *
 * `POST /api/frontend/pricing/preview` — recalcul SSOT sans persistance.
 *
 * Invariants :
 *  - Aucun prix / total / discount accepté du client (FormRequest strip).
 *  - branch_id lu depuis `KioskMachine`.
 *  - Aucune persistance DB, aucun event.
 *  - Rate-limit (voir routes/api.php).
 */
class PricingPreviewController extends Controller
{
    public function __construct(private readonly PricingPreviewService $service = new PricingPreviewService())
    {
    }

    public function preview(PricingPreviewRequest $request): JsonResponse
    {
        $user = $request->user();
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
        $payload = $request->validated();

        try {
            $result = $this->service->preview(
                branchId: $branchId,
                items: $payload['items'] ?? [],
                customerUserId: (int) $user->id,
                couponCode: $payload['coupon_code'] ?? null,
                kioskPromoCode: $payload['kiosk_promo_code'] ?? null,
            );

            return response()->json([
                'status' => true,
                'data'   => $result,
            ], 200);
        } catch (\InvalidArgumentException $iae) {
            // PricingService lance InvalidArgumentException(422) pour les
            // violations SSOT (item introuvable, guard cross-item, …).
            return response()->json([
                'status'  => false,
                'message' => $iae->getMessage(),
            ], 422);
        } catch (Exception $e) {
            Log::error('[PricingPreview] '.$e->getMessage(), [
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
