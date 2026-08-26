<?php

namespace Database\Seeders;

use App\Enums\Role as EnumRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;

class RolePermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $adminRole = SpatieRoleLookup::byLegacyId(EnumRole::ADMIN);
        $adminRole?->givePermissionTo($this->permissionsForRole($adminRole));

        // [POS-9-H.1.2] F-A2 fix: whereIn('name', ...) expects a flat list of strings.
        // The previous `['name' => 'x']` shape matched 0 rows silently — Branch Manager,
        // POS Operator, Chef were effectively left with zero permissions by this seeder
        // (only Admin worked via Permission::all() above).
        $branchManager = SpatieRoleLookup::byLegacyId(EnumRole::BRANCH_MANAGER);
        if ($branchManager) {
            $branchManagerPermissionNames = [
                'dashboard',
                'dining-tables',
                'pos',
                'pos-orders',
                // [POS-9.1.1] branch manager = 10%-50% discount
                'pos-discount-up-to-10',
                'pos-discount-over-10-requires-manager',
                // [POS-9.4.12] Branch managers drive the daily fiscal close.
                'pos-manage-fiscal',
                'pos-reopen-z',
                'online-orders',
                'table-orders',
                'kitchen-display-system',
                'order-status-screen',
                'push-notifications',
                'push-notifications_create',
                'push-notifications_edit',
                'push-notifications_delete',
                'push-notifications_show',
                'messages',
                'delivery-boys',
                'delivery-boys_create',
                'delivery-boys_edit',
                'delivery-boys_delete',
                'delivery-boys_show',
                'customers',
                'customers_create',
                'customers_edit',
                'customers_delete',
                'customers_show',
                'employees',
                'employees_create',
                'employees_edit',
                'employees_delete',
                'employees_show',
                'waiters',
                'waiters_create',
                'waiters_edit',
                'waiters_delete',
                'waiters_show',
                'chefs',
                'chefs_create',
                'chefs_edit',
                'chefs_delete',
                'chefs_show',
                'transactions',
                'sales-report',
                // [Wave O — O4 2026-05-20] Branch Manager voit le rapport des
                // caisses quotidiennes (scoped à sa branche via BranchScope).
                'cash-sessions-report',
                // [Sprint 1D / F-4] Branch Manager may approve cash variance
                // beyond the configured threshold (default 2€). Cashiers
                // (POS Operator) must escalate to a manager.
                'cash.reconcile.variance.override',
                // [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] Branch Manager can
                // apply customer loyalty redemption from POS cashier UI.
                'pos.redeem-loyalty',
                // [HEAL-4 / PROPOSAL-02 — V101-02 2026-05-26] Branch Manager can
                // issue NF525 counter-entry refunds via the new PosRefundModal UI.
                // Admin gets this via Permission::all() above. POS Operator does
                // NOT get it by default (mass-refund vector mitigation).
                'pos-refund',
                // [OWNER 2026-08-13 « ameliore l'acces du caissier »] Ticket promo
                // plateformes/Uber : creer / reimprimer / annuler depuis le comptoir.
                'pos-flyer-print',
            ];
            $branchManager->givePermissionTo(
                $this->permissionsForRole($branchManager, $branchManagerPermissionNames)
            );
        }

        $posOperatorManager = SpatieRoleLookup::byLegacyId(EnumRole::POS_OPERATOR);
        if ($posOperatorManager) {
            $posOperatorManagerPermissionNames = [
                'dashboard',
                'pos',
                'pos-orders',
                // [POS-9.1.1] cashier = up-to-10% discount
                'pos-discount-up-to-10',
                // [LOCK_POS_LOYALTY_REDEEM_UI 2026-05-19] Cashier-facing
                // loyalty redemption gate (LOCK §6.1).
                'pos.redeem-loyalty',
                // [WEB-ORDER-ACCEPT 2026-07-30 · décision owner] Le caissier accepte/gère les
                // commandes du SITE depuis la caisse (mono-resto : c'est lui au comptoir). Sans
                // cette permission le bouton « Accepter » du tracker était mort (403). Le REFUND
                // reste gardé `pos-refund` (NON accordé ici) → le caissier accepte + encaisse mais
                // ne peut pas rembourser seul. Cf. OnlineOrderController::changeStatus.
                'online-orders',
                // [OWNER 2026-08-13 « ameliore l'acces du caissier »] Ticket promo
                // plateformes/Uber : creer / reimprimer / annuler depuis le comptoir. Voir
                // PromoFlyerController et migration 2026_08_13_190000_grant_pos_flyer_print_to_cashier
                // — un plafond quotidien (PromoFlyerService::DAILY_CAP_PER_USER) remplace le
                // blocage total de role qui existait avant.
                'pos-flyer-print',
            ];
            $posOperatorManager->givePermissionTo(
                $this->permissionsForRole($posOperatorManager, $posOperatorManagerPermissionNames)
            );
        }

        $chef = SpatieRoleLookup::byLegacyId(EnumRole::CHEF);
        if ($chef) {
            $chefPermissionNames = [
                'dashboard',
                'kitchen-display-system',
                'order-status-screen',
            ];
            $chef->givePermissionTo(
                $this->permissionsForRole($chef, $chefPermissionNames)
            );
        }

        // [GAP-19-5] POS Operator also needs KDS + OSS visibility.
        // In a small restaurant (Le Cayenne), the cashier monitors the kitchen
        // and the order status screen directly from the POS station.
        // [GAP-29-1] FIX: whereIn expects scalar strings, not associative arrays
        $posOperatorManager = SpatieRoleLookup::byLegacyId(EnumRole::POS_OPERATOR);
        if ($posOperatorManager) {
            $extraPermissions = $this->permissionsForRole($posOperatorManager, [
                'kitchen-display-system',
                'order-status-screen',
            ]);
            $posOperatorManager->givePermissionTo($extraPermissions);
        }

        // [GAP-19-5] Stuff role had zero permissions — blocked after login.
        // Stuff = floor staff (runners, helpers). They need KDS read access
        // to see which orders are ready to serve, and the OSS to track status.
        // [GAP-29-1] FIX: whereIn expects scalar strings, not associative arrays
        $stuff = SpatieRoleLookup::byLegacyId(EnumRole::STUFF);
        if ($stuff) {
            $stuffPermissions = $this->permissionsForRole($stuff, [
                'dashboard',
                'kitchen-display-system',
                'order-status-screen',
            ]);
            $stuff->givePermissionTo($stuffPermissions);
        }

        // [GAP-19-5] Waiter role — needs table orders + KDS + OSS visibility.
        // [GAP-29-1] FIX: whereIn expects scalar strings, not associative arrays
        $waiter = SpatieRoleLookup::byLegacyId(EnumRole::WAITER);
        if ($waiter) {
            $waiterPermissions = $this->permissionsForRole($waiter, [
                'dashboard',
                'table-orders',
                'kitchen-display-system',
                'order-status-screen',
            ]);
            $waiter->givePermissionTo($waiterPermissions);
        }
    }

    /**
     * Permissions du MÊME guard que le rôle visé.
     *
     * [FIX 2026-08-25] Aucune de ces requêtes ne filtrait `guard_name`. Tant que toutes les
     * permissions vivaient sur `sanctum`, ça passait. Depuis que des migrations en créent
     * aussi sur le guard `web` — `2026_08_13_190000_grant_pos_flyer_print_to_cashier` et
     * `2026_07_15_170000_grant_online_orders_to_pos_operator` le font explicitement, « les
     * pages Vue admin appellent /api/admin/* via le cookie de session » — un `whereIn('name')`
     * non filtré ramène les DEUX guards, et `givePermissionTo` lève `GuardDoesNotMatch` sur un
     * rôle `sanctum`.
     *
     * Conséquence réelle : `php artisan db:seed` échouait sur toute base déjà migrée. Trois
     * tests le signalaient sans que la cause remonte.
     *
     * @param  \Spatie\Permission\Models\Role  $role
     * @param  array<int, string>|null  $noms  null = toutes les permissions de ce guard
     * @return \Illuminate\Database\Eloquent\Collection
     */
    private function permissionsForRole($role, ?array $noms = null)
    {
        $requete = Permission::query()->where('guard_name', $role->guard_name);

        if ($noms !== null) {
            $requete->whereIn('name', $noms);
        }

        return $requete->get();
    }
}
