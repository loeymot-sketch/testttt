<?php

namespace App\Services;

use App\Models\LoyaltyTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class LoyaltyService
{
    /**
     * Refund loyalty points when an order that used loyalty discount is cancelled.
     *
     * Looks up the redeem transaction(s) for this order in the ledger,
     * re-credits the points to the customer, and writes a reversal entry.
     *
     * @param \Illuminate\Database\Eloquent\Model $order  Order or FrontendOrder being cancelled
     * @param string $sourceSurface  'pos' or 'kiosk' for audit trail
     */
    public function refundPoints($order, string $sourceSurface = 'pos'): void
    {
        if (!$order->loyalty_customer_code) {
            return;
        }

        $redeemTxns = LoyaltyTransaction::where('order_id', $order->id)
            ->where('type', 'redeem')
            ->get();

        if ($redeemTxns->isEmpty()) {
            return;
        }

        $totalPointsToRefund = $redeemTxns->sum(fn ($t) => abs($t->points));
        if ($totalPointsToRefund <= 0) {
            return;
        }

        $query = User::where('loyalty_code', $order->loyalty_customer_code)
            ->where('status', \App\Enums\Status::ACTIVE);
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }
        $loyaltyUser = $query->first();

        if (!$loyaltyUser) {
            Log::warning('[Loyalty] Refund skipped: customer not found', [
                'order_id' => $order->id,
                'loyalty_code' => $order->loyalty_customer_code,
                'points' => $totalPointsToRefund,
            ]);
            return;
        }

        DB::table('users')
            ->where('id', $loyaltyUser->id)
            ->increment('loyalty_points', $totalPointsToRefund);

        $balanceAfter = $loyaltyUser->loyalty_points + $totalPointsToRefund;

        LoyaltyTransaction::create([
            'user_id' => $loyaltyUser->id,
            'loyalty_code' => $loyaltyUser->loyalty_code,
            'order_id' => $order->id,
            'type' => 'manual_add',
            'points' => $totalPointsToRefund,
            'balance_after' => $balanceAfter,
            'source_surface' => $sourceSurface,
            'description' => 'Remboursement fidélité suite annulation commande #' . ($order->order_serial_no ?? $order->id),
        ]);

        Log::info('[Loyalty] Points refunded on cancel', [
            'order_id' => $order->id,
            'customer_id' => $loyaltyUser->id,
            'points_refunded' => $totalPointsToRefund,
            'new_balance' => $balanceAfter,
        ]);
    }
}
