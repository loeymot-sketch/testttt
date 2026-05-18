<?php

namespace App\Services\Receipt;

use App\Models\Order;

/**
 * NF525 receipt data SSOT.
 *
 * Owns the assembly of the six fiscal/operator header fields that
 * appear on every printed POS ticket:
 *   - fiscal_sequence_no  (gap-free per-branch counter)
 *   - pos_register_id     (caisse identifier)
 *   - pos_siret           (établissement)
 *   - pos_vat_intra       (TVA intracommunautaire)
 *   - pos_legal_footer    (mentions légales)
 *   - operator_name       (caissier ayant clôturé)
 *
 * Consumed by {@see \App\Http\Resources\OrderDetailsResource} which
 * delegates the six keys above so the HTTP API + the JS-side receipt
 * builder ({@see resources/js/helpers/posReceiptBuilder.js}) converge
 * on a single source of truth. Anything that needs the printed-ticket
 * payload MUST go through this service rather than reading the model
 * directly — see {@see Tests\Feature\Receipt\ReceiptDataServiceWireInTest}.
 *
 * Pure read. No mutation. No pricing computation. No fiscal allocation.
 */
final class ReceiptDataService
{
    /**
     * Database-fetching entry-point. Used by callers that only have an
     * order id (legacy contract, documented in
     * docs/audit/POS_AUDIT_MASTER_PLAN_2026-05-06.md row 23).
     */
    public function buildForOrder(int $orderId): array
    {
        $order = Order::with(['branch', 'user'])->findOrFail($orderId);

        return $this->buildForOrderModel($order);
    }

    /**
     * Model-aware entry-point. Used by OrderDetailsResource which
     * already has the hydrated $order (with branch + user) in scope —
     * avoids a duplicate SELECT per HTTP request.
     *
     * Returned shape matches {@see buildForOrder()} exactly so both
     * signatures are interchangeable.
     */
    public function buildForOrderModel(Order $order): array
    {
        return [
            'order_id' => $order->id,
            'order_serial_no' => $order->order_serial_no,
            'fiscal_sequence_no' => $order->fiscal_sequence_no ?? null,
            'pos_register_id' => optional($order->branch)->register_id,
            'pos_siret' => optional($order->branch)->siret,
            'pos_vat_intra' => optional($order->branch)->vat_intra,
            'pos_legal_footer' => optional($order->branch)->legal_footer,
            'operator_name' => optional($order->user)->name,
            'created_at' => $order->created_at?->toIso8601String(),
        ];
    }
}
