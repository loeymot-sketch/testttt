<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Services\Menu\AvailabilityService;

class DecrementItemAvailabilityOnOrder
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    public function handle(OrderCreated $event): void
    {
        $order = $event->order ?? null;
        if ($order === null || !method_exists($order, 'orderItems')) {
            return;
        }

        $order->loadMissing('orderItems');
        $this->availabilityService->decrementForOrder($order);
    }
}
