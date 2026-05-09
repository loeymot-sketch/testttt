<?php

namespace App\Listeners;

use App\Events\OrderCanceled;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReleaseStockOnOrderCanceled
{
    public function handle(OrderCanceled $event): void
    {
        try {
            app(StockService::class)->releaseForOrder($event->order, 'order_canceled');
        } catch (Throwable $e) {
            // [iter13 P1 STOCK 2026-05-09] Cohérence avec DecrementStockOnOrderCreated.
            // Release est compensating (idempotent via released_qty ledger), mais
            // on log explicitement pour Sentry breadcrumb si ça throw — et on
            // re-throw pour que le caller (OrderService::changeStatus self-cancel
            // déjà en try/catch ligne 1547) garde la trace + propage au retry queue.
            Log::error('ReleaseStockOnOrderCanceled failed', [
                'order_id'  => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
