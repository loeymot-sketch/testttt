<?php

namespace App\Jobs;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\PosPaymentMethod;
use App\Events\OrderCanceled;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Models\FrontendOrder;
use App\Models\Scopes\BranchScope;
use Illuminate\Support\Facades\DB;

class CleanupStalePendingKioskOrders
{
    public function handle(): void
    {
        // [TRAP-2 2026-06-04] Config-driven TTL. Walk-away kiosk orders sit in
        // the cashier collect queue + KDS until purged; a few hours is the
        // safe envelope so a customer who genuinely takes their time queuing
        // is never auto-cancelled mid-service. Override via
        // KIOSK_STALE_COLLECT_TTL_MINUTES. Default 180 min (3 h).
        $ttlMinutes = (int) config('kiosk.stale_collect_ttl_minutes', 180);
        $staleThreshold = now()->subMinutes($ttlMinutes);

        /*
         * [W9-AUDIT FIX-5] Console job runs without Auth context: BranchScope is bypassed
         * naturally, but `withoutGlobalScopes()` would ALSO drop SoftDeletingScope, risking
         * the auto-rejection of orders that were already soft-deleted (e.g. by a manual
         * admin action). Drop only BranchScope (multi-tenant by design) and keep the
         * soft-delete guard intact.
         */
        // [TRAP-2 2026-06-04] CORRECTED ABANDONED SET. The real walk-away kiosk
        // order auto-accepts to `status=ACCEPT` (Plan-B cash counter-deferred,
        // FrontendOrderService:208,590-593), NOT `status=PENDING`. The previous
        // gate filtered `status=PENDING` only, so it matched ZERO real kiosk
        // orders — DEAD CODE despite the GAP-C1-002 comment. Two legal entry
        // statuses are now targeted:
        //   - PENDING (kiosk card/TR never auto-accepted, UNPAID)   → REJECTED
        //   - ACCEPT  (kiosk cash auto-accepted, PENDING_COUNTER)   → CANCELED
        // OrderStateMachine.allows(): PENDING→REJECTED and ACCEPT→CANCELED are
        // both legal (OrderStateMachine.php:38,52) — no frozen-zone edit needed.
        //
        // NF525-safety: only rows with NO fiscal_sequence_no are touched. A
        // collected order has been sealed (payment_status=PAID + a fiscal
        // sequence allocated at PaymentService:321-322) and is excluded by both
        // the payment_status filter AND the explicit whereNull below — a
        // fiscalized order is NEVER mutated, so the NF525 chain is untouched
        // (no sequence = no chain impact).
        FrontendOrder::withoutGlobalScope(BranchScope::class)
            ->whereNull('deleted_at')
            ->whereNull('fiscal_sequence_no')
            ->whereIn('status', [OrderStatus::PENDING, OrderStatus::ACCEPT])
            ->whereIn('payment_status', [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER])
            ->where('source_surface', 'kiosk')
            ->whereIn('order_type', [\App\Enums\OrderType::KIOSK, \App\Enums\OrderType::TAKEAWAY])
            ->where(function ($query) use ($staleThreshold): void {
                $query->where('created_at', '<', $staleThreshold)
                    ->orWhere('order_datetime', '<', $staleThreshold);
            })
            ->orderBy('id')
            ->get()
            ->each(function (FrontendOrder $order): void {
                $oldStatus = null;
                $newStatus = null;
                $cleaned = false;

                DB::transaction(function () use ($order, &$oldStatus, &$newStatus, &$cleaned): void {
                    $locked = FrontendOrder::withoutGlobalScope(BranchScope::class)
                        ->whereKey($order->id)
                        ->lockForUpdate()
                        ->first();

                    // [TRAP-2] Inner re-guard must mirror the outer query, else
                    // every fetched ACCEPT row would be skipped here and the job
                    // stays dead. We re-check status, payment_status AND
                    // fiscal_sequence_no under the row lock so we lose the race
                    // gracefully against a concurrent counter-collect
                    // (PaymentService::confirmCounterPayment, which holds its own
                    // lockForUpdate and seals fiscal_sequence_no + PAID).
                    if (!$locked
                        || $locked->fiscal_sequence_no !== null
                        || !in_array((int) $locked->status, [OrderStatus::PENDING, OrderStatus::ACCEPT], true)
                        || !in_array((int) $locked->payment_status, [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER], true)) {
                        return;
                    }

                    $oldStatus = (int) $locked->status;

                    // From-status-aware terminal state, both legal per
                    // OrderStateMachine.allows() with no frozen-zone edit:
                    //   PENDING → REJECTED (legacy kiosk card/TR path, tested)
                    //   ACCEPT  → CANCELED (real walk-away cash, ACCEPT→REJECTED
                    //                       is ILLEGAL, ACCEPT→CANCELED is legal)
                    $newStatus = $oldStatus === OrderStatus::ACCEPT
                        ? OrderStatus::CANCELED
                        : OrderStatus::REJECTED;

                    OrderStateMachine::apply(
                        $locked,
                        $newStatus,
                        null,
                        $newStatus === OrderStatus::CANCELED
                            ? 'Auto-canceled abandoned counter-pending kiosk order after ' . (int) config('kiosk.stale_collect_ttl_minutes', 180) . ' minutes.'
                            : 'Auto-rejected stale pending kiosk order after ' . (int) config('kiosk.stale_collect_ttl_minutes', 180) . ' minutes.'
                    );

                    // [TRAP-2] Break the counter-deferred marker so a LATE
                    // PaymentService::confirmCounterPayment can never fiscalize +
                    // PAY a row we just canceled. assertCounterDeferredOrder
                    // (PaymentService:700-718) requires
                    // pos_payment_method===COUNTER_DEFERRED; nulling it makes the
                    // collect path throw 422 "not a pending counter payment".
                    // payment_status is left as-is (no honest CANCELED value
                    // exists; REFUNDED would be a lie — no money moved). The
                    // CANCELED order status alone removes it from KDS
                    // (KitchenReleaseRule::visibleStatuses() = ACCEPT/PREPARING/
                    // PREPARED only) and the cashier collect queue.
                    if ((int) ($locked->pos_payment_method ?? 0) === PosPaymentMethod::COUNTER_DEFERRED) {
                        $locked->pos_payment_method = null;
                        $locked->save();
                    }

                    $locked->refresh();
                    $order->setRawAttributes($locked->getAttributes(), true);
                    $cleaned = true;
                });

                if (!$cleaned || $oldStatus === null || $newStatus === null) {
                    return;
                }

                SendOrderMail::dispatch(['order_id' => $order->id, 'status' => $newStatus]);
                SendOrderSms::dispatch(['order_id' => $order->id, 'status' => $newStatus]);
                SendOrderPush::dispatch(['order_id' => $order->id, 'status' => $newStatus]);
                OrderStatusChanged::dispatch($order, $oldStatus, $newStatus);
                // [F-01] Auto-cleaned stale kiosk orders must release any branch-scoped
                // counters consumed at OrderCreated time. Idempotent via released_qty.
                OrderCanceled::dispatch($order);
            });
    }
}
