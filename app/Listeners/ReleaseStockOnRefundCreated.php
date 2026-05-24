<?php

namespace App\Listeners;

use App\Events\RefundCreated;
use App\Services\Stock\StockService;
use Illuminate\Support\Facades\Log;
use Throwable;

class ReleaseStockOnRefundCreated
{
    public function handle(RefundCreated $event): void
    {
        try {
            app(StockService::class)->releaseForOrder($event->order, 'refund', $event->refundedItems);
        } catch (Throwable $e) {
            // [GOAL-I2-HEAL-01 2026-05-24] Phase I.4 AMBER I4-CONCERN-02 sibling.
            //
            // Pre-heal : ZERO try/catch. RefundCreated uses DispatchableAfterCommit and
            // EventServiceProvider:179-186 lists Stock BEFORE Availability — a Throwable
            // here halts Laravel's sync dispatcher and skips ReleaseAvailabilityOnRefundCreated
            // → divergent stock vs availability ledgers on refund failure.
            //
            // Mirror the OrderCanceled cascade hardening : Log::error + return,
            // sibling availability release listener now guaranteed to fire.
            Log::error('ReleaseStockOnRefundCreated isolated (cascade continues)', [
                'order_id'  => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error'     => $e->getMessage(),
                'exception' => $e,
            ]);
        }
    }
}
