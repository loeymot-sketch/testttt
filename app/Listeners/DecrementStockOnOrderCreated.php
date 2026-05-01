<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Services\Stock\StockService;

class DecrementStockOnOrderCreated
{
    public function handle(OrderCreated $event): void
    {
        app(StockService::class)->decrementForOrder($event->order);
    }
}
