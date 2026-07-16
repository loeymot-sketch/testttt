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

        // Branche résolue AVANT l'utilisateur, pour pouvoir provisionner un owner borne dédié
        // sur la bonne branche.
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

        // [TERRAIN-HEAL 2026-07-16 · KIOSK-PROFILE-ESCALATION couche-2] L'owner de la borne détermine
        // le compte sur lequel le token kiosk:order est émis. L'ancien défaut (User::find(1) = admin)
        // faisait qu'un token borne fuité portait un compte PRIVILÉGIÉ = amplificateur du P1 /profile.
        // Défense en profondeur : par défaut on lie à un utilisateur DÉDIÉ SANS RÔLE (même si un garde
        // couche-1 tombait, le token n'a aucun privilège Spatie). --user-id explicite reste respecté,
        // mais on AVERTIT s'il pointe un compte privilégié.
        $userId = $this->option('user-id');
        if ($userId !== null && $userId !== '') {
            $user = User::query()->find((int) $userId);
            if ($user && ($user->hasRole('Admin') || $user->can('settings'))) {
                $this->warn("⚠ Borne liée à un compte PRIVILÉGIÉ ({$user->email}). Recommandé : un user dédié sans rôle (ne pas passer --user-id).");
            }
        } else {
            $user = $this->ensureDedicatedKioskOwner($branch);
            $this->info("Owner borne dédié (sans rôle) : {$user->email}");
        }

        if (! $user) {
            $this->error('Aucun utilisateur trouvé pour lier la borne. Créez un admin ou passez --user-id=');

            return 1;
        }

        // [iter12 P1 KIOSK 2026-05-09] CLI context (admin-only command):
        // Auth is null in console, BranchScope bails out automatically, but
        // we mark intent with withoutGlobalScope so future BranchScope changes
        // (e.g., apply on console) don't break the command silently.
        $machine = KioskMachine::withoutGlobalScope(\App\Models\Scopes\BranchScope::class)
            ->where('username', $username)
            ->first();

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

    /**
     * [KIOSK-PROFILE-ESCALATION couche-2] Garantit un owner borne DÉDIÉ et SANS RÔLE pour une branche.
     * Le token kiosk:order est émis sur ce user ; sans rôle Spatie ni permission, un token fuité ne
     * porte aucun privilège (défense en profondeur derrière les gardes couche-1 block_kiosk_token_admin
     * + block_kiosk_machine_profile). Idempotent (firstOrCreate par email), mot de passe aléatoire
     * (le login borne se fait par les creds KioskMachine, pas par ce user).
     */
    private function ensureDedicatedKioskOwner(Branch $branch): User
    {
        $email = "kiosk-borne-b{$branch->id}@lecayenne.local";

        $user = User::query()->firstOrCreate(
            ['email' => $email],
            [
                'name'      => "Borne Le Cayenne (branche {$branch->id})",
                'username'  => "kiosk-borne-b{$branch->id}",
                'password'  => Hash::make(bin2hex(random_bytes(16))),
                'status'    => Status::ACTIVE,
                'branch_id' => $branch->id,
            ]
        );

        // Défense : garantir qu'aucun rôle n'est attaché (au cas où un ré-run l'aurait modifié).
        if (method_exists($user, 'syncRoles')) {
            $user->syncRoles([]);
        }

        return $user;
    }
}
