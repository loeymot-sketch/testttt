<?php

namespace App\Console\Commands;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Smartisan\Settings\Facades\Settings;
use Spatie\Permission\Models\Role;

/**
 * Garantit un compte administrateur joignable avec l’email documenté (Le Cayenne).
 * Les bases seedées avant UserTableSeeder « Le Cayenne » ont souvent admin@example.com
 * → login admin@lecayenne.fr échoue avec « credentials_invalid ».
 */
class EnsureAdminLoginCommand extends Command
{
    protected $signature = 'foodking:ensure-admin
                            {--email=admin@lecayenne.fr : Email administrateur}
                            {--password=123456 : Mot de passe (dev uniquement)}
                            {--dry-run : Afficher sans modifier}
                            {--force : Autoriser l\'exécution en production (intention explicite)}';

    protected $description = 'Crée ou met à jour l’admin (email + mot de passe + statut actif + rôle Admin) pour débloquer le login';

    public function handle(): int
    {
        // [GOAL CONSOLIDATION_V1_PRODUCTION_20260825 — T-5.3.1] Garde de production.
        //
        // Cette commande CRÉE ou RÉINITIALISE un compte administrateur, avec un mot de passe
        // dont le défaut est `123456`. Sans garde, un `php artisan foodking:ensure-admin` lancé
        // par réflexe — ou par un script de déploiement hérité — pose sur la machine qui sert un
        // administrateur joignable avec un mot de passe connu de tous.
        //
        // Même failure-mode explicite que les gardes d'amorçage d'AppServiceProvider (CLAUDE.md
        // §8) : on refuse bruyamment plutôt que d'agir en silence. `--dry-run` ne lève PAS la
        // garde : l'autoriser entretiendrait l'habitude de lancer cette commande en production.
        if (app()->environment('production') && ! $this->option('force')) {
            $this->error(
                'REFUS : foodking:ensure-admin est bloquée en production. Cette commande crée ou '
                .'réinitialise un compte administrateur (mot de passe par défaut : 123456), ce qui '
                .'équivaut à une élévation de privilège en une commande.'
            );
            $this->line('');
            $this->warn(
                'Si l\'intention est délibérée : relancez avec --force ET un --password explicite. '
                .'Tracez la raison dans le journal d\'exploitation.'
            );

            return 1;
        }

        $email    = (string) $this->option('email');
        $password = (string) $this->option('password');
        $dry      = (bool) $this->option('dry-run');

        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('Email invalide.');

            return 1;
        }

        if (strlen($password) < 6) {
            $this->error('Mot de passe minimum 6 caractères (règle LoginController).');

            return 1;
        }

        // Bases partiellement migrées (ex. E2E / fixtures) : créer le rôle minimal plutôt qu’échouer.
        $role = Role::firstOrCreate(
            ['name' => 'Admin', 'guard_name' => 'sanctum'],
            []
        );
        if ($role->wasRecentlyCreated) {
            $this->warn('Rôle Spatie « Admin » créé (aucune entrée préalable — pense à RoleTableSeeder en prod complète).');
        }

        $other = User::withoutGlobalScopes()
            ->where('email', $email)
            ->first();

        $admin = $other;

        if (! $admin) {
            $admin = User::withoutGlobalScopes()
                ->whereHas('roles', fn ($q) => $q->where('name', 'Admin'))
                ->orderBy('id')
                ->first();
        }

        if (! $admin) {
            $admin = User::withoutGlobalScopes()->orderBy('id')->first();
        }

        if (! $admin) {
            if ($dry) {
                $this->warn('[dry-run] Aucun utilisateur : création prévue de '.$email);

                return 0;
            }

            $branchId = $this->resolveAssignableBranchId();
            $admin = User::create([
                'name'              => 'Admin Le Cayenne',
                'email'             => $email,
                'phone'             => '0600000000',
                'username'          => 'admin',
                'email_verified_at' => now(),
                'password'          => Hash::make($password),
                'branch_id'         => $branchId,
                'status'            => Status::ACTIVE,
                'country_code'      => '+33',
                'is_guest'          => Ask::NO,
            ]);
            $admin->assignRole($role);
            $this->info("Utilisateur créé : id={$admin->id} email={$email} branch_id={$branchId}");

            return 0;
        }

        $conflict = User::withoutGlobalScopes()
            ->where('email', $email)
            ->where('id', '!=', $admin->id)
            ->exists();

        if ($conflict) {
            $this->error("Un autre utilisateur utilise déjà l’email {$email}. Corrige manuellement en base.");

            return 1;
        }

        $this->table(
            ['Champ', 'Avant', 'Après'],
            [
                ['id', (string) $admin->id, (string) $admin->id],
                ['email', (string) $admin->email, $email],
                ['status', (string) $admin->status, (string) Status::ACTIVE],
                ['deleted_at', $admin->deleted_at ? $admin->deleted_at->toDateTimeString() : '(null)', '(null)'],
                ['password', '(hash)', '(nouveau hash)'],
            ]
        );

        if ($dry) {
            $this->warn('Dry-run : aucune modification.');

            return 0;
        }

        $admin->email             = $email;
        $admin->password          = Hash::make($password);
        $admin->status            = Status::ACTIVE;
        $admin->deleted_at        = null;
        $admin->email_verified_at = $admin->email_verified_at ?? now();
        if ((int) $admin->branch_id === 0) {
            $admin->branch_id = $this->resolveAssignableBranchId();
        }
        $admin->save();

        if (! $admin->hasRole('Admin')) {
            $admin->assignRole($role);
            $this->info('Rôle Admin assigné.');
        }

        $this->info("OK — connecte-toi avec : {$email} / (mot de passe défini par --password)");

        return 0;
    }

    /**
     * Évite branch_id=0 + site_default_branch absent → default_access.default_id NULL au login.
     */
    private function resolveAssignableBranchId(): int
    {
        $fromSettings = Settings::group('site')->get('site_default_branch');
        $id = (int) ($fromSettings ?: 0);
        if ($id > 0) {
            return $id;
        }

        $first = Branch::query()->orderBy('id')->value('id');

        return (int) ($first ?: 1);
    }
}
