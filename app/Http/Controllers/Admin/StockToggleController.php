<?php

namespace App\Http\Controllers\Admin;

use App\Enums\Status;
use App\Http\Requests\Admin\StockToggleRequest;
use App\Models\ActionLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Services\Menu\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * [F-016b minimal — V1 go-live unblock]
 *
 * Owner-facing endpoint allowing manual rupture toggling per branch.
 * Builds on the existing F-016 infrastructure (`item_branch_availability`
 * + {@see AvailabilityService::toggle()}) for items.
 *
 * Extras and variations *list* but their per-branch toggle endpoint
 * surfaces 501 Not Implemented until the F-016a-BIS migration
 * (`add_manual_unavailable_to_stock_levels`) lands on this branch.
 * Listing them now gives the UI a final layout and pre-wires the
 * tabs so we ship items rupture today without a frontend rewrite
 * tomorrow.
 *
 * Permission gate: reuses `items_edit` (same as
 * {@see AvailabilityController}) so no new permission row is
 * required. Cross-branch isolation is enforced manually below
 * because admin (branch_id=0) must scope to any branch but a
 * branch admin (branch_id>0) must only touch its own.
 */
class StockToggleController extends AdminController
{
    /**
     * Soft cap on the per-tab list. Owner is expected to use Ctrl+F
     * to navigate; we never paginate. Match the budget the user
     * agreed in the F-016b prompt.
     */
    private const MAX_ROWS_PER_TAB = 500;

    public function __construct()
    {
        parent::__construct();

        // Mirror AvailabilityController: items_edit gates every action,
        // including the read-only ones (the page is restricted to staff
        // who can already manage items anyway).
        $this->middleware(['permission:items_edit']);
    }

    /**
     * GET /api/admin/stock/index?branch_id=X
     *
     * Returns three lists for the requested branch:
     *  - items     : every active Item + its current availability for the branch
     *  - extras    : every active ItemExtra + parent Item name
     *  - variations: every active ItemVariation + parent Item + ItemAttribute name
     *
     * For extras and variations, `is_available` defaults to `true`
     * because per-branch toggling for those entities lives in the
     * F-016a-BIS commit which is not present on this branch.
     */
    public function index(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId(
            (int) $request->input('branch_id', 0)
        );

        if ($branchId === null) {
            return response()->json([
                'message' => 'Branch scope denied.',
            ], 403);
        }

        // Items: read availability via the per-branch table.
        $availabilityRows = ItemBranchAvailability::query()
            ->where('branch_id', $branchId)
            ->get(['item_id', 'is_available', 'unavailable_reason'])
            ->keyBy('item_id');

        $items = Item::query()
            ->where('status', Status::ACTIVE)
            ->orderBy('name')
            ->limit(self::MAX_ROWS_PER_TAB)
            ->get(['id', 'name', 'price', 'is_available'])
            ->map(function (Item $item) use ($availabilityRows): array {
                $row = $availabilityRows->get($item->id);
                return [
                    'id'                 => (int) $item->id,
                    'name'               => (string) $item->name,
                    'price'              => (float) $item->price,
                    'is_available'       => $row ? (bool) $row->is_available : (bool) $item->is_available,
                    'unavailable_reason' => $row ? $row->unavailable_reason : null,
                ];
            })
            ->values();

        $extras = ItemExtra::query()
            ->with(['item:id,name'])
            ->where('status', Status::ACTIVE)
            ->orderBy('name')
            ->limit(self::MAX_ROWS_PER_TAB)
            ->get(['id', 'item_id', 'name', 'price'])
            ->map(function (ItemExtra $extra): array {
                return [
                    'id'                 => (int) $extra->id,
                    'item_id'            => (int) $extra->item_id,
                    'item_name'          => $extra->item ? (string) $extra->item->name : null,
                    'name'               => (string) $extra->name,
                    'price'              => (float) $extra->price,
                    // Per-branch toggle for extras lives in F-016a-BIS.
                    // Until that lands, we default to true so the row
                    // renders cleanly in the UI without faking state.
                    'is_available'       => true,
                    'unavailable_reason' => null,
                ];
            })
            ->values();

        $variations = ItemVariation::query()
            ->with(['item:id,name', 'itemAttribute:id,name'])
            ->where('status', Status::ACTIVE)
            ->orderBy('name')
            ->limit(self::MAX_ROWS_PER_TAB)
            ->get(['id', 'item_id', 'item_attribute_id', 'name', 'price'])
            ->map(function (ItemVariation $variation): array {
                return [
                    'id'                 => (int) $variation->id,
                    'item_id'            => (int) $variation->item_id,
                    'item_name'          => $variation->item ? (string) $variation->item->name : null,
                    'attribute_id'       => (int) $variation->item_attribute_id,
                    'attribute_name'     => $variation->itemAttribute ? (string) $variation->itemAttribute->name : null,
                    'name'               => (string) $variation->name,
                    'price'              => (float) $variation->price,
                    // Same caveat as extras: per-branch live in F-016a-BIS.
                    'is_available'       => true,
                    'unavailable_reason' => null,
                ];
            })
            ->values();

        return response()->json([
            'branch_id'  => $branchId,
            'generated_at' => now()->toIso8601String(),
            'items'      => $items,
            'extras'     => $extras,
            'variations' => $variations,
            // Surface the F-016a-BIS dependency so the UI can render
            // a banner without poking the controller for a flag.
            'extras_toggle_supported'     => false,
            'variations_toggle_supported' => false,
        ]);
    }

