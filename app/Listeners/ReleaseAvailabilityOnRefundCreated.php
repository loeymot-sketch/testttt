<?php

namespace App\Listeners;

use App\Events\RefundCreated;
use App\Services\Menu\AvailabilityService;

/**
 * [F-01 + NEW-05] Compensating release on refund.
 *
 *   - If `refundedItems` is empty → behaves like a full cancel (release all lines).
 *   - Otherwise → releases only the requested line-item quantities.
 *
 * Idempotent via {@see AvailabilityService::releaseForOrderItems}.
 */
class ReleaseAvailabilityOnRefundCreated
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    public function handle(RefundCreated $event): void
    {
        $order = $event->order;
        if (! method_exists($order, 'orderItems')) {
            return;
        }

        $order->loadMissing('orderItems');
        $orderItems = $order->orderItems;

        if ($event->refundedItems === []) {
            $lineItems = $orderItems->map(static function ($orderItem): array {
                return [
                    'order_item_id' => (int) $orderItem->id,
                    'item_id'       => (int) $orderItem->item_id,
                    'branch_id'     => (int) $orderItem->branch_id,
                    'qty'           => (int) $orderItem->quantity,
                ];
            })->all();

            if ($lineItems !== []) {
                $this->availabilityService->releaseForOrderItems($lineItems);
            }

            return;
        }

        $requestedByOrderItemId = collect($event->refundedItems)
            ->keyBy(static fn (array $item): int => (int) $item['order_item_id']);

        $lineItems = $orderItems
            ->filter(static fn ($orderItem): bool => $requestedByOrderItemId->has((int) $orderItem->id))
            ->map(static function ($orderItem) use ($requestedByOrderItemId): array {
                $requested = $requestedByOrderItemId->get((int) $orderItem->id);
                return [
                    'order_item_id' => (int) $orderItem->id,
                    'item_id'       => (int) $orderItem->item_id,
                    'branch_id'     => (int) $orderItem->branch_id,
                    'qty'           => (int) $requested['qty'],
                ];
            })
            ->values()
            ->all();

        if ($lineItems === []) {
            return;
        }

        $this->availabilityService->releaseForOrderItems($lineItems);
    }
}
