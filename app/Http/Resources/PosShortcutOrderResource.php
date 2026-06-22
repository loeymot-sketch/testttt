<?php

namespace App\Http\Resources;

use Illuminate\Http\Resources\Json\JsonResource;

/**
 * [test-e2e fix A-001 round-1 2026-05-21] POS shortcut variant of
 * CDSOrderDetailsResource. The authenticated POS dashboard widget
 * (`pos-shortcuts-ready`) must display the order total so a cashier can
 * read what was billed when handing over the bag.
 *
 * CDSOrderDetailsResource is reused on the PUBLIC customer wall display
 * (`frontend/oss-order` route, OrderStatusScreenController::publicIndex)
 * where exposing `total` would leak the amount on a TV screen visible to
 * any customer in the lobby. That resource must therefore stay PII-free.
 *
 * Contract: this resource is consumed ONLY by the authenticated admin
 * endpoint `admin/oss-order` (`OrderStatusScreenController::index`). Per-
 * mission gate `permission:order-status-screen` is enforced upstream by
 * the controller constructor.
 */
class PosShortcutOrderResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @param \Illuminate\Http\Request $request
     * @return array
     */
    public function toArray($request): array
    {
        return [
            'id'              => $this->id,
            'order_serial_no' => $this->order_serial_no,
            'token'           => $this->token,
            'queue_number'    => $this->queue_number,
            'order_type'      => $this->order_type,
            'status'          => $this->status,
            // [test-e2e fix A-001 round-1 2026-05-21] Numeric `total` so
            // PosComponent's `formatKioskPrice(o.total ?? o.order_amount)`
            // renders the right amount on the Prêt-à-livrer shortcut rows
            // instead of the structural 0,00 €.
            'total'           => round((float) ($this->total ?? 0), 2),
        ];
    }
}
