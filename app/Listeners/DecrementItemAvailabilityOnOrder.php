<?php

namespace App\Listeners;

use App\Events\OrderCreated;
use App\Events\StockDecrementFailedEvent;
use App\Services\Menu\AvailabilityService;
use Illuminate\Support\Facades\Log;
use Throwable;

class DecrementItemAvailabilityOnOrder
{
    public function __construct(
        private readonly AvailabilityService $availabilityService,
    ) {
    }

    /**
     * [WG-2 WF-4 + PK1-ARCH-01 — 2026-05-19] Unified failure-isolation policy.
     *
     * Pre-WG-2 this listener had ZERO try/catch — any Throwable raised by
     * AvailabilityService::decrementForOrder() (FK violation, unexpected
     * relation state, downstream SQL crash) propagated up the event dispatcher
     * and silently skipped the remaining cascade : DecrementStockOnOrderCreated
     * + SendFcmOnOrderCreated. PersistOrderCreatedToOutbox was protected only
     * by EventServiceProvider registration order (first-in, before this
     * listener) — coincidence, not contract.
     *
     * Post-WG-2 : Throwable is caught, logged, and a structured
     * StockDecrementFailedEvent is dispatched for ops. Sibling listeners
     * continue regardless of registration order.
     */
    public function handle(OrderCreated $event): void
    {
        $order = $event->order ?? null;
        if ($order === null || !method_exists($order, 'orderItems')) {
            return;
        }

        try {
            $order->loadMissing('orderItems');
            $this->availabilityService->decrementForOrder($order);
        } catch (Throwable $e) {
            // [WG-2] isolate to log+event, sibling listeners continue.
            Log::error('DecrementItemAvailabilityOnOrder isolated', [
                'order_id'  => $order->id ?? null,
                'branch_id' => $order->branch_id ?? null,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);

            try {
                event(new StockDecrementFailedEvent(
                    branchId:     (int) ($order->branch_id ?? 0),
                    orderId:      (int) ($order->id ?? 0),
                    listener:     self::class,
                    errorMessage: $e->getMessage(),
                    failedAt:     new \DateTimeImmutable(),
                ));
            } catch (Throwable $eventException) {
                Log::warning('StockDecrementFailedEvent dispatch absorbed', [
                    'order_id' => $order->id ?? null,
                    'error'    => $eventException->getMessage(),
                ]);
            }
        }
    }
}
