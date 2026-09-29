<?php

namespace App\Services\Hardware;

use App\Models\Printer;
use App\Services\Hardware\PrinterTransport\PrinterTransportInterface;
use Illuminate\Support\Facades\Log;

final class EscPosPrinterService
{
    public function __construct(
        private readonly PrinterTransportInterface $transport
    ) {
    }

    public function sendRaw(Printer $printer, string $bytes): bool
    {
        // [BYPASS-AUDIT-HEAL P1] Wire BypassAuditLogger::printingBypassed() —
        // RED a flagué le dead code (méthode définie mais jamais appelée).
        // Quand printing.bypass.enabled, AppServiceProvider bind NullPrinterTransport
        // qui swallow silently; ce log structuré garantit l'audit trail visible
        // dans storage/logs/laravel.log (cf runbook §7).
        \App\Services\Bypass\BypassAuditLogger::printingBypassed([
            'service' => 'EscPosPrinterService::sendRaw',
            'printer_id' => $printer->id,
            'station' => $printer->station,
            'bytes_count' => strlen($bytes),
        ]);

        $ok = $this->transport->send($bytes, [
            'host' => $printer->host,
            'port' => $printer->port,
            'type' => $printer->type,
            'station' => $printer->station,
        ]);

        if (! $ok) {
            Log::warning('[EscPosPrinterService] print failed', [
                'printer_id' => $printer->id,
                'branch_id' => $printer->branch_id,
                'station' => $printer->station,
                'type' => $printer->type,
                'error' => $this->transport->lastError(),
            ]);
        }

        return $ok;
    }

    public function testPrint(Printer $printer): bool
    {
        $widthChars = (int) ($printer->width_chars ?: 48);
        // [V14 C-β / FINDING C-β-T15-1 P2] Allow per-printer codepage override
        // via options. Default 19 = CP858 (the most common European thermal
        // default). Without selecting a codepage, accents print as "?" or
        // mojibake on most ESC/POS hardware.
        $opts = is_array($printer->options) ? $printer->options : [];
        $codePageNum = isset($opts['code_page']) ? (int) $opts['code_page'] : 19;

        $bytes = '';
        $bytes .= EscPosCommandBuilder::init();
        $bytes .= EscPosCommandBuilder::selectCodePage($codePageNum);
        $bytes .= EscPosCommandBuilder::alignCenter();
        $bytes .= EscPosCommandBuilder::doubleSize(true);
        $bytes .= EscPosCommandBuilder::bold(true);
        $bytes .= EscPosCommandBuilder::textLine('FOODKING POS');
        $bytes .= EscPosCommandBuilder::doubleSize(false);
        $bytes .= EscPosCommandBuilder::bold(false);
        $bytes .= EscPosCommandBuilder::textLine('Test print OK');
        $bytes .= EscPosCommandBuilder::separator('-', $widthChars);
        $bytes .= EscPosCommandBuilder::alignLeft();
        $bytes .= EscPosCommandBuilder::lineKV('Printer', $printer->name, $widthChars);
        $bytes .= EscPosCommandBuilder::lineKV('Station', (string) ($printer->station ?: '-'), $widthChars);
        $bytes .= EscPosCommandBuilder::lineKV('Date', now()->format('Y-m-d H:i:s'), $widthChars);
        $bytes .= EscPosCommandBuilder::feed(3);
        $bytes .= EscPosCommandBuilder::cut();

        return $this->sendRaw($printer, $bytes);
    }

    /**
     * Open the cash drawer via the branch receipt-station printer (or a specific printer id).
     * Never throws — returns a structured array for API callers.
     */
    public function openDrawer(?int $printerId = null, int $branchId = 0): array
    {
        try {
            if ($branchId <= 0) {
                return ['success' => false, 'error' => 'invalid_branch'];
            }

            // [Z6-P1-WGS 2026-05-19] singular form — Printer has no SoftDeletes,
            // so behaviour is unchanged; the explicit BranchScope::class arg
            // documents that the bypass is intentional (admin-driven cash
            // drawer open targets the explicit $branchId, not the caller's
            // own branch).
            // [ONB-10 2026-08-27] Le filtre `status = ACTIVE` manquait sur ces deux
            // recherches, alors que les TROIS autres chemins d'impression du produit
            // l'appliquent (KitchenTicketAutoPrinter::kitchenPrinter,
            // PosReceiptPrintController, PrintFiscalReceiptAndOpenDrawerOnCounterPaid).
            //
            // Conséquence, avec `orderBy('id')` : un commerçant qui remplace son
            // imprimante de caisse et archive l'ancienne voit ses tickets partir
            // correctement sur la NOUVELLE — mais la commande d'ouverture du tiroir
            // continuait d'être envoyée à l'ANCIENNE, parce qu'elle a l'identifiant le
            // plus petit. Le tiroir ne s'ouvre plus, sans message, au comptoir, en
            // plein service. Archiver une imprimante doit vouloir dire la même chose
            // partout.
            if ($printerId !== null && $printerId > 0) {
                $printer = Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('branch_id', $branchId)
                    ->where('id', $printerId)
                    ->where('status', \App\Enums\Status::ACTIVE)
                    ->first();
            } else {
                // Receipt role is stored in `station` (schema: type = escpos_tcp|…, station = receipt|kitchen_…).
                $printer = Printer::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
                    ->where('branch_id', $branchId)
                    ->where('station', 'receipt')
                    ->where('status', \App\Enums\Status::ACTIVE)
                    ->orderBy('id')
                    ->first();
            }

            if (! $printer) {
                return ['success' => false, 'error' => 'no_printer'];
            }

            $commandBytes = EscPosCommandBuilder::openDrawerCommand();

            // [BYPASS-AUDIT-HEAL P1] Wire BypassAuditLogger::printingBypassed() — cash drawer.
            \App\Services\Bypass\BypassAuditLogger::printingBypassed([
                'service' => 'EscPosPrinterService::openDrawer',
                'printer_id' => $printer->id,
                'station' => $printer->station,
                'bytes_count' => strlen($commandBytes),
            ]);

            $ok = $this->transport->send($commandBytes, [
                'host' => $printer->host,
                'port' => $printer->port,
                'type' => $printer->type,
                'station' => $printer->station,
            ]);

            if (! $ok) {
                return [
                    'success' => false,
                    'error' => $this->transport->lastError() ?? 'send_failed',
                    'printer_id' => (int) $printer->id,
                    'bytes_sent' => 0,
                ];
            }

            return [
                'success' => true,
                'printer_id' => (int) $printer->id,
                'bytes_sent' => strlen($commandBytes),
            ];
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => $e->getMessage()];
        }
    }
}
