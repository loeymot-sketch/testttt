<?php

namespace App\Listeners;

use App\Enums\PaymentStatus;
use App\Events\OrderCreated;
use App\Models\Order;
use App\Services\Receipt\PosReceiptAutoPrinter;

/**
 * [POS PRINTER 2026-06-04] Auto-print the NF525 customer ticket for the DEFAULT
 * inline POS sale.
 *
 * OrderService::posOrderStore creates the caisse order already PAID
 * (source_surface='pos', fiscal_sequence_no allocated) and fires OrderCreated
 * AFTER commit. This listener prints ONLY for orders that are:
 *   - source_surface = 'pos' (the caisse register — NOT kiosk / web / admin),
 *   - paid.
 *
 * Kiosk orders (own printer), web/app orders and unpaid / deferred-to-counter
 * orders are skipped here; the counter-collection path is handled by
 * {@see PrintPosReceiptOnOrderPaidAtCounter}. The shared
 * {@see PosReceiptAutoPrinter} guarantees a single impression per order.
 */
class PrintPosReceiptOnOrderCreated
{
    public function __construct(private readonly PosReceiptAutoPrinter $autoPrinter)
    {
    }

    public function handle(OrderCreated $event): void
    {
        $order = $event->order;
        if (! $order instanceof Order) {
            return;
        }

        if ((string) ($order->source_surface ?? '') !== 'pos') {
            return;
        }

        if ((int) ($order->payment_status ?? 0) !== PaymentStatus::PAID) {
            return;
        }

        $this->autoPrinter->printOriginalFor($order);
    }
}
