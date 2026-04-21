<?php

namespace App\Services\Receipt;

use App\Models\Order;

final class ReceiptDataService
{
    /**
     * Assemble all data needed by the printed receipt for an order.
     * Pure read. No mutation. No pricing computation.
     */
    public function buildForOrder(int $orderId): array
    {
        $order = Order::with(['branch', 'user'])->findOrFail($orderId);

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
