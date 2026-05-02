<?php

namespace App\Http\Controllers\Admin;

use App\Events\ItemAvailabilityChanged;
use App\Http\Requests\Admin\AvailabilityToggleRequest;
use App\Models\Branch;
use App\Models\ItemBranchAvailability;
use App\Services\Menu\AvailabilityService;
use Carbon\Carbon;
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

        $dispatches = [];

        DB::transaction(function () use (
            $branchId,
            &$dispatches,
            $isAvailable,
            $itemId,
            $reason,
            $scopeBranchIds
        ): void {
            $targetBranchIds = $branchId !== null ? [$branchId] : $scopeBranchIds;

            foreach ($targetBranchIds as $targetBranchId) {
                $didChange = $this->toggleBranchAvailability(
                    itemId: $itemId,
                    branchId: $targetBranchId,
                    isAvailable: $isAvailable,
                    reason: $reason,
                    forceUpsert: $branchId !== null
                );

                if ($didChange) {
                    $dispatches[] = [$targetBranchId, $isAvailable, $reason];
                }
            }

            DB::afterCommit(function () use ($dispatches, $itemId): void {
                foreach ($dispatches as [$targetBranchId, $available, $dispatchReason]) {
                    event(ItemAvailabilityChanged::forBranch(
                        itemId: $itemId,
                        branchId: (int) $targetBranchId,
                        isAvailable: (bool) $available,
                        reason: $dispatchReason
                    ));
                }
            });
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

    private function toggleBranchAvailability(
        int $itemId,
        int $branchId,
        bool $isAvailable,
        ?string $reason,
        bool $forceUpsert
    ): bool {
        $row = ItemBranchAvailability::query()
            ->where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if (!$row) {
            if (!$forceUpsert && $isAvailable) {
                return false;
            }

            ItemBranchAvailability::query()->create([
                'item_id' => $itemId,
                'branch_id' => $branchId,
                'is_available' => $isAvailable,
                'unavailable_reason' => $isAvailable ? null : $reason,
                'unavailable_since' => $isAvailable ? null : now(),
                'daily_consumed_qty' => 0,
                'daily_reset_at' => Carbon::today()->toDateString(),
            ]);

            return true;
        }

        $normalizedReason = $isAvailable ? null : $reason;
        if ((bool) $row->is_available === $isAvailable && $row->unavailable_reason === $normalizedReason) {
            return false;
        }

        $row->update([
            'is_available' => $isAvailable,
            'unavailable_reason' => $normalizedReason,
            'unavailable_since' => $isAvailable ? null : now(),
        ]);

        return true;
    }
}
