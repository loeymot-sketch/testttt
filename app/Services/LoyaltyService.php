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

        // [AUDIT FIDÉLITÉ 2026-08-01 · P0-2] On rembourse CHAQUE ligne redeem à SON PROPRE
        // porteur (`loyalty_transactions.user_id`), jamais en bloc au porteur de
        // `orders.loyalty_customer_code` : ce code est ÉCRASÉ par le dernier rachat, donc
        // deux codes sur une même commande faisaient perdre ses points au premier client et
        // en offraient autant au second. Le grand-livre est la source de vérité.
        foreach ($redeemTxns->groupBy('user_id') as $userId => $txns) {
            $this->refundPointsToOwner((int) $userId, $order, (int) $txns->sum(fn ($t) => abs($t->points)), $sourceSurface);
        }
    }

    /**
     * Recrédite les points d'UN porteur pour une commande annulée, de façon idempotente.
     */
    private function refundPointsToOwner(int $userId, $order, int $totalPointsToRefund, string $sourceSurface): void
    {
        if ($userId <= 0 || $totalPointsToRefund <= 0) {
            return;
        }

        // [AUDIT FIDÉLITÉ 2026-08-01 · P0-1] SYMÉTRIE DE STATUT avec le débit : PosRedemptionService
        // accepte explicitement les comptes legacy `status=1` en plus de ACTIVE. Filtrer plus
        // strictement ICI détruisait les points de ces clients à l'annulation (débit accepté,
        // remboursement refusé, simple log warning). On identifie le porteur par son ID (issu du
        // grand-livre) : aucun filtre de statut ne peut plus faire disparaître de l'argent client.
        $query = User::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)->where('id', $userId);
        if (DB::connection()->getDriverName() !== 'sqlite') {
            $query->lockForUpdate();
        }
        $loyaltyUser = $query->first();

        if (!$loyaltyUser) {
            Log::warning('[Loyalty] Refund skipped: customer not found', [
                'order_id' => $order->id,
                'user_id' => $userId,
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
            // [P0 RED-CUMUL 2026-08-04] AUCUN filtre de statut — miroir EXACT du heal 08-01 sur
            // refundPointsToOwner. L'award ne regarde PAS status (AwardLoyaltyPointsOnDelivery:66)
            // → un compte legacy (status=1) ou désactivé (status=10) gagnait des points ; les
            // filtrer ICI les laissait AU CLIENT au remboursement (« la maison paie »). On
            // identifie par user_id seul, comme la fonction jumelle de remboursement de rachat.
            $query = User::where('id', $userId);
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

    /**
     * [CLUSTER-7 / P3 2026-07-11] Re-credit UNCONSUMED self-service pre-redemptions.
     *
     * The pre-redeem endpoint (LoyaltyController::redeem) debits points immediately
     * and writes a PENDING ledger row (type='redeem', order_id=NULL). When an order
     * is placed, FrontendOrderService backfills that row's order_id (10-min attach
     * window). If NO order is ever placed / is abandoned, the row stays order_id=NULL
     * and the order-keyed {@see self::refundPoints} can never re-credit it → points
     * burned. This reaper closes that gap: it re-credits any pending redeem older than
     * the configured window (strictly > the 10-min attach window, so it can never race
     * a legitimate late order attach).
     *
     * Mirrors {@see self::refundPoints} (reversal via a NEW type='manual_add' row —
     * the orphan is left immutable). Idempotent: a per-orphan token `[reap:<id>]` in
     * the reversal description guards against double-credit across repeated runs.
     *
     * @param  int|null  $olderThanMinutes  Override the config window (mainly for tests).
     * @return int  Number of orphan redemptions re-credited this run.
     */
    public function reapOrphanRedemptions(?int $olderThanMinutes = null): int
    {
        $olderThanMinutes = $olderThanMinutes ?? (int) config('loyalty.orphan_redeem_reap_minutes', 30);
        if ($olderThanMinutes < 1) {
            $olderThanMinutes = 30;
        }
        // [P2 cycle3 SÉCU 2026-08-04] Plancher DUR strictement > la fenêtre d'attach (10 min dans
        // FrontendOrderService::applyKioskLoyaltyDiscount). Sinon un `LOYALTY_ORPHAN_REDEEM_REAP_MINUTES`
        // trop bas re-créditerait un pré-rachat ENCORE rattachable → double-bénéfice (points rendus
        // ET remise appliquée à la commande sans débit). Aucune valeur ≤ 10 n'est jamais utilisée.
        if ($olderThanMinutes < 11) {
            $olderThanMinutes = 11;
        }
        $threshold = now()->subMinutes($olderThanMinutes);

        $orphans = LoyaltyTransaction::query()
            ->where('type', 'redeem')
            ->whereNull('order_id')
            ->where('points', '<', 0)
            ->where('created_at', '<', $threshold)
            ->orderBy('id')
            ->get();

        $reaped = 0;
        foreach ($orphans as $orphan) {
            if ($this->reapSingleOrphanRedemption((int) $orphan->id)) {
                $reaped++;
            }
        }

        return $reaped;
    }

    /**
     * Re-credit a single orphan pending redeem under a row lock, idempotently.
     *
     * @return bool  True if points were re-credited this call, false on NOOP
     *               (consumed since, already reaped, or customer row gone).
     */
    private function reapSingleOrphanRedemption(int $orphanId): bool
    {
        return (bool) DB::transaction(function () use ($orphanId) {
            // Re-fetch under lock. If order_id was backfilled between the outer
            // select and here (order finally placed), it no longer matches the
            // whereNull filter → NOOP (never re-credit a consumed redemption).
            $query = LoyaltyTransaction::query()
                ->where('id', $orphanId)
                ->where('type', 'redeem')
                ->whereNull('order_id');
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $query->lockForUpdate();
            }
            $orphan = $query->first();
            if (!$orphan) {
                return false;
            }

            $points = abs((int) $orphan->points);
            if ($points <= 0) {
                return false;
            }

            // Idempotency: a prior run already wrote the reversal for THIS orphan.
            // The (user_id, order_id, type) UNIQUE index does not protect NULL
            // order_id rows (MySQL treats NULLs as distinct), so guard on the
            // per-orphan token embedded in the reversal description.
            $token = '[reap:'.$orphan->id.']';
            $alreadyReaped = LoyaltyTransaction::query()
                ->where('type', 'manual_add')
                ->where('description', 'like', '%'.$token.'%')
                ->exists();
            if ($alreadyReaped) {
                return false;
            }

            // Re-credit regardless of current account status: these are the
            // customer's OWN previously-debited points; refusing to return them
            // to a since-deactivated account would strand them exactly as the
            // bug this reaper fixes. Only require the row to still exist.
            $userQuery = User::query()->where('id', $orphan->user_id);
            if (DB::connection()->getDriverName() !== 'sqlite') {
                $userQuery->lockForUpdate();
            }
            $user = $userQuery->first();
            if (!$user) {
                Log::warning('[Loyalty] Orphan redeem reap skipped: customer row missing', [
                    'txn_id' => $orphan->id,
                    'user_id' => $orphan->user_id,
                    'points' => $points,
                ]);
                return false;
            }

            DB::table('users')
                ->where('id', $user->id)
                ->increment('loyalty_points', $points);

            $balanceAfter = (int) $user->loyalty_points + $points;

            LoyaltyTransaction::create([
                'user_id' => $user->id,
                'loyalty_code' => $user->loyalty_code,
                'order_id' => null,
                'type' => 'manual_add',
                'points' => $points,
                'balance_after' => $balanceAfter,
                'source_surface' => $orphan->source_surface ?: 'reaper',
                'description' => 'Reprise fidelite: reduction non consommee (commande abandonnee) '.$token,
            ]);

            Log::info('[Loyalty] Orphan pending redeem re-credited', [
                'txn_id' => $orphan->id,
                'user_id' => $user->id,
                'points_refunded' => $points,
                'new_balance' => $balanceAfter,
            ]);

            return true;
        });
    }
}
