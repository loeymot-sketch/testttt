<?php

namespace App\Services\Order;

use App\Exceptions\OrderSealedException;
use App\Models\Order;
use App\Models\ZReport;
use Illuminate\Support\Facades\Config;

/**
 * [P11-FZH / F-VERIFY-08-02]
 *
 * Centralised sealed-order detection. Uses the EXACT predicate from
 * OrderService::destroy() (lines 1896-1906) and ZReportService::aggregate()
 * — no semantic drift between "what destroy refuses", "what changeStatus
 * refuses", and "what aggregate already counted".
 *
 * Predicate: order is sealed iff
 *   exists ZReport WHERE branch_id = order.branch_id
 *     AND status = CLOSED
 *     AND opened_at < order.created_at
 *     AND closed_at >= order.created_at
 *     AND order.fiscal_sequence_no IS NOT NULL
 *
 * Feature flag `fiscal.sealed_z_guard_enabled` (env SEALED_Z_GUARD_ENABLED,
 * default true). When false, the guard is no-op (emergency rollback).
 */
class SealedOrderGuard
{
    /**
     * @throws OrderSealedException when the order is sealed AND the
     *                              attempted transition would mutate it.
     */
    public function assertMutable(Order $order, string $attemptedTransition): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        // Legacy / unfiscalised orders are not under NF525 sealing — let
        // them through (changeStatus → RETURNED on a draft order remains OK).
        if ($order->fiscal_sequence_no === null) {
            return;
        }

        $sealingZ = ZReport::query()
            ->where('branch_id', (int) $order->branch_id)
            ->where('status', ZReport::STATUS_CLOSED)
            ->where('opened_at', '<', $order->created_at)
            ->where('closed_at', '>=', $order->created_at)
            ->orderBy('id')
            ->first();

        if (!$sealingZ) {
            return;
        }

        throw new OrderSealedException(
            (int) $order->id,
            (int) $order->branch_id,
            (int) $sealingZ->id,
            $attemptedTransition
        );
    }

    /**
     * [WAVE5-POS-001] Inverse of assertMutable(): require that the order IS sealed
     * (i.e. lives in a CLOSED Z window) before allowing post-Z-only operations
     * such as RefundWithCounterEntryService::execute(). The mirror counter-entry
     * pattern is only meaningful AFTER Z closure — running it pre-Z creates a
     * double-counting hazard (mirror persists in current Z; the still-open parent
     * can later be set RETURNED via the standard pre-Z path → 2 negatives for 1
     * sale in the same Z window).
     *
     * Uses the SAME predicate as assertMutable() so there's no semantic drift
     * between "what blocks mutation post-Z" and "what RefundWithCounterEntry
     * requires before generating a mirror".
     *
     * Honors the same `fiscal.sealed_z_guard_enabled` feature flag for symmetry
     * (when off, both guards no-op; emergency rollback knob is uniform).
     *
     * @throws \InvalidArgumentException 422 when the parent order is NOT sealed.
     */
    public function assertSealed(\App\Models\Order $order, string $context): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        if ($order->fiscal_sequence_no === null) {
            throw new \InvalidArgumentException(
                'Order has no fiscal_sequence_no — cannot run "' . $context . '" on an unfiscalised draft.',
                422
            );
        }

        $sealingZ = ZReport::query()
            ->where('branch_id', (int) $order->branch_id)
            ->where('status', ZReport::STATUS_CLOSED)
            ->where('opened_at', '<', $order->created_at)
            ->where('closed_at', '>=', $order->created_at)
            ->orderBy('id')
            ->first();

        if ($sealingZ) {
            return; // sealed → operation allowed
        }

        throw new \InvalidArgumentException(
            'Order ' . (int) $order->id . ' is not in a CLOSED Z window — operation "'
                . $context . '" requires a sealed (post-Z) parent. Use the standard pre-Z path instead.',
            422
        );
    }

    /**
     * [WI-REFUND-PREZ 2026-06-04] Boolean predicate — is this order sealed
     * (contained in a CLOSED Z window) right now?
     *
     * Reuses the EXACT same predicate as assertMutable() / assertSealed() so
     * there is a single source of truth for "sealed?" semantics across
     * destroy / changeStatus / aggregate / refund. Used by
     * PosOrderController::refundWithCounterEntry to route a refund request to
     * the correct path WITHOUT duplicating the seal logic on the client:
     *   - sealed (post-Z)  → RefundWithCounterEntryService mirror counter-entry
     *   - NOT sealed (pre-Z) → standard pre-Z OrderService::changeStatus(RETURNED)
     *
     * Semantics:
     *   - feature flag off  → returns FALSE (guard no-op everywhere; the pre-Z
     *     path is the safe default — same standard RETURNED refund the rollback
     *     knob already routes mutations through).
     *   - fiscal_sequence_no === null → FALSE (an unfiscalised draft / a pre-Z
     *     POS order that has not been allocated a sequence yet is NOT sealed).
     *   - otherwise sealed iff a CLOSED ZReport window contains created_at.
     */
    public function isSealed(Order $order): bool
    {
        if (!$this->isEnabled()) {
            return false;
        }

        if ($order->fiscal_sequence_no === null) {
            return false;
        }

        return ZReport::query()
            ->where('branch_id', (int) $order->branch_id)
            ->where('status', ZReport::STATUS_CLOSED)
            ->where('opened_at', '<', $order->created_at)
            ->where('closed_at', '>=', $order->created_at)
            ->exists();
    }

    private function isEnabled(): bool
    {
        return (bool) Config::get('fiscal.sealed_z_guard_enabled', true);
    }
}
