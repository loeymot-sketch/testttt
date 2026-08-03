<?php

namespace App\Http\Controllers\Admin;

use App\Services\Stock\UnifiedStockViewService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [PHASE 3d — VUE CONSO & STOCK UNIFIÉE 2026-07-24] Endpoint LECTURE SEULE de la
 * vue « clarté » : matières premières + boissons dans un seul tableau, + section
 * « à acheter ». Délègue tout à {@see UnifiedStockViewService} (aucune logique
 * métier ici). Gate `permission:items_show` (miroir de catalog-overview, écran de
 * lecture). Domaine ADDITIF, HORS NF525 — 0 écriture.
 */
class UnifiedStockViewController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:items_show'])->only('overview');
    }

    /**
     * GET /api/admin/stock/unified-overview — vue unifiée conso & stock.
     *
     * Branche résolue depuis l'utilisateur (admin branch_id=0 → branche 1, V1
     * mono-poste) ; le service hard-scope de toute façon.
     */
    public function overview(Request $request, UnifiedStockViewService $service): JsonResponse
    {
        $branchId = (int) ($request->user()?->branch_id ?: UnifiedStockViewService::BRANCH_ID);

        return response()->json($service->overview($branchId));
    }
}
