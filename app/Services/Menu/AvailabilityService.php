<?php

namespace App\Services\Menu;

use App\Events\ItemAvailabilityChanged;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

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

            if (! $row) {
                $row = new ItemBranchAvailability([
                    'item_id' => $itemId,
                    'branch_id' => $branchId,
                    'is_available' => $available,
                    'unavailable_reason' => $available ? null : $reason,
                    'unavailable_since' => $available ? null : now(),
                    'daily_consumed_qty' => 0,
                    'daily_reset_at' => Carbon::today()->toDateString(),
                ]);
                $row->save();
                $this->dispatchEvent($itemId, $branchId, $available, $reason);

                return $row;
            }

            if ((bool) $row->is_available === $available && $row->unavailable_reason === ($available ? null : $reason)) {
                return $row;
            }

            $row->is_available = $available;
            $row->unavailable_reason = $available ? null : $reason;
            $row->unavailable_since = $available ? null : now();
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
            if (! $before || (bool) $before->is_available !== $available) {
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
     * Reject checkout when any line references an item marked unavailable for this branch.
     * With `$useRowLock=true`, locks existing `item_branch_availability` rows (same DB transaction)
     * so concurrent rupture toggles serialize with order commit. Absence of a row = available (V1).
     * With `$useRowLock=false` (e.g. pricing preview), performs a read-only check without locks.
     *
     * @param  array<int|mixed>  $itemIds
     *
     * @throws \InvalidArgumentException
     */
    public function assertItemsOrderableForBranch(int $branchId, array $itemIds, bool $useRowLock = true): void
    {
        $itemIds = array_values(array_unique(array_filter(array_map(static fn ($id) => (int) $id, $itemIds))));
        if ($branchId < 1 || $itemIds === []) {
            return;
        }

        $catalogItems = Item::query()
            ->select('id', 'status', 'is_available')
            ->whereIn('id', $itemIds)
            ->get()
            ->keyBy('id');

        foreach ($itemIds as $itemId) {
            $item = $catalogItems->get($itemId);
            if (! $item) {
                throw new \InvalidArgumentException(
                    "Article {$itemId} introuvable. Commande rejetée.",
                    422
                );
            }

            if ((int) $item->status !== Status::ACTIVE) {
                throw new \InvalidArgumentException(
                    "Article {$itemId} inactif dans le catalogue. Commande rejetée.",
                    422
                );
            }

            if ($item->is_available !== null && ! (bool) $item->is_available) {
                throw new \InvalidArgumentException(
                    "Article {$itemId} indisponible dans le catalogue. Commande rejetée.",
                    422
                );
            }
        }

        $query = ItemBranchAvailability::query()
            ->where('branch_id', $branchId)
            ->whereIn('item_id', $itemIds)
            ->orderBy('item_id');

        $rows = $useRowLock
            ? $query->lockForUpdate()->get()->keyBy('item_id')
            : $query->get()->keyBy('item_id');

        foreach ($itemIds as $itemId) {
            $row = $rows->get($itemId);
            $available = $row ? (bool) $row->is_available : true;
            if (! $available) {
                $reason = $row && $row->unavailable_reason
                    ? (string) $row->unavailable_reason
                    : 'unavailable';
                throw new \InvalidArgumentException(
                    "Article {$itemId} indisponible pour cette branche ({$reason}).",
                    422
                );
            }
        }
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

            if (! $row || $row->max_daily_qty === null) {
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
                $row->is_available = false;
                $row->unavailable_reason = 'out_of_stock';
                $row->unavailable_since = now();
            }

            $row->save();

            // Emit only on availability state flip (was available, now 86).
            if ($wasAvailable && ! (bool) $row->is_available) {
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

    /**
     * [F-01 + NEW-05] Compensating release of branch-scoped daily counters when an
     * order is canceled or refunded (full or partial). Idempotent per line via
     * the `order_items.released_qty` ledger:
     *
     *   delta = min(requestedQty, quantity - released_qty)
     *
     * Duplicate event delivery (re-fired, or cancel-then-refund) becomes a safe
     * no-op once `released_qty` reaches `quantity`. // allow: docblock-only mention
     * of cancel/refund flow names — no sensitive action performed here.
     *
     * Branch isolation: queries are filtered by both `item_id` AND `branch_id`.
     * After-commit: ItemAvailabilityChanged events emitted on the unavailable→available
     * flip are queued and dispatched via DB::afterCommit (commit-before-dispatch
     * invariant — gate C9 / KI-001).
     *
     * @param array<int, array{order_item_id:int, item_id:int, branch_id:int, qty:int}> $lineItems
     */
    public function releaseForOrderItems(array $lineItems): void
    {
        if ($lineItems === []) {
            return;
        }

        $eventsToDispatch = [];

        DB::transaction(function () use ($lineItems, &$eventsToDispatch): void {
            foreach ($lineItems as $lineItem) {
                $orderItemId  = (int) ($lineItem['order_item_id'] ?? 0);
                $itemId       = (int) ($lineItem['item_id'] ?? 0);
                $branchId     = (int) ($lineItem['branch_id'] ?? 0);
                $requestedQty = max(0, (int) ($lineItem['qty'] ?? 0));

                if ($orderItemId <= 0 || $itemId <= 0 || $branchId <= 0 || $requestedQty <= 0) {
                    continue;
                }

                $orderItem = DB::table('order_items')
                    ->where('id', $orderItemId)
                    ->lockForUpdate()
                    ->first(['id', 'item_id', 'branch_id', 'quantity', 'released_qty']);

                if (! $orderItem) {
                    Log::warning('availability release skipped (order item missing)', [
                        'order_item_id' => $orderItemId,
                        'item_id'       => $itemId,
                        'branch_id'     => $branchId,
                    ]);
                    continue;
                }

                if ((int) $orderItem->item_id !== $itemId
                    || (int) $orderItem->branch_id !== $branchId) {
                    Log::warning('availability release skipped (line item mismatch)', [
                        'order_item_id'      => $orderItemId,
                        'expected_item_id'   => $itemId,
                        'actual_item_id'     => (int) $orderItem->item_id,
                        'expected_branch_id' => $branchId,
                        'actual_branch_id'   => (int) $orderItem->branch_id,
                    ]);
                    continue;
                }

                $remaining = max(0, (int) $orderItem->quantity - (int) $orderItem->released_qty);
                $delta = min($requestedQty, $remaining);

                if ($delta <= 0) {
                    Log::info('availability release skipped (already released)', [
                        'order_item_id'  => $orderItemId,
                        'item_id'        => $itemId,
                        'branch_id'      => $branchId,
                        'requested_qty'  => $requestedQty,
                        'released_qty'   => (int) $orderItem->released_qty,
                        'quantity'       => (int) $orderItem->quantity,
                    ]);
                    continue;
                }

                $availability = DB::table('item_branch_availability')
                    ->where('item_id', $itemId)
                    ->where('branch_id', $branchId)
                    ->lockForUpdate()
                    ->first([
                        'item_id',
                        'branch_id',
                        'is_available',
                        'unavailable_reason',
                        'daily_consumed_qty',
                        'max_daily_qty',
                    ]);

                if ($availability) {
                    $currentConsumed = max(0, (int) $availability->daily_consumed_qty);
                    $newConsumed     = max(0, $currentConsumed - $delta);
                    $wasUnavailable  = ! (bool) $availability->is_available;
                    $shouldFlip      = $wasUnavailable
                        && $availability->max_daily_qty !== null
                        && $newConsumed < (int) $availability->max_daily_qty;

                    $update = ['daily_consumed_qty' => $newConsumed];

                    if ($shouldFlip) {
                        $update['is_available']       = true;
                        $update['unavailable_reason'] = null;
                        $update['unavailable_since']  = null;

                        $eventsToDispatch[] = [
                            'item_id'      => $itemId,
                            'branch_id'    => $branchId,
                            'is_available' => true,
                            'reason'       => 'released_after_cancel_or_refund',
                        ];
                    }

                    DB::table('item_branch_availability')
                        ->where('item_id', $itemId)
                        ->where('branch_id', $branchId)
                        ->update($update);
                }

                DB::table('order_items')
                    ->where('id', $orderItemId)
                    ->update([
                        'released_qty' => (int) $orderItem->released_qty + $delta,
                        'released_at'  => Carbon::now(),
                    ]);
            }

            if ($eventsToDispatch !== []) {
                DB::afterCommit(function () use ($eventsToDispatch): void {
                    foreach ($eventsToDispatch as $payload) {
                        event(ItemAvailabilityChanged::forBranch(
                            itemId: $payload['item_id'],
                            branchId: $payload['branch_id'],
                            isAvailable: $payload['is_available'],
                            reason: $payload['reason'],
                        ));
                    }
                });
            }
        });
    }
}
