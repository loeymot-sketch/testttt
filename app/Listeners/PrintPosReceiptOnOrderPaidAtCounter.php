<?php

namespace App\Listeners;

use App\Events\OrderPaidAtCounter;
use App\Models\Order;
use App\Services\Receipt\PosReceiptAutoPrinter;

/**
 * [POS PRINTER 2026-06-04] Auto-print the NF525 customer ticket when an order
 * is paid at the counter (deferred counter-collection: POS walk-in with
 * walkin_route_to_counter=true, and kiosk Plan-B orders physically paid at the
 * caisse). OrderPaidAtCounter is dispatched AFTER commit
 * (PaymentService::confirmCounterPayment), so the print can never roll back the
 * fiscal sequence.
 *
 * The inline paid-at-creation POS sale (the default flow) is covered by the
 * sibling {@see PrintPosReceiptOnOrderCreated}. The shared
 * {@see PosReceiptAutoPrinter} claims the original impression atomically, so an
 * order can never print twice even if both paths touch it.
 */
class PrintPosReceiptOnOrderPaidAtCounter
{
    public function __construct(private readonly PosReceiptAutoPrinter $autoPrinter)
    {
    }

    public function handle(OrderPaidAtCounter $event): void
    {
        $order = $event->order;
        if ($order instanceof Order) {
            $this->autoPrinter->printOriginalFor($order);
        }
    }
}
