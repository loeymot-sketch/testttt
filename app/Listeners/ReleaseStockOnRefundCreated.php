<?php

namespace App\Listeners;

use App\Events\RefundCreated;
use App\Services\Stock\StockService;

class ReleaseStockOnRefundCreated
{
    public function handle(RefundCreated $event): void
    {
        app(StockService::class)->releaseForOrder($event->order, 'refund', $event->refundedItems);
    }
}
