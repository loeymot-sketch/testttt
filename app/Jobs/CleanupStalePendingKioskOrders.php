<?php

namespace App\Jobs;

use App\Domain\Order\OrderStateMachine;
use App\Enums\OrderStatus;
use App\Events\OrderStatusChanged;
use App\Events\SendOrderMail;
use App\Events\SendOrderPush;
use App\Events\SendOrderSms;
use App\Models\FrontendOrder;
use App\Models\Scopes\BranchScope;

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
        FrontendOrder::withoutGlobalScope(BranchScope::class)
            ->whereNull('deleted_at')
            ->where('status', OrderStatus::PENDING)
            ->where('source_surface', 'kiosk')
            ->whereIn('order_type', [\App\Enums\OrderType::KIOSK, \App\Enums\OrderType::TAKEAWAY])
            ->where(function ($query) use ($staleThreshold): void {
                $query->where('created_at', '<', $staleThreshold)
                    ->orWhere('order_datetime', '<', $staleThreshold);
            })
            ->orderBy('id')
            ->get()
            ->each(function (FrontendOrder $order): void {
                $oldStatus = (int) $order->status;

                OrderStateMachine::apply(
                    $order,
                    OrderStatus::REJECTED,
                    null,
                    'Auto-rejected stale pending kiosk order after 15 minutes.'
                );

                $order->refresh();

                SendOrderMail::dispatch(['order_id' => $order->id, 'status' => OrderStatus::REJECTED]);
                SendOrderSms::dispatch(['order_id' => $order->id, 'status' => OrderStatus::REJECTED]);
                SendOrderPush::dispatch(['order_id' => $order->id, 'status' => OrderStatus::REJECTED]);
                OrderStatusChanged::dispatch($order, $oldStatus, OrderStatus::REJECTED);
            });
    }
}
