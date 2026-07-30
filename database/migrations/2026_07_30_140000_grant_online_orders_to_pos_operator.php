<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * [WEB-ORDER-ACCEPT 2026-07-30 · décision owner] Accorde la permission `online-orders` au rôle
 * « POS Operator » : le caissier accepte/gère les commandes du SITE directement depuis la caisse
 * (mono-resto Le Cayenne — c'est lui au comptoir). Sans cette permission, le bouton « Accepter »
 * du tracker de commandes était un bouton MORT (affiché mais POST → 403), défaut relevé par l'audit
 * adversaire « gestion commande site » 2026-07-30.
 *
 * Le REMBOURSEMENT reste gardé `pos-refund` (NON accordé ici) : le caissier accepte + encaisse mais
 * ne peut pas rembourser seul → frontière de permission saine (parité avec la garde POS sœur).
 *
 * Idempotent : re-run = no-op. Miroir permanent pour les installs neuves = RolePermissionTableSeeder.
 */
return new class extends Migration
{
    private const ROLE  = 'POS Operator';
    private const GUARD = 'sanctum';
    private const PERM  = 'online-orders';

    public function up(): void
    {
        $role = Role::where('name', self::ROLE)->where('guard_name', self::GUARD)->first();
        if (! $role) {
            return; // rôle non présent sur cette install → le seeder posera la permission à l'install
        }

        $perm = Permission::firstOrCreate(['name' => self::PERM, 'guard_name' => self::GUARD]);

        if (! $role->hasPermissionTo($perm)) {
            $role->givePermissionTo($perm);
        }

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        $role = Role::where('name', self::ROLE)->where('guard_name', self::GUARD)->first();
        $perm = Permission::where('name', self::PERM)->where('guard_name', self::GUARD)->first();

        if ($role && $perm && $role->hasPermissionTo($perm)) {
            $role->revokePermissionTo($perm);
            app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        }
    }
};
