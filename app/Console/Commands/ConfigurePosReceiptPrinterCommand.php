<?php

namespace App\Console\Commands;

use App\Models\Printer;
use App\Models\Scopes\BranchScope;
use App\Services\Hardware\EscPosPrinterService;
use Illuminate\Console\Command;

/**
 * [POS PRINTER 2026-06-04] One-shot setup for the POS receipt printer
 * (SAGA SGPR-200II — 80mm ESC/POS, LAN). Creates/updates the branch's
 * receipt-station Printer row and fires a test print so the owner can
 * confirm the wiring in a single command:
 *
 *     php artisan pos:configure-receipt-printer 192.168.1.50
 *
 * No OS driver is involved — the backend sends ESC/POS straight to the
 * printer IP on port 9100 (escpos_tcp transport). See
 * docs/hardware/SAGA_SGPR-200II_CAISSE_SETUP.md.
 */
class ConfigurePosReceiptPrinterCommand extends Command
{
    protected $signature = 'pos:configure-receipt-printer
        {ip : Adresse IP de l\'imprimante sur le réseau local (ex: 192.168.1.50)}
        {--port=9100 : Port RAW/ESC-POS (JetDirect). 9100 par défaut}
        {--branch=1 : branch_id de la caisse (V1 LOCAL = 1)}
        {--station=receipt : station printers (ticket client = receipt)}
        {--width=48 : Colonnes (80mm = 48 en police A)}
        {--code-page=19 : Code page ESC/POS (19 = CP858, accents FR + €)}
        {--name= : Nom affiché du périphérique}
        {--no-test : Ne pas envoyer le test print après configuration}';

    protected $description = 'Configure (crée/maj) l\'imprimante ticket caisse SAGA SGPR-200II et lance un test print';

    public function handle(EscPosPrinterService $service): int
    {
        $ip = trim((string) $this->argument('ip'));
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            $this->error("IP invalide : « {$ip} ». Exemple attendu : 192.168.1.50");

            return self::FAILURE;
        }

        $branchId = (int) $this->option('branch');
        $station = (string) $this->option('station');
        $port = (int) $this->option('port');
        $width = (int) $this->option('width');
        $codePage = (int) $this->option('code-page');
        $name = trim((string) ($this->option('name') ?: 'SAGA SGPR-200II (Caisse)'));

        if ($branchId <= 0) {
            $this->error('branch invalide (doit être > 0).');

            return self::FAILURE;
        }

        $printer = Printer::withoutGlobalScope(BranchScope::class)->updateOrCreate(
            ['branch_id' => $branchId, 'station' => $station],
            [
                'name' => $name,
                'type' => 'escpos_tcp',
                'host' => $ip,
                'port' => $port > 0 ? $port : 9100,
                'width_chars' => $width > 0 ? $width : 48,
                'status' => 1,
                'options' => ['code_page' => $codePage > 0 ? $codePage : 19],
            ]
        );

        $this->info('✓ Imprimante caisse configurée :');
        $this->table(
            ['Champ', 'Valeur'],
            [
                ['id', (string) $printer->id],
                ['name', $printer->name],
                ['type', $printer->type],
                ['host', $printer->host],
                ['port', (string) $printer->port],
                ['branch_id', (string) $printer->branch_id],
                ['station', (string) $printer->station],
                ['width_chars', (string) $printer->width_chars],
                ['code_page', (string) ($printer->options['code_page'] ?? '')],
                ['status', $printer->status ? 'actif' : 'inactif'],
            ]
        );

        if ($this->option('no-test')) {
            $this->line('Test print ignoré (--no-test). L\'auto-print s\'activera à la prochaine commande payée au comptoir.');

            return self::SUCCESS;
        }

        $this->line("Envoi du test print vers {$printer->host}:{$printer->port} …");
        $ok = $service->testPrint($printer);

        if ($ok) {
            $this->info('✓ Test print envoyé. Vérifie que le ticket est bien sorti de l\'imprimante.');
            $this->line('L\'impression automatique du ticket client se fera désormais après chaque paiement au comptoir.');

            return self::SUCCESS;
        }

        $this->warn('⚠ Le test print a échoué (imprimante injoignable ?).');
        $this->line('Vérifie : (1) câble Ethernet branché, (2) imprimante allumée,');
        $this->line("(3) l'IP {$printer->host} est correcte et joignable depuis ce PC (ping), (4) port {$printer->port} ouvert.");
        $this->line('La configuration est enregistrée — relance la commande une fois le réseau OK.');

        return self::FAILURE;
    }
}
