<?php

namespace App\Events;

use App\Contracts\BroadcastableOrder;
use App\Events\Concerns\DispatchableAfterCommit;

/**
 * Plain domain event fired when an order status changes.
 *
 * Uses {@see DispatchableAfterCommit} (gate C9 — KI-001) so the event is
 * deferred until the surrounding DB::transaction() commits, and dropped
 * entirely on rollback.
 */
class OrderStatusChanged
{
    use DispatchableAfterCommit;

    public function __construct(
        public BroadcastableOrder $order,
        public int $oldStatus,
        public int $newStatus
    ) {
    }
}
