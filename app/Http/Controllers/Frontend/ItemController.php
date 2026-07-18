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
            // [F-DETAILS-BRANCH-AVAIL 2026-07-15] Passe le branch_id borne pour que les détails
            // reflètent la rupture PAR BRANCHE (cécité mid-wizard fermée).
            $branchId = request()->filled('branch_id') ? (int) request('branch_id') : null;
            return new NormalItemResource($this->itemService->itemDetails($item, $branchId));
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

            // [F-UPSELL-COMPOSE-GUARD 2026-07-18 / P1-4] L'upsell borne est un ajout
            // 1-tap SANS wizard. Un item qui EXIGE une composition — attribut requis
            // (min_select>=1) ou profil composer publié — ajouté 1-tap part avec un
            // payload variations VIDE → OrderRequest REJECT 422 « Sélectionnez au moins
            // 1 … » au paiement (bouton Payer mort, cas prouvé item 40 « Menu Enfant
            // Nuggets »). On l'exclut à la SOURCE (point d'exposition) : un item « à
            // personnaliser » n'a rien à faire dans un pool 1-tap.
            //
            // [F-UPSELL-BRANCH-AVAIL 2026-07-18 / P2-borne(F5)] Miroir du heal itemDetails :
            // un item 86 PAR BRANCHE (item_branch_availability) peut être suggéré puis
            // refusé au quote/paiement (contourne pruneUnavailableLines). branch_id lu en
            // query (route publique, comme itemDetails), injecté par le store borne.
            // Le 86 GLOBAL (is_available) et le hors-canal borne sont filtrés dans la
            // requête ci-dessous.
            $branchId = $request->filled('branch_id') ? (int) $request->input('branch_id') : null;
            $hardExclude = array_values(array_unique(array_merge(
                $excludeIds,
                $this->composeRequiredItemIds(),
                $this->branchUnavailableItemIds($branchId),
            )));

            // Pool commun : ACTIF + globalement disponible + catégorie autorisée au pool
            // upsell + visible sur le canal borne (null channels = toutes surfaces).
            $applyPool = function ($q) use ($hardExclude): void {
                $q->where('status', \App\Enums\Status::ACTIVE)
                    ->where('is_available', true)
                    ->whereNotIn('id', $hardExclude)
                    ->whereHas('category', function ($c): void {
                        $c->where('kiosk_upsell_include', true);
                    });
                $this->itemService->applyChannelsFilter($q, 'kiosk');
            };

            // Priority 1: items explicitly flagged as upsell
            // [GAP-27-2] Ask::YES = 5 (not boolean true=1) — must use integer comparison
            $upsellItems = Item::query()
                ->where($applyPool)
                ->where('is_upsell', \App\Enums\Ask::YES)
                ->inRandomOrder()
                ->take($limit)
                ->get();

            // Priority 2: if not enough is_upsell items, fallback to is_featured (same rules)
            if ($upsellItems->count() < $limit) {
                $needed   = $limit - $upsellItems->count();
                $usedIds  = array_values(array_unique(array_merge(
                    $hardExclude,
                    $upsellItems->pluck('id')->map(fn ($id): int => (int) $id)->all()
                )));
                $featured = Item::query()
                    ->where($applyPool)
                    ->whereNotIn('id', $usedIds)
                    ->where('is_featured', \App\Enums\Ask::YES)
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

    /**
     * [P1-4] Item IDs qui EXIGENT une composition (wizard) et ne doivent donc jamais
     * apparaître dans le pool upsell 1-tap :
     *   (a) au moins une variation ACTIVE dont l'attribut est requis (min_select>=1) —
     *       critère MIROIR de MultiVariationConstraint (la source du 422) ;
     *   (b) un profil composer PUBLIÉ au niveau item.
     *
     * @return list<int>
     */
    private function composeRequiredItemIds(): array
    {
        // (a) attribut requis (min_select>=1) porté par une variation active
        $requiredAttrIds = \App\Models\ItemAttribute::query()
            ->where('min_select', '>=', 1)
            ->pluck('id')
            ->all();

        $requiredAttrItemIds = $requiredAttrIds === []
            ? []
            : \App\Models\ItemVariation::query()
                ->whereIn('item_attribute_id', $requiredAttrIds)
                ->where('status', \App\Enums\Status::ACTIVE)
                ->distinct()
                ->pluck('item_id')
                ->map(fn ($id): int => (int) $id)
                ->all();

        // (b) profil composer publié (item-level). withoutGlobalScope : la route est
        // publique (pas d'auth) → filtre déterministe, indépendant du contexte branche.
        $profileItemIds = \App\Models\ItemWizardProfile::query()
            ->withoutGlobalScope(\App\Models\Scopes\WizardProfileBranchScope::class)
            ->where('is_published', true)
            ->whereNotNull('item_id')
            ->distinct()
            ->pluck('item_id')
            ->map(fn ($id): int => (int) $id)
            ->all();

        return array_values(array_unique(array_merge($requiredAttrItemIds, $profileItemIds)));
    }

    /**
     * [P2-borne(F5)] Item IDs indisponibles SUR CETTE BRANCHE
     * (item_branch_availability.is_available=false). Miroir de la dispo branche de
     * itemDetails/simpleList. BranchScope retiré (déterministe sur route publique,
     * cf. ItemService::availabilityCounts).
     *
     * @return list<int>
     */
    private function branchUnavailableItemIds(?int $branchId): array
    {
        if ($branchId === null || $branchId < 1) {
            return [];
        }

        return \App\Models\ItemBranchAvailability::query()
            ->withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('is_available', false)
            ->pluck('item_id')
            ->map(fn ($id): int => (int) $id)
            ->all();
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
