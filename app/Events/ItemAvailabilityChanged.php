<?php

namespace App\Events;

use App\Models\Item;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Plain domain event fired when an item's status or price changes.
 *
 * The outbox pattern now persists and broadcasts the payload after commit,
 * replacing direct ShouldBroadcastNow dispatch from this event class.
 */
class ItemAvailabilityChanged
{
    use Dispatchable;

    public int    $itemId;
    public int    $status;
    public float  $price;
    public string $type;

    public function __construct(Item $item, string $type = 'status')
    {
        $this->itemId  = $item->id;
        $this->status  = (int) $item->status;
        $this->price   = (float) $item->price;
        $this->type    = $type;
    }
}
