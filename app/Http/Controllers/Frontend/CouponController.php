<?php

namespace App\Http\Controllers\Frontend;

use Exception;
use App\Services\CouponService;
use App\Http\Controllers\Controller;
use App\Http\Resources\CouponResource;
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
            return CouponResource::collection($this->couponService->couponDateWise());
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
        if (config('pos.manual_discount_enabled') !== true) {
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
