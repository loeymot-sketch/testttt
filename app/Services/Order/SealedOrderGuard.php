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

    private function isEnabled(): bool
    {
        return (bool) Config::get('fiscal.sealed_z_guard_enabled', true);
    }
}
