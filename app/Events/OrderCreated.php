<?php

namespace App\Events;

use App\Contracts\BroadcastableOrder;
use App\Events\Concerns\DispatchableAfterCommit;

/**
 * Plain domain event fired when a new order is created.
 *
 * The outbox pattern now persists and broadcasts the payload after commit,
 * replacing direct ShouldBroadcastNow dispatch from this event class.
 *
 * Uses {@see DispatchableAfterCommit} (gate C9 — KI-001) so the event is
 * deferred until the surrounding DB::transaction() commits, and dropped
 * entirely on rollback. Guarantees KDS / Kiosk / POS sync consumers never
 * observe orders that did not actually persist.
 */
class OrderCreated
{
    use DispatchableAfterCommit;

    public function __construct(public BroadcastableOrder $order)
    {
    }
}
