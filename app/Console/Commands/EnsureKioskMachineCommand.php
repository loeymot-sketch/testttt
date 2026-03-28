<?php

namespace App\Console\Commands;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\KioskMachine;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;

/**
 * Réaligne une borne « kiosk-lecayenne » (ou autre username) avec un mot de passe connu,
 * active la machine, et vérifie l’utilisateur lié — utile quand le seed n’a pas tourné
 * ou après un changement de mot de passe admin oublié côté borne.
 *
 * Ne remplace pas la configuration .env : pour le démarrage sans formulaire, gardez
 * KIOSK_MACHINE_* / KIOSK_DEFAULT_MACHINE_* alignés sur ce mot de passe.
 */
class EnsureKioskMachineCommand extends Command
{
    protected $signature = 'foodking:ensure-kiosk-machine
                            {--username=kiosk-lecayenne : Nom d’utilisateur machine (colonne kiosk_machines.username)}
                            {--password=kiosk123 : Mot de passe machine (min. 6 caractères)}
                            {--user-id= : user_id explicite (sinon premier utilisateur id=1 ou premier admin)}
                            {--branch-id= : branch_id explicite (sinon première branche)}
                            {--dry-run : Afficher sans modifier}
                            {--force : Sans confirmation en production (scripts / CI)}';

    protected $description = 'Crée ou met à jour une machine borne (hash mot de passe, statut actif) pour débloquer auth/kiosk-login';

    public function handle(): int
    {
        if (app()->environment('production') && ! $this->option('force') && ! $this->confirm('Environnement production : continuer la mise à jour du mot de passe borne ?')) {
            return 1;
        }

        $username = trim((string) $this->option('username'));
        $password = (string) $this->option('password');
        $dryRun   = (bool) $this->option('dry-run');

        if (strlen($password) < 6) {
            $this->error('Le mot de passe borne doit faire au moins 6 caractères (règle API kiosk-login).');

            return 1;
        }

        $userId = $this->option('user-id');
        if ($userId !== null && $userId !== '') {
            $user = User::query()->find((int) $userId);
        } else {
            $user = User::query()->find(1)
                ?? User::query()->orderBy('id')->first();
        }

        if (! $user) {
            $this->error('Aucun utilisateur trouvé pour lier la borne. Créez un admin ou passez --user-id=');

            return 1;
        }

        $branchId = $this->option('branch-id');
        if ($branchId !== null && $branchId !== '') {
            $branch = Branch::query()->find((int) $branchId);
        } else {
            $branch = Branch::query()->orderBy('id')->first();
        }

        if (! $branch) {
            $this->error('Aucune branche en base. Exécutez les migrations / seeders (BranchTableSeeder).');

            return 1;
        }

        $machine = KioskMachine::query()->where('username', $username)->first();

        if ($dryRun) {
            $this->info('[dry-run] Utilisateur lié : '.$user->id.' ('.$user->email.')');
            $this->info('[dry-run] Branche : '.$branch->id);
            $this->info('[dry-run] Machine : '.($machine ? 'mise à jour #'.$machine->id : 'création'));

            return 0;
        }

        if ($machine) {
            $machine->update([
                'user_id'   => $user->id,
                'branch_id' => $branch->id,
                'password'  => Hash::make($password),
                'status'    => Status::ACTIVE,
            ]);
            $this->info("Borne « {$username} » mise à jour (id={$machine->id}), statut actif, mot de passe réinitialisé.");
        } else {
            $machine = KioskMachine::query()->create([
                'user_id'    => $user->id,
                'branch_id'  => $branch->id,
                'machine_id' => 'CLI-'.strtoupper(substr(sha1($username), 0, 10)),
                'username'   => $username,
                'password'   => Hash::make($password),
                'is_login'   => Ask::NO,
                'status'     => Status::ACTIVE,
            ]);
            $this->info("Borne « {$username} » créée (id={$machine->id}).");
        }

        $this->line('Connectez la borne avec ce mot de passe, ou définissez dans .env :');
        $this->line('  KIOSK_MACHINE_USERNAME='.$username);
        $this->line('  KIOSK_MACHINE_PASSWORD='.$password);
        $this->line('Puis : php artisan config:clear');

        return 0;
    }
}
