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

    public function upsell(Item $item)
    {
        try {
            // Algorithme d'Upsell: on retourne les items de la même catégorie, sinon des populaires.
            $suggested = Item::with('category')
                ->where('id', '!=', $item->id)
                ->where('status', 5)
                ->where('item_category_id', $item->item_category_id)
                ->inRandomOrder()
                ->take(3)
                ->get();

            // Si moins de 3 suggestions trouvées, on complète avec des populaires (si applicable)
            if ($suggested->count() < 3) {
                $more = Item::with('category')
                    ->where('id', '!=', $item->id)
                    ->where('status', 5)
                    ->whereNotIn('id', $suggested->pluck('id')->toArray())
                    ->inRandomOrder()
                    ->take(3 - $suggested->count())
                    ->get();
                $suggested = $suggested->merge($more);
            }

            return SimpleItemResource::collection($suggested);
        } catch (Exception $exception) {
            return response(['status' => false, 'message' => $exception->getMessage()], 422);
        }
    }

}
