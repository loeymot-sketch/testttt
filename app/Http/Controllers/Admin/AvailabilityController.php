<?php

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\AvailabilityToggleRequest;
use App\Models\Branch;
use App\Models\ItemBranchAvailability;
use App\Services\Menu\AvailabilityService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AvailabilityController extends AdminController
{
    public function __construct()
    {
        parent::__construct();

        $this->middleware(['permission:items_edit'])->only(['toggle', 'setMaxDailyQty']);
    }

    /**
     * Toggle per-branch availability (manual rupture / 86) for one or many branches.
     *
     * [CV1-V1-CLOSEOUT-001 T-CENT-DEDUP-AVAIL-01] Delegates the actual mutation to
     * {@see AvailabilityService::toggle()} so admin HTTP and internal callers share
     * a single SSOT (locking, idempotency, dispatch-after-commit). Auth, branch
     * scoping and JSON response shape remain owned by the controller (public API).
     *
     * Fan-out semantics (branch_id = null): the controller iterates the caller's
     * branch scope and delegates per-branch. To avoid materializing spurious rows
     * when re-enabling an item that has no per-branch override yet, branches
     * without an existing row are skipped on reactivation (preserves the prior
     * controller behaviour relied on by branch-scoped admin UX).
     */
    public function toggle(AvailabilityToggleRequest $request): JsonResponse
    {
        $validated = $request->validated();
        $itemId = (int) $validated['item_id'];
        $branchId = isset($validated['branch_id']) ? (int) $validated['branch_id'] : null;
        $isAvailable = (bool) $validated['is_available'];
        $reason = $validated['unavailable_reason'] ?? null;
        $scopeBranchIds = $this->resolveScopedBranchIds((int) ($request->user()?->branch_id ?? 0));

        if ($branchId !== null && !in_array($branchId, $scopeBranchIds, true)) {
            return response()->json([
                'message' => 'Branch scope denied.',
            ], 403);
        }

        $targetBranchIds = $branchId !== null ? [$branchId] : $scopeBranchIds;
        $forceUpsert = $branchId !== null;
        $service = app(AvailabilityService::class);

        DB::transaction(function () use (
            $itemId,
            $targetBranchIds,
            $isAvailable,
            $reason,
            $forceUpsert,
            $service
        ): void {
            foreach ($targetBranchIds as $targetBranchId) {
                $targetBranchId = (int) $targetBranchId;

                if (!$forceUpsert && $isAvailable) {
                    $rowExists = ItemBranchAvailability::query()
                        ->where('item_id', $itemId)
                        ->where('branch_id', $targetBranchId)
                        ->lockForUpdate()
                        ->exists();

                    if (!$rowExists) {
                        continue;
                    }
                }

                $service->toggle($itemId, $targetBranchId, $isAvailable, $reason);
            }
        });

        return response()->json([
            'ok' => true,
            'item_id' => $itemId,
            'branch_id' => $branchId,
            'is_available' => $isAvailable,
            'unavailable_reason' => $reason,
        ]);
    }

    /**
     * Update the per-branch daily quota cap and re-evaluate availability immediately.
     *
     * Delegates to {@see AvailabilityService::setMaxDailyQty()} (M2 V2 task 2.5):
     *  - lowering the cap below current consumed qty triggers an auto-86,
     *  - raising it above consumed qty restores availability,
     *  - null means unlimited (always available from the quota perspective).
     *
     * Authorization mirrors {@see toggle()}: requires `items_edit` permission and
     * the requested `branch_id` must be inside the caller's branch scope.
     */
    public function setMaxDailyQty(Request $request): JsonResponse
    {
        $data = $request->validate([
            'item_id' => ['required', 'integer', 'exists:items,id'],
            'branch_id' => ['required', 'integer', 'exists:branches,id'],
            'max_daily_qty' => ['nullable', 'integer', 'min:0'],
        ]);

        $branchId = (int) $data['branch_id'];
        $scopeBranchIds = $this->resolveScopedBranchIds((int) ($request->user()?->branch_id ?? 0));

        if (! in_array($branchId, $scopeBranchIds, true)) {
            return response()->json([
                'message' => 'Branch scope denied.',
            ], 403);
        }

        $row = app(AvailabilityService::class)->setMaxDailyQty(
            (int) $data['item_id'],
            $branchId,
            array_key_exists('max_daily_qty', $data) && $data['max_daily_qty'] !== null
                ? (int) $data['max_daily_qty']
                : null,
        );

        return response()->json([
            'success' => true,
            'data' => [
                'item_id' => (int) $row->item_id,
                'branch_id' => (int) $row->branch_id,
                'is_available' => (bool) $row->is_available,
                'max_daily_qty' => $row->max_daily_qty !== null ? (int) $row->max_daily_qty : null,
                'daily_consumed_qty' => (int) $row->daily_consumed_qty,
                'unavailable_reason' => $row->unavailable_reason,
            ],
        ]);
    }

    /**
     * @return array<int, int>
     */
    private function resolveScopedBranchIds(int $userBranchId): array
    {
        if ($userBranchId !== 0) {
            return [$userBranchId];
        }

        return Branch::query()
            ->pluck('id')
            ->map(fn ($id): int => (int) $id)
            ->all();
    }
}
