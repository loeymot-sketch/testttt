<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;
use Illuminate\Console\Command;

/**
 * [PRINT-ACTIVATION 2026-06-28] Configure the counter SAGA as the ESC/POS receipt
 * printer so the POS prints the clean thermal ticket (proper accents/€, no browser
 * URL footer, physical cut between the client and kitchen tickets) instead of the
 * window.print() fallback.
 *
 * Run ON the Windows caisse (where the SAGA is plugged in USB):
 *   php artisan pos:setup-receipt-printer "EXACT WINDOWS PRINTER NAME"
 * and set PRINT_DRIVER=windows_raw in .env (see docs/PRINT_SAGA_USB_WINDOWS_SETUP.md).
 */
class SetupReceiptPrinterCommand extends Command
{
    protected $signature = 'pos:setup-receipt-printer
        {name : Exact Windows printer queue name (Get-Printer | Select-Object Name)}
        {--branch=1 : Branch id}
        {--width=48 : Characters per line (48 = 80mm Font A, 32 = 58mm)}
        {--code-page=19 : ESC/POS code page (19 = CP858, FR accents + €)}';

    protected $description = 'Create/update the ACTIVE receipt printer row so the POS prints ESC/POS to the counter printer';

    public function handle(): int
    {
        $name = trim((string) $this->argument('name'));
        if ($name === '') {
            $this->error('Printer name is required.');

            return self::FAILURE;
        }
        $branchId = (int) $this->option('branch');

        $printer = Printer::withoutGlobalScope(BranchScope::class)
            ->where('branch_id', $branchId)
            ->where('station', 'receipt')
            ->orderBy('id')
            ->first() ?? new Printer;

        $printer->forceFill([
            'branch_id' => $branchId,
            // [CHANGEMENT-IMPRIMANTE 2026-08-09] Le libellé était figé sur « SAGA Caisse »
            // et survivait donc au remplacement du matériel (back-office affichant une
            // marque qui n'est plus branchée). Il DÉRIVE désormais du nom de file réel.
            'name' => 'Caisse (' . $name . ')',
            'type' => 'escpos_usb_windows',
            'host' => $name,
            'station' => 'receipt',
            'width_chars' => (int) $this->option('width'),
            'status' => Status::ACTIVE,
            'options' => ['code_page' => (int) $this->option('code-page')],
        ])->save();

        $this->info("Receipt printer set: station=receipt host=\"{$name}\" width={$printer->width_chars} (branch {$branchId}).");
        $this->line('Reminder: set PRINT_DRIVER=windows_raw in .env, then php artisan config:clear.');

        return self::SUCCESS;
    }
}
