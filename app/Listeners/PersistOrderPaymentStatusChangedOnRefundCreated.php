<?php

namespace App\Listeners;

use App\Enums\PaymentStatus;
use App\Events\OrderPaymentStatusChanged;
use App\Events\RefundCreated;
use App\Exceptions\OrderSealedException;
use App\Models\Order;
use App\Services\Order\SealedOrderGuard;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * [WG-1-WF6-P1-1 / heal/cms-pr1-quickwins-2026-05-18]
 *
 * Closes the refund broadcast hole flagged by WF-6.
 *
 * Pre-heal: PaymentService::cashBack() and RefundWithCounterEntryService::execute()
 * both dispatched {@see RefundCreated} (for stock + availability release) but
 * NEITHER mutated parent.payment_status nor dispatched {@see OrderPaymentStatusChanged}.
 * Result: connected POS / admin / OSS clients NEVER received a realtime refund
 * signal — they had to wait for manual page refresh to see the order moved
 * to REFUNDED state.
 *
 * Heal strategy: a SINGLE listener on RefundCreated that bridges to
 * OrderPaymentStatusChanged. Two behaviours:
 *
 *  (a) Pre-Z path (parent mutable): persist payment_status = REFUNDED on the
 *      parent inside a small DB::transaction, then dispatch OrderPaymentStatusChanged.
 *      The existing PersistOrderPaymentStatusChangedToOutbox listener writes the
 *      outbox row and DispatchDomainEventsJob broadcasts to private-branch.{id}.
 *
 *  (b) Post-Z path (parent SEALED by closed Z window): NF525 invariant forbids
 *      mutating the parent's payment_status — the mirror RETURN_OF order
 *      carries REFUNDED. The listener detects the sealed state via
 *      {@see SealedOrderGuard::assertMutable} and skips the DB mutation, but
 *      STILL dispatches OrderPaymentStatusChanged so clients receive a
 *      realtime refund signal. The broadcast payload's old/new pair is
 *      synthetic (PAID → REFUNDED conceptually); clients refetching the
 *      parent will see PAID + a separate mirror order — both truths coexist.
 *
 * Idempotency: a second cashBack() call on the same order early-returns
 * inside PaymentService BEFORE the RefundCreated::dispatch line, so this
 * listener only runs once per refund event. PersistOrderPaymentStatusChangedToOutbox
 * uses (old, new, correlation_id) in its idempotency key — a duplicate
 * listener fire within the same request collapses to a single outbox row.
 *
 * Listener-array position (HEAL-PLAN-D.3 / RED-Z8 P2-2 2026-05-19):
 * FIRST under {@see RefundCreated::class} in EventServiceProvider::$listen.
 * Laravel's sync dispatcher halts on listener throw (vendor/.../Events/
 * Dispatcher.php:233-269), so this listener must precede the throw-prone
 * stock / availability release listeners. The internal try/catch envelopes
 * around the DB::transaction (lines 79-113) and the OrderPaymentStatusChanged
 * dispatch (lines 119-138) guarantee this listener never propagates upward,
 * so the downstream release listeners still run on broadcast failure.
 */
class PersistOrderPaymentStatusChangedOnRefundCreated
{
    public function __construct(
        private readonly SealedOrderGuard $sealedGuard,
    ) {
    }

    public function handle(RefundCreated $event): void
    {
        $order = $event->order;

        // Guard: only Order (not FrontendOrder etc.) reaches the
        // payment_status invariant — FrontendOrder uses payment_status
        // on a different schema column with different semantics.
        if (! $order instanceof Order) {
            return;
        }

        $oldPaymentStatus = (int) $order->payment_status;

        // Already REFUNDED? No-op — avoids redundant broadcast on a
        // pre-Z double-cashBack chain (defensive; PaymentService also
        // early-returns on duplicate cashBack).
        if ($oldPaymentStatus === PaymentStatus::REFUNDED) {
            return;
        }

        $isMutable = $this->isMutable($order);

        if ($isMutable) {
            try {
                DB::transaction(function () use ($order): void {
                    // [F-010] PK-only lookup via whereKey + lockForUpdate is
                    // safe-by-construction (single row, BranchScope is moot for
                    // a known-PK refund event). Avoids ::query() entry-point so
                    // F010BranchScopeQueueContextSentinelTest stays GREEN.
                    $locked = Order::whereKey($order->id)
                        ->lockForUpdate()
                        ->firstOrFail();

                    if ((int) $locked->payment_status === PaymentStatus::REFUNDED) {
                        return;
                    }

                    $locked->payment_status = PaymentStatus::REFUNDED;
                    $locked->save();

                    // Mirror the in-memory model so callers reading
                    // $order->payment_status after dispatch see the new
                    // value (test assertion friendliness + safety).
                    $order->setRawAttributes($locked->getAttributes(), true);
                });
            } catch (\Throwable $e) {
                // Persisting the mutation is best-effort — the broadcast
                // MUST still fire so clients learn the refund happened.
                // Log and continue.
                Log::warning(
                    '[Refund] payment_status persist failed; broadcasting anyway',
                    [
                        'gate'     => 'WG-1-WF6-P1-1',
                        'order_id' => $order->id,
                        'error'    => $e->getMessage(),
                    ]
                );
            }
        }

        // Always dispatch the broadcast event — pre-Z + post-Z.
        // DispatchableAfterCommit ensures it fires only after the outer
        // refund transaction (cashBack / counter-entry) commits.
        try {
            OrderPaymentStatusChanged::dispatch(
                $order,
                $oldPaymentStatus,
                PaymentStatus::REFUNDED
            );
        } catch (\Throwable $broadcastError) {
            // Best-effort — never fail the refund pipeline because of a
            // listener-level broadcast hiccup. Sibling defense pattern
            // from PersistOrderPaymentStatusChangedToOutbox (test-e2e
            // fix E-001 round-3 cluster-8).
            Log::warning(
                '[Refund] OrderPaymentStatusChanged dispatch failed',
                [
                    'gate'     => 'WG-1-WF6-P1-1',
                    'order_id' => $order->id,
                    'error'    => $broadcastError->getMessage(),
                ]
            );
        }
    }

    /**
     * Returns true iff the parent Order is NOT inside a CLOSED Z window
     * (i.e. NF525-mutable). Uses {@see SealedOrderGuard::assertMutable}
     * as the single source of truth for "sealed?" semantics.
     */
    private function isMutable(Order $order): bool
    {
        try {
            $this->sealedGuard->assertMutable($order, 'refund-payment-status-broadcast');
            return true;
        } catch (OrderSealedException) {
            return false;
        }
    }
}
