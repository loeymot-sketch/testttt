<?php

namespace App\Events;

use App\Contracts\BroadcastableOrder;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * [BORNE-WINDOWS / PHASE-E] Broadcast event fired when an order status changes.
 * Enables real-time OSS/POS/KDS updates without polling.
 *
 * Uses BroadcastableOrder interface so both Order (POS) and FrontendOrder (kiosk/web)
 * can be passed without a PHP type mismatch.
 *
 * ShouldBroadcastNow bypasses the queue (QUEUE_CONNECTION=sync safe) and fires
 * the WebSocket push synchronously after the DB transaction commits.
 *
 * Channel: private-branch.{branch_id}
 */
class OrderStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public BroadcastableOrder $order,
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
            'token'        => $this->order->token ?? null,
        ];
    }
}
