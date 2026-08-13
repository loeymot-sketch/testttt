<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * [OWNER 2026-08-13 « ameliore l'acces du caissier »] `PromoFlyerController::store/reprint/revoke`
 * (le ticket promo imprime pour les commandes plateformes/Uber depuis le comptoir) est verrouille
 * derriere `coupons_create|settings` (commits a4b9a2b46 puis a5622d47, 2026-08-07/09) — un vrai
 * garde-fou contre un caissier qui frapperait des codes -10% sans limite. Mais NI la caisse
 * (POS Operator) NI le gerant de branche ne portent cette permission : seul l'Admin peut
 * l'utiliser. Le bouton "Ticket promo" du tracker caisse (PosOrdersTrackerComponent.vue) est donc
 * visible et cliquable, et repond 403 — exactement le symptome remonte.
 *
 * On cree une permission DEDIEE et etroite, `pos-flyer-print`, plutot que d'ouvrir `coupons_create`
 * (qui deverrouillerait aussi la creation de coupons generiques via CouponController). Le risque de
 * mint illimite que `coupons_create` bloquait est repris par un plafond applicatif
 * (PromoFlyerService::create, voir PromoFlyerDailyCapTest) au lieu d'un blocage total du role.
 *
 * Grant sur les DEUX guards (sanctum API + web session) — meme raison que les migrations
 * similaires (2026_07_15_170000_...) : les pages Vue admin appellent /api/admin/* via le cookie
 * de session (web guard), un grant sanctum-only produirait des 403.
 */
return new class extends Migration
{
    private const PERMISSION = 'pos-flyer-print';

    private const ROLES = ['POS Operator', 'Branch Manager'];

    public function up(): void
    {
        foreach (['sanctum', 'web'] as $guard) {
            $permission = Permission::updateOrCreate([
                'name' => self::PERMISSION,
                'guard_name' => $guard,
            ], [
                'title' => 'Ticket promo (creer / reimprimer / annuler)',
                'url' => 'pos-flyer-print',
            ]);

            foreach (self::ROLES as $roleName) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', $guard)
                    ->first();

                if ($role && ! $role->hasPermissionTo($permission)) {
                    $role->givePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        foreach (['sanctum', 'web'] as $guard) {
            $permission = Permission::query()
                ->where('name', self::PERMISSION)
                ->where('guard_name', $guard)
                ->first();

            if (! $permission) {
                continue;
            }

            foreach (self::ROLES as $roleName) {
                $role = Role::query()
                    ->where('name', $roleName)
                    ->where('guard_name', $guard)
                    ->first();

                if ($role && $role->hasPermissionTo($permission)) {
                    $role->revokePermissionTo($permission);
                }
            }
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
};
