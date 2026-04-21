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

            if ($printerId !== null && $printerId > 0) {
                $printer = Printer::withoutGlobalScopes()
                    ->where('branch_id', $branchId)
                    ->where('id', $printerId)
                    ->first();
            } else {
                // Receipt role is stored in `station` (schema: type = escpos_tcp|…, station = receipt|kitchen_…).
                $printer = Printer::withoutGlobalScopes()
                    ->where('branch_id', $branchId)
                    ->where('station', 'receipt')
                    ->orderBy('id')
                    ->first();
            }

            if (! $printer) {
                return ['success' => false, 'error' => 'no_printer'];
            }

            $commandBytes = EscPosCommandBuilder::openDrawerCommand();
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
