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
            // [GOAL-I2-HEAL-01 2026-05-24] Phase I.4 RED I4-CONCERN-01 — drop re-throw.
            //
            // Stale iter13 reasoning (Cohérence DecrementStockOnOrderCreated +
            // OrderService::changeStatus try/catch) was load-bearing-wrong : OrderCanceled
            // uses DispatchableAfterCommit, so listeners run AFTER the outer transaction
            // commits. Re-throwing :
            //   - rolls back nothing (commit done),
            //   - halts Laravel's sync dispatcher (vendor/.../Events/Dispatcher.php:233-269)
            //     → next listener ReleaseAvailabilityOnOrderCanceled NEVER runs,
            //   - divergent stock vs availability ledgers on failure.
            //
            // Mirror DecrementStockOnOrderCreated (post-WG-2) : isolate to Log::error +
            // return. Sibling availability release listener is now guaranteed to fire.
            // StockDecrementFailedEvent is NOT used here — its PHPDoc nails it to the
            // OrderCreated cascade; using it for OrderCanceled would conflate alerts.
            // V1.0.2 backlog : introduce StockReleaseFailedEvent typed hook if ops
            // alerting needs to discriminate release-failures from decrement-failures.
            Log::error('ReleaseStockOnOrderCanceled isolated (cascade continues)', [
                'order_id'  => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
