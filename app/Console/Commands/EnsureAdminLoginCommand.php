<?php

namespace App\Console\Commands;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
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
                            {--dry-run : Afficher sans modifier}';

    protected $description = 'Crée ou met à jour l’admin (email + mot de passe + statut actif + rôle Admin) pour débloquer le login';

    public function handle(): int
    {
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

        $role = Role::query()
            ->where('name', 'Admin')
            ->where('guard_name', 'sanctum')
            ->first();

        if (! $role) {
            $this->error('Rôle Spatie « Admin » introuvable. Lancez d’abord les seeders (RoleTableSeeder).');

            return 1;
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

            $admin = User::create([
                'name'              => 'Admin Le Cayenne',
                'email'             => $email,
                'phone'             => '0600000000',
                'username'          => 'admin',
                'email_verified_at' => now(),
                'password'          => Hash::make($password),
                'branch_id'         => 0,
                'status'            => Status::ACTIVE,
                'country_code'      => '+33',
                'is_guest'          => Ask::NO,
            ]);
            $admin->assignRole($role);
            $this->info("Utilisateur créé : id={$admin->id} email={$email}");

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
        $admin->save();

        if (! $admin->hasRole('Admin')) {
            $admin->assignRole($role);
            $this->info('Rôle Admin assigné.');
        }

        $this->info("OK — connecte-toi avec : {$email} / (mot de passe défini par --password)");

        return 0;
    }
}
