<?php

namespace App\Events;

use App\Models\Order;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * [BORNE-WINDOWS / PHASE-E] Broadcast event fired when an order status changes.
 * Enables real-time OSS/POS updates without polling.
 *
 * Channel: private-branch.{branch_id}
 */
class OrderStatusChanged implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Order $order,
        public int $oldStatus,
        public int $newStatus
    ) {
    }

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('branch.' . $this->order->branch_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'OrderStatusChanged';
    }

    public function broadcastWith(): array
    {
        return [
            'order_id'     => $this->order->id,
            'queue_number' => $this->order->queue_number,
            'old_status'   => $this->oldStatus,
            'new_status'   => $this->newStatus,
            'token'        => $this->order->token,
        ];
    }
}
