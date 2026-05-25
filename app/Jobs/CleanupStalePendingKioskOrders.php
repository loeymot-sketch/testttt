<?php

namespace App\Jobs;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
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
        $staleThreshold = now()->subMinutes(15);

        /*
         * [W9-AUDIT FIX-5] Console job runs without Auth context: BranchScope is bypassed
         * naturally, but `withoutGlobalScopes()` would ALSO drop SoftDeletingScope, risking
         * the auto-rejection of orders that were already soft-deleted (e.g. by a manual
         * admin action). Drop only BranchScope (multi-tenant by design) and keep the
         * soft-delete guard intact.
         */
        // [GAP-C1-002] Extend cleanup to PENDING_COUNTER zombies (kiosk cash
        // abandonné client mid-flow). Previously only UNPAID kiosk orders were
        // purged → PENDING_COUNTER polluted KDS indefinitely + risk NF525 if
        // encaissé à tort. Branch isolation + TTL preserved.
        FrontendOrder::withoutGlobalScope(BranchScope::class)
            ->whereNull('deleted_at')
            ->where('status', OrderStatus::PENDING)
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
                $rejected = false;

                DB::transaction(function () use ($order, &$oldStatus, &$rejected): void {
                    $locked = FrontendOrder::withoutGlobalScope(BranchScope::class)
                        ->whereKey($order->id)
                        ->lockForUpdate()
                        ->first();

                    if (!$locked
                        || (int) $locked->status !== OrderStatus::PENDING
                        || !in_array((int) $locked->payment_status, [PaymentStatus::UNPAID, PaymentStatus::PENDING_COUNTER], true)) {
                        return;
                    }

                    $oldStatus = (int) $locked->status;

                    OrderStateMachine::apply(
                        $locked,
                        OrderStatus::REJECTED,
                        null,
                        'Auto-rejected stale pending kiosk order after 15 minutes.'
                    );

                    $locked->refresh();
                    $order->setRawAttributes($locked->getAttributes(), true);
                    $rejected = true;
                });

                if (!$rejected || $oldStatus === null) {
                    return;
                }

                SendOrderMail::dispatch(['order_id' => $order->id, 'status' => OrderStatus::REJECTED]);
                SendOrderSms::dispatch(['order_id' => $order->id, 'status' => OrderStatus::REJECTED]);
                SendOrderPush::dispatch(['order_id' => $order->id, 'status' => OrderStatus::REJECTED]);
                OrderStatusChanged::dispatch($order, $oldStatus, OrderStatus::REJECTED);
                // [F-01] Auto-rejected stale kiosk orders must release any branch-scoped
                // counters consumed at OrderCreated time. Idempotent via released_qty.
                OrderCanceled::dispatch($order);
            });
    }
}
