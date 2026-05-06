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
 * Garantit un compte caissier POS joignable (E2E / bases partiellement seedées).
 * Complète {@see EnsureAdminLoginCommand} : le global-setup Playwright aligne l’admin
 * mais pas forcément pos@… — d’où des 400 « credentials_invalid » sur tests/e2e/02-pos-*.spec.js.
 */
class EnsurePosOperatorLoginCommand extends Command
{
    protected $signature = 'foodking:ensure-pos-operator
                            {--email=pos@lecayenne.fr : Email opérateur POS}
                            {--password=123456 : Mot de passe (dev uniquement)}
                            {--dry-run : Afficher sans modifier}';

    protected $description = 'Crée ou met à jour le compte caissier POS (mot de passe, branche valide, rôle POS Operator, default_access)';

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
            ['name' => 'POS Operator', 'guard_name' => 'sanctum'],
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
                'name'              => 'Caissier',
                'email'             => $email,
                'phone'             => '0600000002',
                'username'          => 'pos-'.substr(sha1($email), 0, 12),
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
            $this->ensureBranchHasGeoForDeliveryQuotes($branchId);
            $this->syncPosOperatorRolePermissions();
            $this->info("Utilisateur POS créé : id={$user->id} email={$email} branch_id={$branchId}");

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

        if (! $user->hasRole('POS Operator')) {
            $user->assignRole($role);
            $this->info('Rôle POS Operator assigné.');
        }

        DefaultAccess::updateOrCreate(
            ['user_id' => $user->id, 'name' => 'branch_id'],
            ['default_id' => $resolvedBranch]
        );

        $this->ensureBranchHasGeoForDeliveryQuotes($resolvedBranch);
        $this->syncPosOperatorRolePermissions();

        $this->info("OK — connecte-toi avec : {$email} / (mot de passe défini par --password)");

        return 0;
    }

    /**
     * Les quotes livraison POS (tests C2 / Feature) exigent lat/lon branche pour recalcul frais.
     */
    private function ensureBranchHasGeoForDeliveryQuotes(int $branchId): void
    {
        if ($branchId <= 0) {
            return;
        }
        $branch = Branch::query()->find($branchId);
        if (! $branch) {
            return;
        }
        $lat = trim((string) ($branch->latitude ?? ''));
        $lng = trim((string) ($branch->longitude ?? ''));
        if ($lat === '' || $lng === '') {
            $branch->forceFill([
                'latitude'  => '48.8566',
                'longitude' => '2.3522',
            ])->save();
        }
    }

    /**
     * Ré-attache les permissions au rôle POS Operator (le login API n’expose que role_has_permissions).
     */
    private function syncPosOperatorRolePermissions(): void
    {
        $role = Role::query()->where('name', 'POS Operator')->where('guard_name', 'sanctum')->first();
        if (! $role) {
            return;
        }
        $names = [
            'dashboard',
            'pos',
            'pos-orders',
            'pos-discount-up-to-10',
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
