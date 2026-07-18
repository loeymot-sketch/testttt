<?php

namespace App\Services\Stock;

use App\Events\StockLevelChanged;
use App\Events\ItemAvailabilityChanged;
use App\Exceptions\Stock\StockUnavailableException;
use App\Models\FrontendOrder;
use App\Models\Item;
use App\Models\ItemBranchAvailability;
use App\Models\ItemExtra;
use App\Models\ItemVariation;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockLevel;
use App\Models\StockMovement;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StockService
{
    private const AUTO_RUPTURE_REASON = 'stock_rupture';

    /**
     * [S5-01 2026-07-18] `'out_of_stock'` is the reason AvailabilityService writes
     * when the DAILY QUOTA (`max_daily_qty`) is reached — NOT a physical stock
     * rupture. It is owned exclusively by the quota machinery
     * (AvailabilityService::decrementForOrder / setMaxDailyQty / releaseForOrderItems
     * + ResetStaleDailyQuotaCommand). StockService must therefore NEVER treat it as
     * an auto stock rupture it may reactivate: when an item combines `max_daily_qty`
     * AND tracked `stock_levels`, the OrderCreated listener chain runs
     * DecrementItemAvailabilityOnOrder (86 quota → 'out_of_stock') THEN
     * DecrementStockOnOrderCreated → syncItemAvailabilityForStockLevel; if `on_hand`
     * is still > 0 and this reason were "auto", the item would be flipped back to
     * available IN THE SAME REQUEST (quota cap silently broken + phantom
     * 'stock_restocked' event). Kept as a named constant so the exclusion is
     * explicit, not an accidental omission.
     */
    private const DAILY_QUOTA_REASON = 'out_of_stock';

    private const AUTO_RESTOCK_REASON = 'stock_restocked';

    public function decrementForOrder(Model $order, ?string $idempotencySeed = null): void
    {
        $this->mutateForOrder($order, 'order_created', -1, $idempotencySeed);
    }

    /**
     * @param  array<int, array{order_item_id:int, qty:int}>  $refundedItems
     */
    public function releaseForOrder(Model $order, string $reason = 'order_canceled', array $refundedItems = []): void
    {
        $reason = $reason === 'refund' ? 'refund' : 'order_canceled';
        $this->mutateForOrder($order, $reason, 1, null, requireOriginalDecrement: true, refundedItems: $refundedItems);
    }

    private function mutateForOrder(
        Model $order,
        string $reason,
        int $direction,
        ?string $idempotencySeed = null,
        bool $requireOriginalDecrement = false,
        array $refundedItems = [],
    ): void {
        DB::transaction(function () use ($order, $reason, $direction, $idempotencySeed, $requireOriginalDecrement, $refundedItems): void {
            $this->mutateForOrderInTransaction($order, $reason, $direction, $idempotencySeed, $requireOriginalDecrement, $refundedItems);
        });
    }

    private function mutateForOrderInTransaction(
        Model $order,
        string $reason,
        int $direction,
        ?string $idempotencySeed = null,
        bool $requireOriginalDecrement = false,
        array $refundedItems = [],
    ): void {
        if (! $order instanceof Order && ! $order instanceof FrontendOrder) {
            return;
        }

        $branchId = (int) $order->branch_id;
        if ($branchId <= 0) {
            return;
        }

        if ($direction > 0) {
            $this->releaseForOrderInTransaction($order, $branchId, $reason, $refundedItems);
            return;
        }

        $requirements = $this->requirementsForOrder($order);
        if ($requirements->isEmpty()) {
            return;
        }

        $changedLevelIds = [];
        $availabilityEvents = [];
        $choiceAvailabilityBoundaryChanged = false;

        foreach ($requirements as $row) {
            $level = StockLevel::query()
                ->where('branch_id', $branchId)
                ->where('stockable_type', $row['stockable_type'])
                ->where('stockable_id', $row['stockable_id'])
                ->lockForUpdate()
                ->first();

            if (! $level) {
                continue;
            }

            $orderKey = $this->movementKey('order_created', $order, $row, $idempotencySeed);
            $movementKey = $direction < 0
                ? $orderKey
                : $this->movementKey($reason, $order, $row, null);

            if (StockMovement::query()->where('idempotency_key', $movementKey)->exists()) {
                continue;
            }

            if ($requireOriginalDecrement && ! StockMovement::query()->where('idempotency_key', $orderKey)->exists()) {
                continue;
            }

            $qty = (int) $row['quantity'];
            if ($direction < 0 && (int) $level->on_hand < $qty) {
                throw new StockUnavailableException("Stock insuffisant pour {$row['stockable_type']}#{$row['stockable_id']}.");
            }

            $beforeOnHand = (int) $level->on_hand;
            $delta = $direction * $qty;
            $level->forceFill(['on_hand' => (int) $level->on_hand + $delta])->save();

            StockMovement::query()->create([
                'stock_level_id' => $level->id,
                'branch_id' => $branchId,
                'delta' => $delta,
                'reason' => $reason,
                'reference_type' => $order::class,
                'reference_id' => $order->id,
                'idempotency_key' => $movementKey,
                'created_at' => now(),
            ]);

            $changedLevelIds[] = (int) $level->id;
            $availabilityEvent = $this->syncItemAvailabilityForStockLevel($level, $branchId);
            if ($availabilityEvent) {
                $availabilityEvents[] = $availabilityEvent;
            }
            if ($this->isChoiceBoundaryMutation($row, $beforeOnHand, (int) $level->on_hand)) {
                $choiceAvailabilityBoundaryChanged = true;
            }
        }

        foreach ($availabilityEvents as $event) {
            $this->dispatchAvailabilityChanged($event);
        }

        if ($changedLevelIds !== [] && $choiceAvailabilityBoundaryChanged) {
            StockLevelChanged::dispatch($branchId, array_values(array_unique($changedLevelIds)));
        }
    }

    private function dispatchAvailabilityChanged(ItemAvailabilityChanged $event): void
    {
        ItemAvailabilityChanged::dispatch(
            $event->itemId,
            $event->status,
            $event->price,
            $event->type,
            $event->branchId,
            $event->isAvailable,
            $event->reason,
        );
    }

    private function syncItemAvailabilityForStockLevel(StockLevel $level, int $branchId): ?ItemAvailabilityChanged
    {
        if ($level->stockable_type !== Item::class) {
            return null;
        }

        $itemId = (int) $level->stockable_id;
        if ($itemId <= 0) {
            return null;
        }

        $row = ItemBranchAvailability::query()
            ->where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();

        if ((int) $level->on_hand <= 0) {
            if ($row && ! (bool) $row->is_available && ! $this->isAutoStockRuptureReason($row->unavailable_reason)) {
                return null;
            }

            if (! $row) {
                $row = new ItemBranchAvailability([
                    'item_id' => $itemId,
                    'branch_id' => $branchId,
                    'daily_consumed_qty' => 0,
                    'daily_reset_at' => now()->toDateString(),
                ]);
            }

            if (! (bool) $row->is_available && $this->isAutoStockRuptureReason($row->unavailable_reason)) {
                return null;
            }

            $row->is_available = false;
            $row->unavailable_reason = self::AUTO_RUPTURE_REASON;
            $row->unavailable_since = now();
            $row->save();

            return ItemAvailabilityChanged::forBranch($itemId, $branchId, false, self::AUTO_RUPTURE_REASON);
        }

        if ($row && ! (bool) $row->is_available && $this->isAutoStockRuptureReason($row->unavailable_reason)) {
            $row->is_available = true;
            $row->unavailable_reason = null;
            $row->unavailable_since = null;
            $row->save();

            return ItemAvailabilityChanged::forBranch($itemId, $branchId, true, self::AUTO_RESTOCK_REASON);
        }

        return null;
    }

    /**
     * True only for a rupture StockService itself owns and may auto-reactivate on
     * restock. The daily-quota reason ({@see self::DAILY_QUOTA_REASON}) is
     * DELIBERATELY excluded — it belongs to AvailabilityService (see the constant's
     * docblock, finding S5-01). Excluding it makes StockService a no-op on
     * quota-86'd rows: it neither reactivates them on restock (bug fixed) nor
     * overwrites their reason when on_hand hits 0 (both branches return null
     * upstream), so the daily cap holds until the quota machinery clears it.
     */
    private function isAutoStockRuptureReason(?string $reason): bool
    {
        return $reason === self::AUTO_RUPTURE_REASON;
    }

    /**
     * @return Collection<int, array{stockable_type: class-string, stockable_id: int, quantity: int, line_uid: string}>
     */
    private function requirementsForOrder(Model $order): Collection
    {
        return $order->orderItems()
            ->get()
            ->flatMap(fn (OrderItem $item): array => $this->requirementsForOrderItem($item))
            ->groupBy(fn (array $row): string => $row['stockable_type'] . ':' . $row['stockable_id'] . ':' . $row['line_uid'])
            ->map(fn (Collection $rows): array => [
                'stockable_type' => $rows->first()['stockable_type'],
                'stockable_id' => $rows->first()['stockable_id'],
                'quantity' => $rows->sum('quantity'),
                'line_uid' => $rows->first()['line_uid'],
            ])
            ->values();
    }

    /**
     * @return array<int, array{stockable_type: class-string, stockable_id: int, quantity: int, line_uid: string}>
     */
    private function requirementsForOrderItem(OrderItem $orderItem, ?int $effectiveQuantity = null): array
    {
        $lineQuantity = max(1, (int) ($effectiveQuantity ?? $orderItem->quantity));
        $lineUid = 'line:' . (int) $orderItem->id;
        $requirements = [[
            'stockable_type' => Item::class,
            'stockable_id' => (int) $orderItem->item_id,
            'quantity' => $lineQuantity,
            'line_uid' => $lineUid . ':item:' . (int) $orderItem->item_id,
        ]];

        foreach ($this->decodeRows($orderItem->item_variations) as $variation) {
            $id = (int) ($variation['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $requirements[] = [
                'stockable_type' => ItemVariation::class,
                'stockable_id' => $id,
                'quantity' => $lineQuantity * max(1, (int) ($variation['quantity'] ?? 1)),
                'line_uid' => $lineUid . ':variation:' . $id,
            ];
        }

        foreach ($this->decodeRows($orderItem->item_extras) as $extra) {
            $id = (int) ($extra['id'] ?? 0);
            if ($id <= 0) {
                continue;
            }
            $requirements[] = [
                'stockable_type' => ItemExtra::class,
                'stockable_id' => $id,
                'quantity' => $lineQuantity * max(1, (int) ($extra['quantity'] ?? 1)),
                'line_uid' => $lineUid . ':extra:' . $id,
            ];
        }

        foreach ($this->decodeSnapshotAddons($orderItem->composition_snapshot) as $addon) {
            $addonItemId = (int) ($addon['addon_item_id'] ?? 0);
            if ($addonItemId <= 0) {
                continue;
            }

            $requirements[] = [
                'stockable_type' => Item::class,
                'stockable_id' => $addonItemId,
                'quantity' => $lineQuantity * max(1, (int) ($addon['quantity'] ?? 1)),
                'line_uid' => $lineUid . ':addon-item:' . $addonItemId,
            ];
        }

        return $requirements;
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeRows(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && trim($value) !== '') {
            $decoded = json_decode($value, true);
            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function decodeSnapshotAddons(mixed $value): array
    {
        $snapshot = $this->decodeRows($value);
        $addons = $snapshot['addons'] ?? [];
        return is_array($addons) ? $addons : [];
    }

    /**
     * @param array{stockable_type: class-string, stockable_id: int, quantity: int, line_uid: string} $row
     */
    private function movementKey(string $reason, Model $order, array $row, ?string $seed): string
    {
        $parts = [
            $reason,
            $order::class,
            (int) $order->id,
            $row['line_uid'],
            $row['stockable_type'],
            (int) $row['stockable_id'],
        ];
        if ($reason !== 'order_created' && $seed !== null && $seed !== '') {
            $parts[] = $seed;
        }

        $raw = implode('|', $parts);

        return 'stock:' . sha1($raw);
    }

    /**
     * @param  array<int, array{order_item_id:int, qty:int}>  $refundedItems
     */
    private function releaseForOrderInTransaction(Model $order, int $branchId, string $reason, array $refundedItems): void
    {
        $requestedByOrderItemId = collect($refundedItems)
            ->keyBy(static fn (array $item): int => (int) ($item['order_item_id'] ?? 0));

        // [BRAIN-SUPERVISOR 2026-07-15 / P1] withTrashed : depuis F-DESTROY-RELEASE-ATOMIC
        // (6ec645c85), OrderCanceled tire APRÈS le commit du destroy — les lignes sont déjà
        // soft-deleted et une requête fraîche les excluait → libération de stock silencieusement
        // no-op (stock physique jamais restauré). L'idempotence released_qty garde le
        // double-release ; le flux cancel (lignes vivantes) est inchangé.
        $orderItems = $order->orderItems()->withTrashed()->get(['id']);
        if ($orderItems->isEmpty()) {
            return;
        }

        $changedLevelIds = [];
        $availabilityEvents = [];
        $choiceAvailabilityBoundaryChanged = false;

        foreach ($orderItems as $line) {
            // withTrashed : même raison que le fetch ci-dessus (destroy post-commit).
            $orderItem = OrderItem::withTrashed()
                ->where('id', (int) $line->id)
                ->lockForUpdate()
                ->first();

            if (! $orderItem || (int) $orderItem->branch_id !== $branchId) {
                continue;
            }

            $requestedQty = $requestedByOrderItemId->isEmpty()
                ? (int) $orderItem->quantity
                : max(0, (int) (($requestedByOrderItemId->get((int) $orderItem->id)['qty'] ?? 0)));

            if ($requestedByOrderItemId->isNotEmpty() && ! $requestedByOrderItemId->has((int) $orderItem->id)) {
                continue;
            }

            $remainingQty = max(0, (int) $orderItem->quantity - (int) $orderItem->released_qty);
            $deltaLineQty = min($requestedQty, $remainingQty);
            if ($deltaLineQty <= 0) {
                continue;
            }

            $requirements = $this->requirementsForOrderItem($orderItem, $deltaLineQty);

            foreach ($requirements as $row) {
                $level = StockLevel::query()
                    ->where('branch_id', $branchId)
                    ->where('stockable_type', $row['stockable_type'])
                    ->where('stockable_id', $row['stockable_id'])
                    ->lockForUpdate()
                    ->first();

                if (! $level) {
                    continue;
                }

                $orderKey = $this->movementKey('order_created', $order, $row, null);
                if (! StockMovement::query()->where('idempotency_key', $orderKey)->exists()) {
                    continue;
                }

                $movementKey = $this->movementKey(
                    $reason,
                    $order,
                    $row,
                    'released:' . (int) $orderItem->released_qty . ':delta:' . $deltaLineQty
                );
                if (StockMovement::query()->where('idempotency_key', $movementKey)->exists()) {
                    continue;
                }

                $beforeOnHand = (int) $level->on_hand;
                $delta = (int) $row['quantity'];
                $level->forceFill(['on_hand' => (int) $level->on_hand + $delta])->save();

                StockMovement::query()->create([
                    'stock_level_id' => $level->id,
                    'branch_id' => $branchId,
                    'delta' => $delta,
                    'reason' => $reason,
                    'reference_type' => $order::class,
                    'reference_id' => $order->id,
                    'idempotency_key' => $movementKey,
                    'created_at' => now(),
                ]);

                $changedLevelIds[] = (int) $level->id;
                $availabilityEvent = $this->syncItemAvailabilityForStockLevel($level, $branchId);
                if ($availabilityEvent) {
                    $availabilityEvents[] = $availabilityEvent;
                }
                if ($this->isChoiceBoundaryMutation($row, $beforeOnHand, (int) $level->on_hand)) {
                    $choiceAvailabilityBoundaryChanged = true;
                }
            }
        }

        foreach ($availabilityEvents as $event) {
            $this->dispatchAvailabilityChanged($event);
        }

        if ($changedLevelIds !== [] && $choiceAvailabilityBoundaryChanged) {
            StockLevelChanged::dispatch($branchId, array_values(array_unique($changedLevelIds)));
        }
    }

    private function crossedAvailabilityBoundary(int $beforeOnHand, int $afterOnHand): bool
    {
        return ($beforeOnHand > 0 && $afterOnHand <= 0) || ($beforeOnHand <= 0 && $afterOnHand > 0);
    }

    /**
     * @param  array{stockable_type: class-string, stockable_id: int, quantity: int, line_uid: string}  $row
     */
    private function isChoiceBoundaryMutation(array $row, int $beforeOnHand, int $afterOnHand): bool
    {
        if (! $this->crossedAvailabilityBoundary($beforeOnHand, $afterOnHand)) {
            return false;
        }

        return $row['stockable_type'] !== Item::class
            || str_contains((string) $row['line_uid'], ':addon-item:');
    }
}
