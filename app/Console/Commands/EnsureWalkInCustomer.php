<?php

namespace App\Console\Commands;

use App\Models\User;
use Database\Seeders\WalkInCustomerSeeder;
use Illuminate\Console\Command;

/**
 * [POS-3] Boot assertion : vérifie qu'un walk-in customer existe en base avant
 * de laisser le POS démarrer. À exécuter au déploiement (post-migrate) et,
 * idéalement, dans une healthcheck startup.
 *
 * Sans ce row, `PosComponent.vue` peut attribuer une commande anonyme à un
 * vrai client (le premier rencontré dans la liste filtrée role_id=2),
 * provoquant une fuite d'historique et un mauvais reporting.
 *
 * Usage :
 *   php artisan foodking:walk-in:ensure          # exit 1 si manquant
 *   php artisan foodking:walk-in:ensure --seed   # auto-fix via WalkInCustomerSeeder
 */
class EnsureWalkInCustomer extends Command
{
    protected $signature = 'foodking:walk-in:ensure
                            {--seed : Lancer WalkInCustomerSeeder si manquant}';

    protected $description = '[POS-3] Vérifie qu\'un walk-in customer existe (boot assertion). --seed pour auto-fix.';

    public function handle(): int
    {
        // Résolution alignée sur PosComponent.vue : email exact OU name contenant 'walking'.
        // BranchScope écarté car le walk-in est branch_id=0 (global).
        $exists = User::withoutGlobalScopes()
            ->where(function ($q) {
                $q->where('email', WalkInCustomerSeeder::WALKING_EMAIL)
                  ->orWhereRaw('LOWER(name) LIKE ?', ['%walking%']);
            })
            ->exists();

        if ($exists) {
            $this->info('[OK] Walk-in customer présent — POS résolution déterministe.');
            return self::SUCCESS;
        }

        $this->error('[CRITICAL][POS-3] Walk-in customer MANQUANT en base.');
        $this->line('Risque : PosComponent.vue va attribuer les commandes anonymes au premier');
        $this->line('client trouvé (role_id=2), provoquant une fuite d\'historique.');

        if ($this->option('seed')) {
            $this->warn('Lancement de WalkInCustomerSeeder...');
            $seeder = new WalkInCustomerSeeder();
            $seeder->setCommand($this);
            $seeder->run();
            $this->info('[FIXED] Walk-in customer créé.');
            return self::SUCCESS;
        }

        $this->line('');
        $this->line('Correction :');
        $this->line('  php artisan foodking:walk-in:ensure --seed');
        $this->line('  # ou : php artisan db:seed --class=WalkInCustomerSeeder');
        return self::FAILURE;
    }
}
