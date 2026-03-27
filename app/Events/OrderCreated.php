<?php

namespace App\Events;

use App\Contracts\BroadcastableOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * [BORNE-WINDOWS / PHASE-E] Broadcast event fired when a new order is created.
 * Enables real-time KDS/POS/OSS updates via Soketi WebSockets.
 *
 * Uses BroadcastableOrder interface so both Order (POS) and FrontendOrder (kiosk/web)
 * can be passed without a PHP type mismatch.
 *
 * ShouldBroadcastNow bypasses the queue (QUEUE_CONNECTION=sync safe).
 *
 * Channel: private-branch.{branch_id}
 * Requires: BROADCAST_DRIVER=pusher + Soketi running
 */
class OrderCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public BroadcastableOrder $order)
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
