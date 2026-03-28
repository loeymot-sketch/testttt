<?php

namespace App\Http\Controllers\Frontend;


use App\Http\Resources\NormalItemResource;
use App\Http\Resources\SimpleItemResource;
use App\Models\Item;
use Exception;
use App\Http\Controllers\Controller;
use App\Http\Requests\PaginateRequest;
use App\Services\ItemService;

class ItemController extends Controller
{

    public ItemService $itemService;

    public function __construct(ItemService $itemService)
    {
        $this->itemService = $itemService;
    }

    public function index(
        PaginateRequest $request
    ): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return SimpleItemResource::collection($this->itemService->simpleList($request));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function featuredItems(
    ): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return SimpleItemResource::collection($this->itemService->featuredItems());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function mostPopularItems(
    ): \Illuminate\Http\Response|\Illuminate\Http\Resources\Json\AnonymousResourceCollection|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory {
        try {
            return SimpleItemResource::collection($this->itemService->mostPopularItems());
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    public function itemDetails(Item $item)
    {
        try {
            return new NormalItemResource($this->itemService->itemDetails($item));
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /**
     * [SPLASH MERCHANDISING] Smart upsell based on cart item IDs.
     * Splash logic: if basket has meals → suggest drinks + desserts (is_upsell=true).
     * Falls back to featured items if no is_upsell items configured.
     *
     * Usage: GET /frontend/item/kiosk-upsell?item_ids=1,2,3&limit=6
     */
    public function kioskUpsell(\Illuminate\Http\Request $request)
    {
        try {
            $limit     = min((int) $request->input('limit', 6), 12);
            $itemIds   = array_filter(explode(',', $request->input('item_ids', '')));
            $excludeIds = array_map('intval', $itemIds);

            // Priority 1: items explicitly flagged as upsell (category must allow kiosk upsell pool)
            // [GAP-27-2] Ask::YES = 5 (not boolean true=1) — must use integer comparison
            // [Phase A] Exclude items whose category has kiosk_upsell_include = false
            $upsellItems = Item::where('status', \App\Enums\Status::ACTIVE)
                ->where('is_upsell', \App\Enums\Ask::YES)
                ->whereNotIn('id', $excludeIds)
                ->whereHas('category', function ($q) {
                    $q->where('kiosk_upsell_include', true);
                })
                ->inRandomOrder()
                ->take($limit)
                ->get();

            // Priority 2: if not enough is_upsell items, fallback to is_featured (same category rule)
            if ($upsellItems->count() < $limit) {
                $needed   = $limit - $upsellItems->count();
                $usedIds  = array_merge($excludeIds, $upsellItems->pluck('id')->toArray());
                $featured = Item::where('status', \App\Enums\Status::ACTIVE)
                    ->where('is_featured', \App\Enums\Ask::YES)
                    ->whereNotIn('id', $usedIds)
                    ->whereHas('category', function ($q) {
                        $q->where('kiosk_upsell_include', true);
                    })
                    ->inRandomOrder()
                    ->take($needed)
                    ->get();
                $upsellItems = $upsellItems->merge($featured);
            }

            return SimpleItemResource::collection($upsellItems);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

    /** Legacy upsell (kept for backward compat) */
    public function upsell(Item $item)
    {
        try {
            $suggested = Item::with('category')
                ->where('id', '!=', $item->id)
                ->where('status', \App\Enums\Status::ACTIVE)
                // [GAP-27-2] Ask::YES = 5 — integer comparison required
                ->where('is_upsell', \App\Enums\Ask::YES)
                ->inRandomOrder()
                ->take(6)
                ->get();

            if ($suggested->count() < 3) {
                $more = Item::with('category')
                    ->where('id', '!=', $item->id)
                    ->where('status', \App\Enums\Status::ACTIVE)
                    ->whereNotIn('id', $suggested->pluck('id')->toArray())
                    ->where('is_featured', \App\Enums\Ask::YES)
                    ->inRandomOrder()
                    ->take(6 - $suggested->count())
                    ->get();
                $suggested = $suggested->merge($more);
            }

            return SimpleItemResource::collection($suggested);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

}
