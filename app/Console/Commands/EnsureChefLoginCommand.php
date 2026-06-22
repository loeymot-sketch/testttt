<?php

namespace App\Console\Commands;

use App\Enums\Ask;
use App\Enums\Status;
use App\Models\Branch;
use App\Models\DefaultAccess;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Smartisan\Settings\Facades\Settings;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Garantit le compte chef/KDS documenté (E2E) — même problème que POS après bases partielles.
 */
class EnsureChefLoginCommand extends Command
{
    protected $signature = 'foodking:ensure-chef-operator
                            {--email=chef@lecayenne.fr : Email chef / KDS}
                            {--password=123456 : Mot de passe (dev uniquement)}
                            {--dry-run : Afficher sans modifier}';

    protected $description = 'Crée ou met à jour le compte chef (mot de passe, branche valide, rôle Chef, default_access)';

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

        $role = Role::firstOrCreate(
            ['name' => 'Chef', 'guard_name' => 'sanctum'],
            []
        );

        $branchId = $this->resolveAssignableBranchId();

        $user = User::withoutGlobalScopes()->where('email', $email)->first();

        if (! $user) {
            if ($dry) {
                $this->warn("[dry-run] Aucun utilisateur : création prévue de {$email} branch_id={$branchId}");

                return 0;
            }

            $user = User::create([
                'name'              => 'Chef cuisine',
                'email'             => $email,
                'phone'             => '0600000003',
                'username'          => 'chef-'.substr(sha1($email), 0, 12),
                'email_verified_at' => now(),
                'password'          => Hash::make($password),
                'branch_id'         => $branchId,
                'status'            => Status::ACTIVE,
                'country_code'      => '+33',
                'is_guest'          => Ask::NO,
            ]);
            $user->assignRole($role);
            DefaultAccess::firstOrCreate(
                ['user_id' => $user->id, 'name' => 'branch_id'],
                ['default_id' => $branchId]
            );
            $this->syncChefRolePermissions();
            $this->info("Utilisateur Chef créé : id={$user->id} email={$email} branch_id={$branchId}");

            return 0;
        }

        $this->table(
            ['Champ', 'Avant', 'Après'],
            [
                ['id', (string) $user->id, (string) $user->id],
                ['email', (string) $user->email, $email],
                ['branch_id', (string) $user->branch_id, (string) $this->effectiveBranchIdForUser((int) $user->branch_id, $branchId)],
                ['status', (string) $user->status, (string) Status::ACTIVE],
                ['password', '(hash)', '(nouveau hash)'],
            ]
        );

        if ($dry) {
            $this->warn('Dry-run : aucune modification.');

            return 0;
        }

        $resolvedBranch = $this->effectiveBranchIdForUser((int) $user->branch_id, $branchId);

        $user->password           = Hash::make($password);
        $user->status             = Status::ACTIVE;
        $user->deleted_at         = null;
        $user->email_verified_at  = $user->email_verified_at ?? now();
        $user->branch_id          = $resolvedBranch;
        $user->save();

        if (! $user->hasRole('Chef')) {
            $user->assignRole($role);
            $this->info('Rôle Chef assigné.');
        }

        DefaultAccess::updateOrCreate(
            ['user_id' => $user->id, 'name' => 'branch_id'],
            ['default_id' => $resolvedBranch]
        );

        $this->syncChefRolePermissions();

        $this->info("OK — connecte-toi avec : {$email} / (mot de passe défini par --password)");

        return 0;
    }

    private function syncChefRolePermissions(): void
    {
        $role = Role::query()->where('name', 'Chef')->where('guard_name', 'sanctum')->first();
        if (! $role) {
            return;
        }
        $names = [
            'dashboard',
            'kitchen-display-system',
            'order-status-screen',
        ];
        $perms = Permission::query()
            ->where('guard_name', 'sanctum')
            ->whereIn('name', $names)
            ->get();
        if ($perms->isEmpty()) {
            return;
        }
        $role->givePermissionTo($perms);
    }

    private function effectiveBranchIdForUser(int $currentBranchId, int $fallbackBranchId): int
    {
        if ($currentBranchId > 0 && Branch::query()->whereKey($currentBranchId)->exists()) {
            return $currentBranchId;
        }

        return $fallbackBranchId;
    }

    private function resolveAssignableBranchId(): int
    {
        $fromSettings = Settings::group('site')->get('site_default_branch');
        $id           = (int) ($fromSettings ?: 0);
        if ($id > 0 && Branch::query()->whereKey($id)->exists()) {
            return $id;
        }

        $first = (int) (Branch::query()->orderBy('id')->value('id') ?: 0);
        if ($first > 0) {
            return $first;
        }

        return (int) Branch::factory()->create()->id;
    }
}
