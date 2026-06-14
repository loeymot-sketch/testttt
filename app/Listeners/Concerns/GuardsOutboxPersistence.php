<?php

namespace App\Listeners\Concerns;

use Illuminate\Support\Facades\Log;

/**
 * [REACT-A heal 2026-06-14 — ultra-review systemic-cascade fix].
 *
 * The Persist*ToOutbox listeners run SYNCHRONOUSLY and are registered FIRST in their
 * event's listener array. OrderCreated / OrderStatusChanged / OrderPaidAtCounter all
 * use {@see \App\Events\Concerns\DispatchableAfterCommit}, so their listeners fire
 * AFTER the order/payment row is committed. If the synchronous outbox persistence
 * (a DomainEvent::firstOrCreate) throws, Laravel's sync event dispatcher aborts the
 * ENTIRE remaining cascade — skipping the business-critical listeners that follow:
 *   - OrderCreated      → DecrementItemAvailabilityOnOrder + DecrementStockOnOrderCreated  ⇒ OVERSELL
 *   - OrderStatusChanged→ AwardLoyaltyPointsOnDelivery (the sole loyalty-award path)        ⇒ LOST POINTS
 *   - OrderPaidAtCounter→ receipt-print / downstream side effects                           ⇒ POS 500 on a PAID sale
 *
 * The outbox row is a BEST-EFFORT realtime projection: clients reconcile on the next
 * poll and the `outbox:retry-failed` cron re-emits persisted rows. A persistence hiccup
 * must therefore NEVER halt the business cascade. Swallow + alert (the inner afterCommit
 * broadcast dispatch is already separately guarded).
 */
trait GuardsOutboxPersistence
{
    protected function runOutboxPersistenceGuarded(string $listener, \Closure $project): void
    {
        try {
            $project();
        } catch (\Throwable $e) {
            // Non-blocking: log at error tier (alertable) but DO NOT rethrow — the
            // downstream stock/loyalty/receipt listeners MUST still run.
            Log::error('[Outbox] synchronous projection swallowed (non-blocking — downstream cascade preserved)', [
                'gate'     => 'REACT-A-2026-06-14',
                'listener' => $listener,
                'error'    => $e->getMessage(),
            ]);
        }
    }
}