    /**
     * POST /api/admin/stock/toggle
     *
     * Body: { entity_type, entity_id, branch_id, is_available, reason? }
     */
    public function toggle(StockToggleRequest $request, AvailabilityService $service): JsonResponse
    {
        $type        = (string) $request->input('entity_type');
        $entityId    = (int) $request->input('entity_id');
        $branchId    = (int) $request->input('branch_id');
        $isAvailable = (bool) $request->input('is_available');
        $reason      = $request->input('reason');

        $scopedBranchId = $this->resolveBranchId($branchId);
        if ($scopedBranchId === null || $scopedBranchId !== $branchId) {
            return response()->json([
                'message' => 'Branch scope denied.',
            ], 403);
        }

        if ($type === 'item') {
            return $this->toggleItem($service, $entityId, $branchId, $isAvailable, $reason);
        }

        // Extras and variations require F-016a-BIS columns / methods which
        // are not present on this branch. We surface this explicitly so
        // the UI can render a "merge required" banner instead of failing
        // silently or hard-erroring.
        return response()->json([
            'message'         => 'Per-branch toggle for ' . $type . ' requires F-016a-BIS merge.',
            'entity_type'     => $type,
            'pending_feature' => 'F-016a-BIS',
        ], 501);
    }

    /**
     * GET /api/admin/stock/audit?branch_id=X&limit=50
     *
     * Returns the most recent ActionLog rows whose `action` starts with
     * `stock.` and whose `branch_id` matches the requested branch.
     * We use ActionLog — not the fiscal AuditLog — because rupture is
     * operational, not NF525-immutable; that keeps writes cheap and
     * avoids polluting the fiscal hash chain.
     *
     * Scope note: even a head-office admin (branch_id=0) gets a single
     * branch back per call — `resolveBranchId(0)` defaults to the first
     * active branch. Cross-branch summary is out of scope for V1; the
     * dropdown in the page lets the owner switch branches one at a time.
     */
    public function audit(Request $request): JsonResponse
    {
        $branchId = $this->resolveBranchId(
            (int) $request->input('branch_id', 0)
        );
        if ($branchId === null) {
            return response()->json([
                'message' => 'Branch scope denied.',
            ], 403);
        }

        $limit = (int) $request->input('limit', 50);
        $limit = max(1, min($limit, 200));

        $logs = ActionLog::query()
            ->where('branch_id', $branchId)
            ->where('action', 'like', 'stock.%')
            ->orderByDesc('created_at')
            ->limit($limit)
            ->get(['id', 'user_id', 'branch_id', 'action', 'resource', 'details', 'created_at']);

        return response()->json([
            'branch_id' => $branchId,
            'logs'      => $logs,
        ]);
    }

    /**
     * Apply the items toggle through the canonical AvailabilityService
     * (frozen-zone delegation, no inline DB writes) and write a
     * stock.* ActionLog row in the same request lifecycle.
     */
    private function toggleItem(
        AvailabilityService $service,
        int $entityId,
        int $branchId,
        bool $isAvailable,
        ?string $reason
    ): JsonResponse {
        // Existence guard: the FormRequest does not validate item_id
        // because the entity_type discriminates which table we hit.
        if (!Item::query()->whereKey($entityId)->exists()) {
            return response()->json([
                'message' => 'Item not found.',
            ], 404);
        }

        $service->toggle($entityId, $branchId, $isAvailable, $reason);

        ActionLog::create([
            'user_id'   => Auth::id(),
            'branch_id' => $branchId,
            'action'    => $isAvailable ? 'stock.toggle.available' : 'stock.toggle.unavailable',
            'resource'  => 'item:' . $entityId,
            'details'   => json_encode([
                'entity_type'  => 'item',
                'entity_id'    => $entityId,
                'branch_id'    => $branchId,
                'is_available' => $isAvailable,
                'reason'       => $isAvailable ? null : $reason,
            ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        return response()->json([
            'status'      => 'ok',
            'entity_type' => 'item',
            'entity_id'   => $entityId,
            'branch_id'   => $branchId,
            'is_available' => $isAvailable,
            'unavailable_reason' => $isAvailable ? null : $reason,
        ]);
    }

    /**
     * Resolve the branch the current user is allowed to touch.
     *
     * Conventions in this codebase:
     *   - branch_id = 0 on the user model = head office / super admin,
     *     allowed across every branch (used by AvailabilityController).
     *   - branch_id > 0 = scoped to that single branch.
     *
     * Returns null when the requested branch is outside the user scope.
     */
    private function resolveBranchId(int $requestedBranchId): ?int
    {
        $user = Auth::user();
        $userBranchId = $user ? (int) ($user->branch_id ?? 0) : 0;

        if ($userBranchId === 0) {
            // Head office: any positive branch, or fall back to the first
            // active branch when the request didn't pin one (e.g. initial
            // page load). We avoid returning 0 here because
            // ItemBranchAvailability rows are keyed by a real branch.
            if ($requestedBranchId > 0) {
                return Branch::query()->whereKey($requestedBranchId)->exists()
                    ? $requestedBranchId
                    : null;
            }

            $first = Branch::query()->orderBy('id')->value('id');
            return $first !== null ? (int) $first : null;
        }

        // Branch-scoped admin: ignore whatever branch_id was sent and
        // pin to their own. This blocks cross-tenant tampering even if
        // the form was forged.
        if ($requestedBranchId !== 0 && $requestedBranchId !== $userBranchId) {
            return null;
        }

        return $userBranchId;
    }
}
