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

        // [HEAL-A.2 / Z8 P0-2 — 2026-05-19] Idempotent NOOP via early-detect.
        // The loyalty_transactions UNIQUE (user_id, order_id, type) index
        // (migration 2026_03_26_075919_add_unique_to_loyalty_transactions.php)
        // throws SQLSTATE 23000 on a second create with the same key. The
        // pre-heal code did `DB::table('users')->increment('loyalty_points', ...)`
        // BEFORE the LoyaltyTransaction::create — so a duplicate cancel call
        // (a) double-credited the customer's balance, then (b) threw 23000
        // from the ledger insert, surfacing as a generic 500 and rolling
        // back the caller's outer DB::transaction (status flip, cashBack,
        // mirror order). All 4 callers run inside outer DB::transaction:
        // OrderService:1753 + :1856, FrontendOrderService:707,
        // Order/RefundWithCounterEntryService:241 — a throw would cascade.
        // Owner Q1 decision (HEAL-PLAN-A §A.2, 2026-05-19): NOOP idempotent
        // (planner-recommended), NOT 409 throw. Detect the existing reversal
        // row BEFORE any mutation and return silently.
        $alreadyRefunded = LoyaltyTransaction::where('user_id', $loyaltyUser->id)
            ->where('order_id', $order->id)
            ->where('type', 'manual_add')
            ->exists();
        if ($alreadyRefunded) {
            Log::info('[Loyalty] Refund already credited — idempotent NOOP', [
                'order_id' => $order->id,
                'customer_id' => $loyaltyUser->id,
                'points_attempted' => $totalPointsToRefund,
            ]);
            return;
        }

        DB::table('users')
            ->where('id', $loyaltyUser->id)
            ->increment('loyalty_points', $totalPointsToRefund);

        $balanceAfter = $loyaltyUser->loyalty_points + $totalPointsToRefund;

        // [L2 LOYALTY-SYNC 2026-06-11]
        \App\Events\LoyaltyBalanceChanged::dispatch(
            (int) $loyaltyUser->id,
            (int) ($order->branch_id ?? 1),
            (int) $balanceAfter,
            (int) $totalPointsToRefund,
            'refund'
        );

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

    /**
     * [GOAL-J2-HEAL-07 2026-05-24] Phase J-ADV-3 L3 P1 CONFIRMED:
     * Subtract previously-awarded loyalty points from user balance on refund.
     *
     * Pre-heal gap: LoyaltyService::refundPoints reverses REDEEM rows only;
     * no path decremented user.loyalty_points by orders.loyalty_points_awarded
     * after a refund. With 10 pts/€ default rate, a 30€ refunded-but-DELIVERED
     * order left 300 pts (= 3€ free) on the customer balance — repeatable
     * cash + points double-dip exploit.
     *
     * Mirrors {@see AwardLoyaltyPointsOnDelivery} pattern in inverse:
     *   - Earn writes type='earn' / positive points.
     *   - Clawback writes type='manual_deduct' / negative points.
     *
     * Note on type choice: loyalty_transactions.type ENUM (migration
     * 2026_03_26_075918) is fixed to ['earn','redeem','manual_add',
     * 'manual_deduct','expire']. 'clawback' would violate the column
     * constraint on MySQL prod, so 'manual_deduct' — the exact inverse
     * of the 'manual_add' already used by refundPoints — is the
     * semantically correct enum value. The 'reason' string is preserved
     * verbatim in the description field for the audit trail.
     *
     * Idempotency: enforced by the (user_id, order_id, type) UNIQUE index
     * (migration 2026_03_26_075919) plus a pre-flight existence check
     * mirroring refundPoints HEAL-A.2 pattern — a duplicate event silently
     * NOOPs instead of throwing SQLSTATE 23000 (which would cascade-fail
     * the outer DispatchableAfterCommit dispatcher).
     *
     * Balance is clamped at 0 — never negative — to protect against
     * race conditions where partial redemptions / expirations might
     * have already drawn the balance below the awarded amount.
     */
    public function clawbackEarnedPoints(int $userId, int $amount, int $orderId, string $reason): void
    {
        if ($amount <= 0) {
            return;
        }

        $existing = LoyaltyTransaction::where('user_id', $userId)
            ->where('order_id', $orderId)
            ->where('type', 'manual_deduct')
            ->exists();
        if ($existing) {
            Log::info('[Loyalty] Clawback already processed — idempotent NOOP', [
                'order_id' => $orderId,
                'user_id' => $userId,
                'points_attempted' => $amount,
            ]);
            return;
        }

        DB::transaction(function () use ($userId, $amount, $orderId, $reason) {
            $query = User::where('id', $userId)
                ->where('status', \App\Enums\Status::ACTIVE);
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }
            $user = $query->first();
            if (!$user) {
                return;
            }

            $currentBalance = (int) ($user->loyalty_points ?? 0);
            $newBalance = max(0, $currentBalance - $amount);
            $actualDeducted = $currentBalance - $newBalance;

            DB::table('users')
                ->where('id', $userId)
                ->update(['loyalty_points' => $newBalance]);

            LoyaltyTransaction::create([
                'user_id' => $userId,
                'loyalty_code' => $user->loyalty_code,
                'order_id' => $orderId,
                'type' => 'manual_deduct',
                'points' => -$actualDeducted,
                'balance_after' => $newBalance,
                'source_surface' => 'refund',
                'description' => $reason . ' — commande #' . $orderId,
            ]);

            // [L2 LOYALTY-SYNC 2026-06-11]
            \App\Events\LoyaltyBalanceChanged::dispatch(
                (int) $userId, 1, (int) $newBalance, -$actualDeducted, 'clawback'
            );

            Log::info('[Loyalty] Earned points clawed back on refund', [
                'order_id' => $orderId,
                'user_id' => $userId,
                'points_attempted' => $amount,
                'points_actually_deducted' => $actualDeducted,
                'new_balance' => $newBalance,
                'reason' => $reason,
            ]);
        });
    }
}
