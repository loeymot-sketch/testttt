<?php

namespace Database\Seeders;

use App\Enums\Ask;
use App\Enums\Role as EnumRole;
use App\Enums\Status;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

/**
 * [POS-3] Walk-in customer mitigation seeder.
 *
 * Garantit l'existence du client « Client passage » (walking customer) utilisé
 * par le POS quand le caissier n'identifie pas le client. Sans ce row, la
 * résolution `PosComponent.vue` retombe sur un fallback non-déterministe et
 * peut attribuer une commande anonyme à un vrai client (fuite d'historique).
 *
 * Convention projet (non-négociable, alignée avec UserTableSeeder + PosComponent):
 *   - 1 seul client global, branch_id=0
 *   - email = 'walkingcustomer@example.com' (résolution frontend exacte)
 *   - name = 'Client passage' (sans accent volontairement, fallback regex 'walking%')
 *   - rôle Spatie = Customer (id=2 dans le RoleTableSeeder)
 *   - status = Status::ACTIVE
 *
 * Ce seeder est idempotent (firstOrCreate + role check) et doit pouvoir tourner
 * en production (contrairement à UserTableSeeder qui est blocked in prod).
 */
class WalkInCustomerSeeder extends Seeder
{
    public const WALKING_EMAIL = 'walkingcustomer@example.com';
    public const WALKING_NAME  = 'Client passage';

    public function run(): void
    {
        $role = Role::query()
            ->where('name', 'Customer')
            ->where('guard_name', 'sanctum')
            ->first();

        if (! $role) {
            $this->command?->error('[WalkInCustomerSeeder] Rôle Spatie « Customer » introuvable. Lancez RoleTableSeeder d\'abord.');
            return;
        }

        // [POS-3] withoutGlobalScopes → BranchScope ne doit pas masquer le client global.
        $customer = User::withoutGlobalScopes()
            ->where('email', self::WALKING_EMAIL)
            ->first();

        if (! $customer) {
            $customer = User::create([
                'name'              => self::WALKING_NAME,
                'email'             => self::WALKING_EMAIL,
                'phone'             => '0600000001',
                'username'          => 'default-customer',
                'email_verified_at' => now(),
                // Mot de passe aléatoire non-récupérable : ce compte n'est jamais loggué,
                // il sert uniquement de cible pour customer_id sur les commandes anonymes.
                'password'          => Hash::make(Str::random(64)),
                'branch_id'         => 0,
                'status'            => Status::ACTIVE,
                'country_code'      => '+33',
                'is_guest'          => Ask::NO,
            ]);
            $this->command?->info('[WalkInCustomerSeeder] Walking customer créé (id='.$customer->id.').');
        } else {
            $this->command?->info('[WalkInCustomerSeeder] Walking customer existe déjà (id='.$customer->id.').');
        }

        if (! $customer->hasRole($role)) {
            $customer->assignRole($role);
            $this->command?->info('[WalkInCustomerSeeder] Rôle Customer assigné.');
        }
    }
}
