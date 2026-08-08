<?php

namespace App\Http\Controllers\Frontend;

use Exception;
use App\Services\CouponService;
use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
use App\Http\Resources\PublicCouponResource;
use App\Http\Requests\CouponCheckRequest;
use App\Http\Resources\CouponCheckResource;

class CouponController extends Controller
{
    /**
     * Display a listing of the resource.
     *
     * @return \Illuminate\Http\Response
     */

    private CouponService $couponService;

    public function __construct(CouponService $coupon)
    {
        $this->couponService = $coupon;
    }

    public function index() : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        try {
            // [P0 SÉCURITÉ 2026-08-08] Ressource PUBLIQUE, sans `code` : cette route est anonyme
            // (clé d'API publiée dans le meta HTML du site). {@see PublicCouponResource}.
            return PublicCouponResource::collection($this->couponService->couponDateWise());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function couponChecking(CouponCheckRequest $request) : \Illuminate\Http\Response | CouponCheckResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory
    {
        // [A2 cycle 3 · GOAL_WEB_ADVERSARIAL 2026-08-05] Le site ne doit JAMAIS promettre une
        // remise que la commande refusera. Avant cette garde, la validation acceptait le coupon
        // et renvoyait une remise — le site affichait « ✓ CODE · −3,24 € appliqué » et un total
        // remisé sur le bouton — alors que `FrontendOrderService` refuse toute remise quand ce
        // même interrupteur est à false (« Les remises (coupon) sont désactivées en V1. »).
        // Le client se heurtait donc à un mur au DERNIER clic, sans autre issue que de vider
        // lui-même le champ promo : promesse écrite non tenue + vente bloquée.
        // La validation consulte désormais le MÊME interrupteur que la commande.
        // Sentinelle : tests/Feature/Frontend/CouponCheckRespectsDiscountKillSwitchTest.php
        // [FLYER PROMO 2026-08-07] Interrupteur DÉDIÉ aux codes promo. Il doit
        // rester le MIROIR EXACT de la garde de commande
        // (`FrontendOrderService::assertDiscretionaryDiscountAllowed`) : c'est
        // toute la raison d'être de ce bloc — ne jamais promettre au client
        // une remise que la commande refusera au dernier clic.
        $couponsAllowed = config('pos.coupon_codes_enabled') === true
            || config('pos.manual_discount_enabled') === true;

        if (! $couponsAllowed) {
            return response([
                'status'  => false,
                'message' => 'Les codes promo sont désactivés pour le moment.',
            ], 422);
        }

        try {
            return new CouponCheckResource($this->couponService->couponChecking($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }
}
