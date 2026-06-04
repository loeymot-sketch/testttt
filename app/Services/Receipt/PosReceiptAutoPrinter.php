<?php

namespace App\Services\Receipt;

use App\Models\Order;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;
use App\Services\Fiscal\AuditLogService;
use App\Services\Hardware\EscPosPrinterService;
use Illuminate\Support\Facades\Log;

/**
 * [POS PRINTER 2026-06-04] Shared core that prints the ORIGINAL NF525 customer
 * ticket for a POS order on the branch receipt-station printer
 * (SAGA SGPR-200II, escpos_tcp LAN).
 *
 * Invoked from two thin listeners so EVERY way a sale is paid at the caisse is
 * covered, with no double print:
 *   - {@see \App\Listeners\PrintPosReceiptOnOrderCreated} — the default inline
 *     POS sale (OrderService::posOrderStore creates the order PAID, source=pos,
 *     fiscal seq allocated, and fires OrderCreated after commit).
 *   - {@see \App\Listeners\PrintPosReceiptOnOrderPaidAtCounter} — deferred
 *     counter-collection (POS walk-in when walkin_route_to_counter=true, and
 *     kiosk Plan-B orders physically paid at the counter).
 *
 * Count ownership / idempotency: the original impression is claimed atomically
 * (receipt_print_count 0 -> 1). The conditional UPDATE means whichever event
 * arrives first claims-and-prints; a second event (or a listener replay) for
 * the same order is a no-op. A failed physical send releases the claim so a
 * manual reprint is still treated as the original.
 *
 * Best-effort: every path is wrapped so a printer error can never escape into
 * the (already committed) order/payment flow.
 */
final class PosReceiptAutoPrinter
{
    public function __construct(
        private readonly EscPosPrinterService $printer,
        private readonly PosReceiptEscPosRenderer $renderer,
        private readonly AuditLogService $audit,
    ) {
    }

    /**
     * Attempt to print the original customer ticket for $order. Never throws.
     */
    public function printOriginalFor(Order $order): void
    {
        try {
            if (! (bool) config('pos.auto_print_receipt', true)) {
                return;
            }

            if (! $order->getKey()) {
                return;
            }

            $orderId = (int) $order->getKey();
            $branchId = (int) ($order->branch_id ?? 0);
            if ($branchId <= 0) {
                return;
            }

            $printerModel = $this->resolveReceiptPrinter($branchId);
            if ($printerModel === null) {
                // Expected state until `php artisan pos:configure-receipt-printer`
                // runs — debug only, not a warning.
                Log::debug('[pos-auto-print] no receipt printer configured', [
                    'order_id' => $orderId,
                    'branch_id' => $branchId,
                ]);

                return;
            }

            // Atomic claim of the ORIGINAL impression. Bypass BranchScope: the
            // listener runs post-commit without an authenticated branch user;
            // the target branch comes from the order itself.
            $claimed = Order::withoutGlobalScope(BranchScope::class)
                ->whereKey($orderId)
                ->whereRaw('COALESCE(receipt_print_count, 0) = 0')
                ->update(['receipt_print_count' => 1]);

            if ($claimed === 0) {
                // Already printed (other event, manual print, or replay).
                return;
            }

            $fresh = Order::withoutGlobalScope(BranchScope::class)
                ->with(['orderItems.orderItem', 'branch', 'user', 'payments'])
                ->find($orderId);

            if ($fresh === null) {
                $this->releaseClaim($orderId);

                return;
            }

            $bytes = $this->renderer->render($fresh, $this->renderOptions($printerModel));
            $ok = $this->printer->sendRaw($printerModel, $bytes);

            if (! $ok) {
                $this->releaseClaim($orderId);
                Log::warning('[pos-auto-print] receipt send failed — claim released', [
                    'order_id' => $orderId,
                    'branch_id' => $branchId,
                    'printer_id' => (int) $printerModel->id,
                ]);

                return;
            }

            $this->emitPrintAudit($branchId, $orderId);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function renderOptions(Printer $printerModel): array
    {
        $opts = ['is_duplicata' => false];
        if ($printerModel->width_chars) {
            $opts['width_chars'] = (int) $printerModel->width_chars;
        }
        $printerOpts = is_array($printerModel->options) ? $printerModel->options : [];
        if (isset($printerOpts['code_page'])) {
            $opts['code_page'] = (int) $printerOpts['code_page'];
        }

        return $opts;
    }

    private function resolveReceiptPrinter(int $branchId): ?Printer
    {
        $station = (string) config('pos.receipt_printer_station', 'receipt');

        return Printer::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('station', $station)
            ->where('status', 1)
            ->orderBy('id')
            ->first();
    }

    private function releaseClaim(int $orderId): void
    {
        try {
            Order::withoutGlobalScope(BranchScope::class)
                ->whereKey($orderId)
                ->where('receipt_print_count', 1)
                ->update(['receipt_print_count' => 0]);
        } catch (\Throwable $e) {
            report($e);
        }
    }

    private function emitPrintAudit(int $branchId, int $orderId): void
    {
        try {
            $this->audit->write([
                'branch_id' => $branchId,
                'user_id' => null,
                'action' => 'pos.receipt.print',
                'resource' => 'order',
                'resource_id' => $orderId,
                'payload' => [
                    'order_id' => $orderId,
                    'print_count_after' => 1,
                    'print_count_before' => 0,
                    'is_duplicata' => false,
                    'source' => 'auto_print',
                ],
            ]);
        } catch (\Throwable $e) {
            report($e);
        }
    }
}
