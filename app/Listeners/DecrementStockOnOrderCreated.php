<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Events\StockDecrementFailedEvent;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Log;
use Throwable;

class DecrementStockOnOrderCreated
{
    /**
     * [WG-2 WF-4 + PK1-ARCH-01 — 2026-05-19] Unified failure-isolation policy.
     *
     * Pre-WG-2 the listener re-threw on Throwable (and on the domain-typed
     * StockUnavailableException) with a stale "let the transaction roll back
     * upstream" comment. That rationale was load-bearing-wrong : `OrderCreated`
     * uses {@see \App\Events\Concerns\DispatchableAfterCommit}, so this listener
     * runs AFTER the outer transaction commits. Re-throwing :
     *   - rolls back nothing (commit done),
     *   - skips sibling listeners — notably `PersistOrderCreatedToOutbox`
     *     which is the KDS/Kiosk/POS sync SSOT,
     *   - surfaces a 500 to the POS HTTP client for an order that EXISTS in
     *     DB → cashier confusion + dup-create attempt.
     *
     * Post-WG-2 : isolate to log + dispatch StockDecrementFailedEvent (no-op
     * by default, structured ops hook) + return. The Outbox SSOT row + the
     * other 3 sibling listeners continue. Stock drift is observable via the
     * dispatched event and the structured log.
     */
    public function handle(OrderCreated $event): void
    {
        try {
            app(StockService::class)->decrementForOrder($event->order);
        } catch (Throwable $e) {
            // [WG-2] isolate to log+event, sibling listeners continue.
            Log::error('DecrementStockOnOrderCreated isolated', [
                'order_id'  => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            try {
                event(new StockDecrementFailedEvent(
                    branchId:     (int) ($event->order->branch_id ?? 0),
                    orderId:      (int) ($event->order->id ?? 0),
                    listener:     self::class,
                    errorMessage: $e->getMessage(),
                    failedAt:     new \DateTimeImmutable(),
                ));
            } catch (Throwable $eventException) {
                // Defense-in-depth : even the observability event must not
                // re-break the cascade. Log + absorb.
                Log::warning('StockDecrementFailedEvent dispatch absorbed', [
                    'order_id' => $event->order->id ?? null,
                    'error'    => $eventException->getMessage(),
                ]);
            }
        }
    }
}
