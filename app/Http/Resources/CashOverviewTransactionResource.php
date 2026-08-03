<?php

namespace App\Http\Resources;

use App\Http\Controllers\Admin\CashOverviewController;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * [Wave X — X4 2026-05-21] Single-row transaction resource for the
 * /admin/cash-overview unified view.
 *
 * Differs from {@see TransactionResource} (which is the legacy admin
 * transaction listing) by exposing derived `source` / `mode_bucket` so
 * the UI can render the unified table without re-deriving in JS.
 *
 * The source + mode derivations are delegated to static methods on
 * CashOverviewController to keep ONE source of truth.
 */
class CashOverviewTransactionResource extends JsonResource
{
    public function toArray($request): array
    {
        $order = $this->order;
        $rawMode = (string) ($this->payment_method ?? '');

        return [
            'id'                 => (int) $this->id,
            'created_at'         => optional($this->created_at)->toIso8601String(),
            'order_id'           => (int) $this->order_id,
            'order_serial_no'    => $order?->order_serial_no,
            'queue_number'       => $order?->queue_number,
            'source'             => CashOverviewController::deriveSource($this->resource),
            'mode_raw'           => $rawMode,
            'mode_bucket'        => CashOverviewController::derivePaymentBucket($rawMode),
            'amount'             => round((float) ($this->amount ?? 0), 2),
            'transaction_no'     => $this->transaction_no,
            'delivery_boy_id'    => $order?->delivery_boy_id ? (int) $order->delivery_boy_id : null,
            'delivery_boy_name'  => $order?->deliveryBoy?->name,
        ];
    }
}
