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

        // [ONB-06 F-05 2026-08-27] Filtrer par la GARDE du role.
        //
        // `Permission::all()` ramassait aussi les permissions declarees sur la garde
        // `web`. La migration 2026_08_13_190000 cree volontairement `pos-flyer-print`
        // sur les DEUX gardes — certaines routes caisse passent par une session web, et
        // un accord sanctum seul y produirait des 403. Ce doublon est donc CORRECT.
        //
        // Mais l'accorder a un role `sanctum` fait lever GuardDoesNotMatch a Spatie. Le
        // defaut etait masque par un autre : le seeder de permissions echouait avant
        // d'arriver ici. En le rendant rejouable, celui-ci est apparu.
        $adminRole?->givePermissionTo(
            Permission::where('guard_name', $adminRole->guard_name)->get()
        );

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
                Permission::whereIn('name', $branchManagerPermissionNames)
                    ->where('guard_name', $branchManager->guard_name)
                    ->get()
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
                Permission::whereIn('name', $posOperatorManagerPermissionNames)
                    ->where('guard_name', $posOperatorManager->guard_name)
                    ->get()
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
                Permission::whereIn('name', $chefPermissionNames)
                    ->where('guard_name', $chef->guard_name)
                    ->get()
            );
        }

        // [GAP-19-5] POS Operator also needs KDS + OSS visibility.
        // In a small restaurant (Le Cayenne), the cashier monitors the kitchen
        // and the order status screen directly from the POS station.
        // [GAP-29-1] FIX: whereIn expects scalar strings, not associative arrays
        $posOperatorManager = SpatieRoleLookup::byLegacyId(EnumRole::POS_OPERATOR);
        if ($posOperatorManager) {
            // [ONB-06 F-05 2026-08-27] Filtre de garde, par coherence : aucune de ces
            // permissions n'a de doublon `web` aujourd'hui, donc rien ne casse — mais le
            // motif du double accord existe (migration 2026_08_13_190000) et se
            // reproduira. Mieux vaut aligner les six attributions que d'en laisser trois
            // attendre leur tour.
            $extraPermissions = Permission::whereIn('name', [
                'kitchen-display-system',
                'order-status-screen',
            ])->where('guard_name', $posOperatorManager->guard_name)->get();
            $posOperatorManager->givePermissionTo($extraPermissions);
        }

        // [GAP-19-5] Stuff role had zero permissions — blocked after login.
        // Stuff = floor staff (runners, helpers). They need KDS read access
        // to see which orders are ready to serve, and the OSS to track status.
        // [GAP-29-1] FIX: whereIn expects scalar strings, not associative arrays
        $stuff = SpatieRoleLookup::byLegacyId(EnumRole::STUFF);
        if ($stuff) {
            $stuffPermissions = Permission::whereIn('name', [
                'dashboard',
                'kitchen-display-system',
                'order-status-screen',
            ])->where('guard_name', $stuff->guard_name)->get();
            $stuff->givePermissionTo($stuffPermissions);
        }

        // [GAP-19-5] Waiter role — needs table orders + KDS + OSS visibility.
        // [GAP-29-1] FIX: whereIn expects scalar strings, not associative arrays
        $waiter = SpatieRoleLookup::byLegacyId(EnumRole::WAITER);
        if ($waiter) {
            $waiterPermissions = Permission::whereIn('name', [
                'dashboard',
                'table-orders',
                'kitchen-display-system',
                'order-status-screen',
            ])->where('guard_name', $waiter->guard_name)->get();
            $waiter->givePermissionTo($waiterPermissions);
        }
    }
}
