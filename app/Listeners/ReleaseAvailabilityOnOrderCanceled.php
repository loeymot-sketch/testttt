<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Services\Menu\AvailabilityService;

/**
 * [F-01] Compensating release of branch-scoped stock counters when an order
 * is canceled. Idempotent via the {@see AvailabilityService::releaseForOrderItems}
 * `released_qty` ledger.
 */
class ReleaseAvailabilityOnOrderCanceled
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    public function handle(OrderCanceled $event): void
    {
        $order = $event->order;
        if (! method_exists($order, 'orderItems')) {
            return;
        }

        $order->loadMissing('orderItems');

        $lineItems = $order->orderItems->map(static function ($orderItem): array {
            return [
                'order_item_id' => (int) $orderItem->id,
                'item_id'       => (int) $orderItem->item_id,
                'branch_id'     => (int) $orderItem->branch_id,
                'qty'           => (int) $orderItem->quantity,
            ];
        })->all();

        if ($lineItems === []) {
            return;
        }

        $this->availabilityService->releaseForOrderItems($lineItems);
    }
}
