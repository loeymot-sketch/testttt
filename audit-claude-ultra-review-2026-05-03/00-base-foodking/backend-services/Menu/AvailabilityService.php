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
     * Update the daily quota cap and re-evaluate is_available immediately.
     *
     * Symmetrical to toggle() but driven by a quota change instead of a manual
     * rupture. Plan task 2.5 — eliminates the "wait until next order" delay
     * that was visible in admin UX.
     *
     * Idempotent: setting the same max twice does not emit a duplicate event.
     *
     * @param  int|null  $maxDailyQty  null = unlimited (always available from quota perspective)
     */
    public function setMaxDailyQty(int $itemId, int $branchId, ?int $maxDailyQty): ItemBranchAvailability
    {
        return DB::transaction(function () use ($itemId, $branchId, $maxDailyQty): ItemBranchAvailability {
            $row = ItemBranchAvailability::query()
                ->where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->lockForUpdate()
                ->first();

            if (! $row) {
                $row = new ItemBranchAvailability([
                    'item_id' => $itemId,
                    'branch_id' => $branchId,
                    'is_available' => true,
                    'daily_consumed_qty' => 0,
                    'daily_reset_at' => Carbon::today()->toDateString(),
                    'max_daily_qty' => $maxDailyQty,
                ]);
                $row->save();

                return $row;
            }

            $previousMax = $row->max_daily_qty === null ? null : (int) $row->max_daily_qty;
            $newMax = $maxDailyQty;
            $consumed = (int) $row->daily_consumed_qty;
            $wasAvailable = (bool) $row->is_available;
            $previousReason = $row->unavailable_reason;

            if ($previousMax === $newMax) {
                return $row;
            }

            $row->max_daily_qty = $newMax;

            $shouldBeAvailableFromQuota = ($newMax === null) || ($consumed < $newMax);

            // Auto-86: cap lowered below current consumption
            if ($wasAvailable && ! $shouldBeAvailableFromQuota) {
                $row->is_available = false;
                $row->unavailable_reason = 'out_of_stock';
                $row->unavailable_since = now();
                $row->save();
                $this->dispatchEvent($itemId, $branchId, false, 'out_of_stock');

                return $row;
            }

            // Auto-restore: cap raised above current consumption AND was unavailable due to quota
            if (! $wasAvailable && $previousReason === 'out_of_stock' && $shouldBeAvailableFromQuota) {
                $row->is_available = true;
                $row->unavailable_reason = null;
                $row->unavailable_since = null;
                $row->save();
                $this->dispatchEvent($itemId, $branchId, true, null);

                return $row;
            }

            // Quota changed but no flip needed (e.g. raised cap while still available, or
            // unavailable for a different reason like manual rupture).
            $row->save();

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
     *
     * Uses the atomic conditional UPDATE pattern selected in
     * reports/audit/M2_1_9_INDUSTRY_COMPARATIVE_ANALYSIS_2026-05-02.md:
     * one capped counter update, then one CAS-style availability flip so
     * concurrent decrements emit the 86 event exactly once.
     */
    public function decrementForOrder(Model $order): void
    {
        $branchId = (int) $order->branch_id;
        $today = Carbon::today()->toDateString();

        foreach ($order->orderItems as $line) {
            $qty = (int) $line->quantity;

            DB::table('item_branch_availability')
                ->where('item_id', $line->item_id)
                ->where('branch_id', $branchId)
                ->whereDate('daily_reset_at', '<', $today)
                ->update([
                    'daily_consumed_qty' => 0,
                    'daily_reset_at' => $today,
                ]);

            $rows = DB::table('item_branch_availability')
                ->where('item_id', $line->item_id)
                ->where('branch_id', $branchId)
                ->whereNotNull('max_daily_qty')
                ->update([
                    'daily_consumed_qty' => DB::raw(
                        "CASE WHEN daily_consumed_qty + {$qty} > max_daily_qty "
                        . "THEN max_daily_qty ELSE daily_consumed_qty + {$qty} END"
                    ),
                    'updated_at' => now(),
                ]);

            if ($rows === 0) {
                continue;
            }

            $flipRows = DB::table('item_branch_availability')
                ->where('item_id', $line->item_id)
                ->where('branch_id', $branchId)
                ->where('is_available', true)
                ->whereRaw('daily_consumed_qty >= max_daily_qty')
                ->update([
                    'is_available' => false,
                    'unavailable_reason' => 'out_of_stock',
                    'unavailable_since' => now(),
                    'updated_at' => now(),
                ]);

            if ($flipRows === 1) {
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
        DB::afterCommit(function () use ($itemId, $branchId, $available, $reason): void {
            event(ItemAvailabilityChanged::forBranch(
                itemId: $itemId,
                branchId: $branchId,
                isAvailable: $available,
                reason: $reason
            ));
        });
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
