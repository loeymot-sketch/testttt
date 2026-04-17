<?php

namespace App\Services\Menu;

use App\Events\ItemAvailabilityChanged;
use App\Models\Branch;
use App\Models\ItemBranchAvailability;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for branch-scoped menu availability (rupture / 86).
 *
 * Responsibilities:
 *  - Toggle manual rupture on/off for a given (item, branch).
 *  - Snapshot current availability for read paths (POS / Kiosk filters).
 *  - Auto-86 when daily counters reach the configured cap.
 *
 * All mutations are transactional and emit {@see ItemAvailabilityChanged::forBranch()}
 * on success, which is persisted to the outbox and broadcast on the branch channel.
 */
final class AvailabilityService
{
    /**
     * Manual toggle (admin UI). Creates the row if missing. Idempotent:
     * re-toggling to the same state returns without emitting.
     *
     * @param  string|null  $reason  Required semantically when $available=false; enforced at UI level.
     * @return ItemBranchAvailability
     */
    public function toggle(
        int $itemId,
        int $branchId,
        bool $available,
        ?string $reason = null
    ): ItemBranchAvailability {
        return DB::transaction(function () use ($itemId, $branchId, $available, $reason): ItemBranchAvailability {
            $row = ItemBranchAvailability::query()
                ->where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();

            if (!$row) {
                $row = new ItemBranchAvailability([
                    'item_id'            => $itemId,
                    'branch_id'          => $branchId,
                    'is_available'       => $available,
                    'unavailable_reason' => $available ? null : $reason,
                    'unavailable_since'  => $available ? null : now(),
                    'daily_consumed_qty' => 0,
                    'daily_reset_at'     => Carbon::today()->toDateString(),
                ]);
                $row->save();
                $this->dispatchEvent($itemId, $branchId, $available, $reason);

                return $row;
            }

            if ((bool) $row->is_available === $available && $row->unavailable_reason === ($available ? null : $reason)) {
                return $row;
            }

            $row->is_available       = $available;
            $row->unavailable_reason = $available ? null : $reason;
            $row->unavailable_since  = $available ? null : now();
            $row->save();

            $this->dispatchEvent($itemId, $branchId, $available, $reason);

            return $row;
        });
    }

    /**
     * Toggle the item across every active branch. Returns the count of rows
     * actually touched (excluding idempotent no-ops).
     */
    public function toggleForAllBranches(
        int $itemId,
        bool $available,
        ?string $reason = null
    ): int {
        $count = 0;
        Branch::query()->pluck('id')->each(function (int $branchId) use ($itemId, $available, $reason, &$count): void {
            $before = ItemBranchAvailability::query()
                ->where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->first();
            $this->toggle($itemId, $branchId, $available, $reason);
            if (!$before || (bool) $before->is_available !== $available) {
                $count++;
            }
        });

        return $count;
    }

    /**
     * Read helper for POS / Kiosk snapshot consumers.
     * Absent row = available by default (V1 rule).
     */
    public function isAvailable(int $itemId, int $branchId): bool
    {
        $row = ItemBranchAvailability::query()
            ->where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->first();

        return $row ? (bool) $row->is_available : true;
    }

    /**
     * Apply daily counters after an order is created (no-op if no row exists).
     * Auto-86 once the daily cap is reached.
     */
    public function decrementForOrder(Model $order): void
    {
        $branchId = (int) $order->branch_id;
        $today = Carbon::today()->toDateString();

        foreach ($order->orderItems as $line) {
            $row = ItemBranchAvailability::query()
                ->where('item_id', $line->item_id)
                ->where('branch_id', $branchId)
                ->first();

            if (!$row || $row->max_daily_qty === null) {
                continue;
            }

            if ($row->daily_reset_at?->toDateString() !== $today) {
                $row->daily_consumed_qty = 0;
                $row->daily_reset_at = $today;
            }

            $wasAvailable = (bool) $row->is_available;

            $row->daily_consumed_qty = min(
                $row->max_daily_qty,
                (int) $row->daily_consumed_qty + (int) $line->quantity
            );

            if ($row->daily_consumed_qty >= $row->max_daily_qty) {
                $row->is_available       = false;
                $row->unavailable_reason = 'out_of_stock';
                $row->unavailable_since  = now();
            }

            $row->save();

            // Emit only on availability state flip (was available, now 86).
            if ($wasAvailable && !(bool) $row->is_available) {
                $this->dispatchEvent(
                    (int) $line->item_id,
                    $branchId,
                    false,
                    'out_of_stock'
                );
            }
        }
    }

    private function dispatchEvent(int $itemId, int $branchId, bool $available, ?string $reason): void
    {
        event(ItemAvailabilityChanged::forBranch(
            itemId: $itemId,
            branchId: $branchId,
            isAvailable: $available,
            reason: $reason
        ));
    }
}
