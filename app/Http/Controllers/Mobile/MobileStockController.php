<?php

namespace App\Http\Controllers\Mobile;

use App\Enums\Status;
use App\Http\Controllers\Controller;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemCategory;
use App\Models\Scopes\BranchScope;
use App\Services\Menu\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * [GOAL MEGA W-MOBILE 2026-07-22] Données stock du mini-app mobile (/m), PIN-gated.
 *
 *  - catalog() : lecture PRODUITS (items) par catégorie + état dispo/rupture pour
 *    la branche unique V1, en miroir de la lecture du dashboard stock
 *    ({@see \App\Http\Controllers\Admin\StockRuptureDashboardController::catalogOverview}
 *    étapes 1-2) mais réduite aux items (l'owner ne bascule que des produits).
 *  - toggle() : délègue au SSOT {@see AvailabilityService::toggle()} (verrou +
 *    idempotence + dispatch after-commit vers POS/KDS/borne). AUCUN chemin
 *    parallèle : raison 'stock_rupture' comme le 86 manuel admin.
 *
 * HORS NF525 (stock uniquement). AUCUNE donnée fiscale/CA n'est exposée ici.
 */
class MobileStockController extends Controller
{
    /**
     * Full item catalogue (active categories + active items) with the effective
     * per-branch availability for the single V1 branch. Ruptures are also
     * surfaced flat as the "À acheter" shopping list.
     */
    public function catalog(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId();

        // 1) Categories + active items (eager, mirrors catalogOverview step 1).
        $categories = ItemCategory::query()
            ->where('status', Status::ACTIVE)
            ->orderBy('sort')
            ->orderBy('id')
            ->with(['items' => function ($q): void {
                $q->where('status', Status::ACTIVE)
                    ->orderBy('order')
                    ->orderBy('id');
            }])
            ->get();

        $allItemIds = $categories->flatMap(fn (ItemCategory $cat) => $cat->items->pluck('id'))
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values()
            ->all();

        // 2) Per-branch overrides (one SELECT). No authenticated user here, so
        //    BranchScope is inert — we hard-scope by branch_id explicitly and drop
        //    the global scope defensively (mirrors catalogOverview step 2).
        $itemOverrides = ItemBranchAvailability::query()
            ->withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->whereIn('item_id', $allItemIds ?: [0])
            ->get(['item_id', 'is_available', 'unavailable_reason'])
            ->keyBy('item_id');

        $shopping = [];
        $categoriesPayload = $categories->map(function (ItemCategory $cat) use ($itemOverrides, &$shopping): array {
            $items = $cat->items->map(function (Item $item) use ($itemOverrides, $cat, &$shopping): array {
                $override = $itemOverrides->get((int) $item->id);
                $isAvailable = (bool) $item->is_available;
                $reason = null;
                if ($override !== null && ! (bool) $override->is_available) {
                    $isAvailable = false;
                    $reason = $override->unavailable_reason !== null
                        ? (string) $override->unavailable_reason
                        : null;
                }

                $row = [
                    'id' => (int) $item->id,
                    'name' => (string) $item->name,
                    'is_available' => $isAvailable,
                    'reason' => $reason,
                ];

                if (! $isAvailable) {
                    $shopping[] = [
                        'id' => (int) $item->id,
                        'name' => (string) $item->name,
                        'category' => (string) $cat->name,
                    ];
                }

                return $row;
            })->values()->all();

            return [
                'id' => (int) $cat->id,
                'name' => (string) $cat->name,
                'items' => $items,
            ];
        })->values()->all();

        return response()->json([
            'branch_id' => $branchId,
            'shopping' => $shopping,
            'categories' => $categoriesPayload,
            'fetched_at' => now()->toIso8601String(),
        ]);
    }

    /**
     * Toggle one item's availability for the single V1 branch. Delegates to the
     * shared SSOT service (no parallel write path). reason='stock_rupture' when
     * marking a rupture — identical to the admin manual 86.
     */
    public function toggle(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'is_available' => ['required', 'boolean'],
        ]);

        $itemId = (int) $validated['item_id'];
        $isAvailable = (bool) $validated['is_available'];
        $branchId = $this->resolveBranchId();
        $reason = $isAvailable ? null : 'stock_rupture';

        $row = app(AvailabilityService::class)->toggle($itemId, $branchId, $isAvailable, $reason);

        return response()->json([
            'ok' => true,
            'item_id' => $itemId,
            'branch_id' => $branchId,
            'is_available' => (bool) $row->is_available,
            'unavailable_reason' => $row->unavailable_reason,
        ]);
    }

    /**
     * Resolve the single active V1 branch. No authenticated user on this PIN-gated
     * surface, so we take the first active branch (mono-branche = branch_id 1).
     * Mirrors the status filter of StockRuptureDashboardController::scopedBranches().
     */
    private function resolveBranchId(): int
    {
        return (int) (Branch::query()
            ->whereNull('deleted_at')
            ->whereIn('status', [Status::ACTIVE, 1])
            ->orderBy('id')
            ->value('id') ?? 1);
    }
}
