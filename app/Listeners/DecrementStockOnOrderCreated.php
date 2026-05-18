<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Exceptions\Stock\StockUnavailableException;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Log;
use Throwable;

class DecrementStockOnOrderCreated
{
    public function handle(OrderCreated $event): void
    {
        try {
            app(StockService::class)->decrementForOrder($event->order);
        } catch (StockUnavailableException $e) {
            // Domain-expected exception — let it bubble up to the request stack
            // so the order creation transaction can be rolled back upstream.
            // (Pre-iter12 behavior preserved.)
            throw $e;
        } catch (Throwable $e) {
            // [iter12 P1 STOCK 2026-05-09] Don't let unexpected listener
            // failures silently leak stock. Log + re-throw so :
            //   - Sentry breadcrumb captures full stack
            //   - The order transaction (which spawned this event after-commit)
            //     stays consistent — a hard fail surfaces the orphan rather
            //     than letting on_hand drift unchecked.
            Log::error('DecrementStockOnOrderCreated failed', [
                'order_id'  => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            throw $e;
        }
    }
}
