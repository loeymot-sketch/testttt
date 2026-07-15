<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * [GOAL RUPTURE-CARNET 2026-07-15 / W1] Permission dédiée `availability_toggle` :
 * permet à la caisse (POS Operator) et à la cuisine (Chef) de marquer un produit
 * en rupture (86) / le réactiver, SANS leur donner `items_edit` (édition complète
 * du catalogue : prix, compositions — surface trop large, NF525-adjacente).
 *
 * Grant sur les DEUX guards (sanctum API + web session) — même raison que
 * IngredientPermissionSeeder : les pages Vue admin appellent /api/admin/* via le
 * cookie de session (web guard), un grant sanctum-only produirait des 403.
 */
class AvailabilityTogglePermissionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (['sanctum', 'web'] as $guard) {
            // `title`/`url` : colonnes custom du modèle Permission de ce repo.
            // [W6 heal P1] updateOrCreate (PAS firstOrCreate) : des lignes
            // préexistantes avec title/url NULL ne seraient JAMAIS réparées par
            // firstOrCreate (defaults appliqués à la création seulement) → le
            // matcher UI ne voyait pas la permission. Le seeder est désormais
            // convergent : re-run = backfill garanti.
            $permission = Permission::updateOrCreate([
                'name' => 'availability_toggle',
                'guard_name' => $guard,
            ], [
                'title' => 'Rupture produits (86)',
                'url' => 'availability-toggle',
            ]);

            foreach (['Admin', 'Branch Manager', 'POS Operator', 'Chef'] as $roleName) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', $guard)
                    ->first();

                if ($role) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        // Spatie met en cache les lookups role/permission — sans flush, la nouvelle
        // permission est invisible jusqu'au prochain cache reset (403 transitoire).
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
