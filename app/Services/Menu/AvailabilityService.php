<?php

namespace App\Services\Menu;

use App\Models\ItemBranchAvailability;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;

final class AvailabilityService
{
    /**
     * Apply daily counters after an order is created (no-op if no availability row exists).
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

            $row->daily_consumed_qty = min(
                $row->max_daily_qty,
                (int) $row->daily_consumed_qty + (int) $line->quantity
            );

            if ($row->daily_consumed_qty >= $row->max_daily_qty) {
                $row->is_available = false;
                $row->unavailable_reason = 'out_of_stock';
                $row->unavailable_since = now();
            }

            $row->save();
        }
    }
}
