<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Services\Stock\StockService;

class ReleaseStockOnOrderCanceled
{
    public function handle(OrderCanceled $event): void
    {
        app(StockService::class)->releaseForOrder($event->order, 'order_canceled');
    }
}
