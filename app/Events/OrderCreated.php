<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * [BORNE-WINDOWS / PHASE-E] Broadcast event fired when a new order is created.
 * Enables real-time KDS/POS/OSS updates via Soketi WebSockets.
 *
 * Channel: private-branch.{branch_id}
 * Requires: BROADCAST_DRIVER=pusher + Soketi running
 */
class OrderCreated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Order $order)
    {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.' . $this->order->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'OrderCreated';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id'      => $this->order->id,
            'queue_number'  => $this->order->queue_number,
            'status'        => $this->order->status,
            'order_type'    => $this->order->order_type,
            'total'         => $this->order->total,
            'created_at'    => $this->order->created_at?->toISOString(),
        ];
    }
}
