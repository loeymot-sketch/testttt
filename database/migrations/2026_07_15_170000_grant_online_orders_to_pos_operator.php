<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * [SYNC-WEB-RBAC / SEED-A 2026-07-15] Accorde la permission `online-orders` au rôle
 * « POS Operator » (la caisse). Sans elle, le panneau « Commandes web » de la caisse
 * était INVISIBLE au caissier (v-if canProcessWebOrders) → les commandes du site web
 * n'apparaissaient jamais en caisse, alors que c'est exactement le rôle qui doit les
 * traiter/encaisser. Le déploiement lance `migrate` (pas `db:seed`), d'où une migration
 * de données idempotente plutôt qu'un seeder.
 *
 * Grant sur les DEUX guards (sanctum API + web session) — même raison que
 * AvailabilityTogglePermissionSeeder : les pages Vue admin appellent /api/admin/* via le
 * cookie de session (web guard), un grant sanctum-only produirait des 403.
 */
return new class extends Migration
{
    public function up(): void
    {
        foreach (['sanctum', 'web'] as $guard) {
            $permission = Permission::query()
                ->where('name', 'online-orders')
                ->where('guard_name', $guard)
                ->first();

            $role = Role::query()
                ->where('name', 'POS Operator')
                ->where('guard_name', $guard)
                ->first();

            if ($permission && $role && ! $role->hasPermissionTo($permission)) {
                $role->givePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['sanctum', 'web'] as $guard) {
            $permission = Permission::query()
                ->where('name', 'online-orders')
                ->where('guard_name', $guard)
                ->first();

            $role = Role::query()
                ->where('name', 'POS Operator')
                ->where('guard_name', $guard)
                ->first();

            if ($permission && $role && $role->hasPermissionTo($permission)) {
                $role->revokePermissionTo($permission);
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
