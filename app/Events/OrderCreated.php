<?php

namespace App\Events;

use App\Contracts\BroadcastableOrder;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Plain domain event fired when a new order is created.
 *
 * The outbox pattern now persists and broadcasts the payload after commit,
 * replacing direct ShouldBroadcastNow dispatch from this event class.
 */
class OrderCreated
{
    use Dispatchable;

    public function __construct(public BroadcastableOrder $order)
    {
    }
}
