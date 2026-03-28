<?php

namespace App\Events;

use App\Models\Item;
use App\Models\Branch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * [C3] Broadcast event fired when an item's status or price changes.
 * Allows kiosk displays to update their menu cache in real time without
 * waiting for the 5-minute TTL to expire.
 *
 * Items are global (no branch_id) so we broadcast on every active branch
 * channel — each kiosk listens on its own branch channel.
 *
 * Channel: private-branch.{branch_id} (one per active branch)
 * Payload: { item_id, status, price, type }
 *   type = 'status' | 'price' | 'full'
 *   'full' triggers a complete menu refetch on the kiosk.
 */
class ItemAvailabilityChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

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

    /**
     * Broadcast on all active branch channels so every kiosk receives the update.
     */
    public function broadcastOn(): array
    {
        $branchIds = Branch::where('status', \App\Enums\Status::ACTIVE)
            ->pluck('id')
            ->toArray();

        return array_map(
            fn ($id) => new PrivateChannel('branch.' . $id),
            $branchIds
        );
    }

    public function broadcastAs(): string
    {
        return 'ItemAvailabilityChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'item_id' => $this->itemId,
            'status'  => $this->status,
            'price'   => $this->price,
            'type'    => $this->type,
        ];
    }
}
