<?php

namespace App\Listeners;

use App\Enums\Status;
use App\Events\OrderCreated;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;
use App\Services\Hardware\EscPosPrinterService;
use App\Services\Hardware\OrderReceiptEscPosRenderer;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * [PRINT-SAGA 2026-06-24] When a BORNE (kiosk) order is created, print a COPY of
 * the ticket on the COUNTER (caisse) printer — so the counter/kitchen sees the
 * order arriving even though the customer ordered at the kiosk.
 *
 * The kiosk already prints the customer's copy on its OWN printer (Electron
 * bridge, KioskConfirmationComponent — frozen, pre-existing). This listener adds
 * the SECOND copy on the caisse's ESC/POS printer (the SAGA), via the same
 * server-side render+send path as the caisse receipt.
 *
 * Runs AFTER commit (OrderCreated uses DispatchableAfterCommit) and is fully
 * best-effort: any failure is logged and isolated — it MUST NOT break order
 * creation nor the sibling listeners (outbox/stock/FCM).
 *
 * POS orders are skipped (they already print at the caisse on checkout).
 */
class PrintKioskOrderToCounter
{
    public function __construct(
        private readonly EscPosPrinterService $printer = new EscPosPrinterService(new \App\Services\Hardware\PrinterTransport\NullPrinterTransport),
        private readonly OrderReceiptEscPosRenderer $renderer = new OrderReceiptEscPosRenderer,
    ) {
    }

    public function handle(OrderCreated $event): void
    {
        try {
            $order = $event->order;

            // Only borne/kiosk orders get a counter copy.
            if ((string) ($order->source_surface ?? '') !== 'kiosk') {
                return;
            }

            $branchId = (int) ($order->branch_id ?? 0);
            if ($branchId <= 0) {
                return;
            }

            $printer = Printer::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)
                ->where('station', 'receipt')
                ->where('status', Status::ACTIVE)
                ->orderBy('id')
                ->first();
            if (! $printer) {
                return; // no counter printer configured → nothing to copy
            }

            $order->loadMissing(['branch', 'user']);
            $order->setRelation('orderItems', $order->orderItems()->withoutGlobalScope(BranchScope::class)->get());

            $bytes = $this->renderer->renderClientTicket($order, [
                'width_chars' => (int) ($printer->width_chars ?: 48),
                'counter_copy' => true,
            ]);

            // Resolve through the container so the bound transport (Tcp / WindowsRaw /
            // Null per config) is used, not the constructor default.
            app(EscPosPrinterService::class)->sendRaw($printer, $bytes);
        } catch (Throwable $e) {
            Log::warning('[PrintKioskOrderToCounter] counter copy print failed', [
                'order_id' => $event->order->id ?? null,
                'branch_id' => $event->order->branch_id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
