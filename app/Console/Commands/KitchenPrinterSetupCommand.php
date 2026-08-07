<?php

namespace App\Console\Commands;

use App\Enums\Status;
use App\Models\Printer;
use App\Models\Scopes\BranchScope;
use Illuminate\Console\Command;

/**
 * [KITCHEN-AUTOPRINT 2026-08-07 owner] Configure et VÉRIFIE l'imprimante cuisine.
 *
 * L'imprimante (Epson TM-m30, IP fixe) vit sur le réseau du restaurant : elle n'est pas
 * joignable depuis un poste de développement. Cette commande est donc le seul moyen de
 * prouver, DEPUIS LA MACHINE DU RESTAURANT, que la chaîne complète fonctionne — sans avoir à
 * passer une vraie commande pour le découvrir en plein service.
 *
 * Elle diagnostique dans l'ordre des causes réelles de panne, de la plus fréquente à la plus
 * rare : mode bypass encore actif, aucune imprimante déclarée, mauvaise adresse, port fermé.
 *
 *   php artisan kitchen:printer --check
 *   php artisan kitchen:printer --host=192.168.192.168 --port=9100
 *   php artisan kitchen:printer --test
 */
class KitchenPrinterSetupCommand extends Command
{
    protected $signature = 'kitchen:printer
        {--branch=1}
        {--host= : adresse IP de l\'imprimante (ex. 192.168.192.168)}
        {--port=9100}
        {--width=48 : colonnes imprimables (TM-m30 en 80 mm = 48)}
        {--check : diagnostic seul, aucune modification}
        {--test : envoie un ticket de test réel}';

    protected $description = 'Configure et vérifie l\'imprimante cuisine (impression automatique)';

    public function handle(): int
    {
        $branchId = (int) $this->option('branch');

        $this->line('');
        $this->line('  IMPRIMANTE CUISINE — diagnostic');
        $this->line('  ───────────────────────────────');

        // 1. Le mode bypass court-circuite TOUT envoi. C'est la cause n°1 d'un « ça n'imprime
        //    pas » alors que la configuration est parfaite.
        $bypass = (bool) config('printing.bypass.enabled');
        $this->ligne('mode bypass', $bypass ? 'ACTIF → rien ne part vers l\'imprimante' : 'inactif', ! $bypass);
        if ($bypass) {
            $this->warn('    → mettre PRINTING_BYPASS_MODE=false dans .env sur la machine du restaurant.');
        }

        $this->ligne('pilote', (string) config('printing.driver'), config('printing.driver') === 'tcp');

        // 2. L'imprimante déclarée en base.
        $printer = $this->imprimante($branchId);

        if ($this->option('host')) {
            if ($this->option('check')) {
                $this->warn('    → --check est un diagnostic : --host est ignoré.');
            } else {
                $printer = $this->configurer($printer, $branchId);
            }
        }

        if (! $printer) {
            $this->ligne('imprimante cuisine', 'AUCUNE déclarée', false);
            $this->warn('    → php artisan kitchen:printer --host=192.168.192.168 --port=9100');

            return self::FAILURE;
        }

        $this->ligne('imprimante cuisine', "{$printer->name} — {$printer->host}:{$printer->port} ({$printer->station}, {$printer->width_chars} col)", true);
        $this->ligne('statut', $printer->status == Status::ACTIVE ? 'ACTIVE' : 'INACTIVE — elle ne sera jamais choisie', $printer->status == Status::ACTIVE);

        // 3. Le port répond-il ? Une IP juste sur une imprimante éteinte donne le même
        //    « ça n'imprime pas » qu'une IP fausse — il faut les distinguer.
        $joignable = $this->joignable((string) $printer->host, (int) $printer->port);
        $this->ligne('port TCP', $joignable ? 'ouvert' : "injoignable ({$printer->host}:{$printer->port})", $joignable);
        if (! $joignable) {
            $this->warn('    → imprimante éteinte, câble réseau débranché, ou machine hors du réseau du restaurant.');
        }

        // 4. Ticket de test réel.
        if ($this->option('test')) {
            $this->line('');
            if (! $joignable) {
                $this->error('  Test annulé : le port ne répond pas.');

                return self::FAILURE;
            }
            $ok = $this->ticketDeTest($printer);
            $this->ligne('ticket de test', $ok ? 'ENVOYÉ — vérifiez le rouleau' : 'échec de l\'envoi', $ok);

            return $ok ? self::SUCCESS : self::FAILURE;
        }

        $this->line('');
        $this->line('  Ajoutez --test pour envoyer un vrai ticket.');
        $this->line('');

        return ($joignable && ! $bypass) ? self::SUCCESS : self::FAILURE;
    }

    private function imprimante(int $branchId): ?Printer
    {
        foreach (['kitchen_hot', 'kitchen', 'kitchen_cold'] as $station) {
            $p = Printer::withoutGlobalScope(BranchScope::class)
                ->where('branch_id', $branchId)->where('station', $station)->orderBy('id')->first();
            if ($p) {
                return $p;
            }
        }

        return null;
    }

    private function configurer(?Printer $printer, int $branchId): Printer
    {
        $attrs = [
            'branch_id' => $branchId,
            'name' => 'Cuisine',
            'type' => 'escpos_tcp',
            'host' => (string) $this->option('host'),
            'port' => (int) $this->option('port'),
            'station' => 'kitchen_hot',
            'width_chars' => (int) $this->option('width'),
            'status' => Status::ACTIVE,
        ];

        if ($printer) {
            $printer->update($attrs);
            $this->info("    ~ imprimante mise à jour → {$attrs['host']}:{$attrs['port']}");

            return $printer->refresh();
        }

        $this->info("    + imprimante créée → {$attrs['host']}:{$attrs['port']}");

        return Printer::create($attrs);
    }

    /** Test de port court : on veut un diagnostic, pas une attente de 30 secondes. */
    private function joignable(string $host, int $port): bool
    {
        $sock = @fsockopen($host, $port, $errno, $errstr, 3);
        if ($sock === false) {
            return false;
        }
        fclose($sock);

        return true;
    }

    private function ticketDeTest(Printer $printer): bool
    {
        $w = (int) ($printer->width_chars ?: 48);
        $b = \App\Services\Hardware\EscPosCommandBuilder::init()
            .\App\Services\Hardware\EscPosCommandBuilder::alignCenter()
            .\App\Services\Hardware\EscPosCommandBuilder::bold(true)
            .\App\Services\Hardware\EscPosCommandBuilder::textLine('TEST IMPRESSION CUISINE')
            .\App\Services\Hardware\EscPosCommandBuilder::bold(false)
            .\App\Services\Hardware\EscPosCommandBuilder::separator('-', $w)
            .\App\Services\Hardware\EscPosCommandBuilder::textLine('CUISSON   4K 2,5P 3F')
            .\App\Services\Hardware\EscPosCommandBuilder::separator('-', $w)
            .\App\Services\Hardware\EscPosCommandBuilder::textLine(now()->format('d/m/Y H:i:s'))
            .\App\Services\Hardware\EscPosCommandBuilder::feed(4)
            .\App\Services\Hardware\EscPosCommandBuilder::cut();

        try {
            return app(\App\Services\Hardware\EscPosPrinterService::class)->sendRaw($printer, $b);
        } catch (\Throwable $e) {
            $this->error('    '.$e->getMessage());

            return false;
        }
    }

    private function ligne(string $label, string $valeur, bool $ok): void
    {
        $this->line(sprintf('  %s %-20s %s', $ok ? '<info>✓</info>' : '<error>✗</error>', $label, $valeur));
    }
}
