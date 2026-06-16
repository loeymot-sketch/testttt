<?php

namespace App\Http\Controllers\Admin;

use Exception;
use App\Models\Offer;
use App\Services\OfferItemService;
use App\Http\Requests\PaginateRequest;
use App\Http\Requests\OfferItemRequest;
use App\Http\Resources\OfferItemResource;
use App\Models\OfferItem;

class OfferItemController extends AdminController
{

    public OfferItemService $offerItemService;

    public function __construct(OfferItemService $offerItemService)
    {
        parent::__construct();
        $this->offerItemService = $offerItemService;
        $this->middleware(['permission:offers_show'])->only('index', 'store', 'destroy');
        // [GOAL-GOLIVE-VAT10 / S1 2026-05-30] Assigning an item to an offer is the
        // actual mischarge trigger (item then displays a discount PricingService
        // never charges). Blocked for V1 until offers are wired into PricingService.
        $this->middleware(function ($request, $next) {
            abort_unless(
                config('features.offers_enabled') === true,
                403,
                "Le module Offres est désactivé en V1 (prix affiché non appliqué à la caisse)."
            );
            return $next($request);
        })->only('store');
    }

    public function index(
        PaginateRequest $request,
        Offer $offer
    ) : \Illuminate\Http\Response | \Illuminate\Http\Resources\Json\AnonymousResourceCollection | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return OfferItemResource::collection($this->offerItemService->list($request, $offer));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }


    public function store(
        OfferItemRequest $request,
        Offer $offer
    ) : \Illuminate\Http\Response | OfferItemResource | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return new OfferItemResource($this->offerItemService->store($request, $offer));
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }

    public function destroy(
        Offer $offer,
        OfferItem $offerItem
    ) : \Illuminate\Http\Response | \Illuminate\Contracts\Foundation\Application | \Illuminate\Contracts\Routing\ResponseFactory {
        try {
            $this->offerItemService->destroy($offer, $offerItem);
            return response('', 202);
        } catch (Exception $exception) {
            return $this->jsonError($exception, 422);
        }
    }
}
