<?php

namespace App\Events;

use App\Contracts\BroadcastableOrder;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Plain domain event fired when an order status changes.
 *
 * The outbox pattern now persists and broadcasts the payload after commit,
 * replacing direct ShouldBroadcastNow dispatch from this event class.
 */
class OrderStatusChanged
{
    use Dispatchable;

    public function __construct(
        public BroadcastableOrder $order,
        public int $oldStatus,
        public int $newStatus
    ) {
    }
}
