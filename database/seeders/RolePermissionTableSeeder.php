<?php

namespace Database\Seeders;

use App\Enums\Role as EnumRole;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;


class RolePermissionTableSeeder extends Seeder
{
    /**
     * Run the database seeds.
     *
     * @return void
     */
    public function run()
    {
        $adminRole = Role::find(EnumRole::ADMIN);
        $adminRole?->givePermissionTo(Permission::all());

        // [POS-9-H.1.2] F-A2 fix: whereIn('name', ...) expects a flat list of strings.
        // The previous `['name' => 'x']` shape matched 0 rows silently — Branch Manager,
        // POS Operator, Chef were effectively left with zero permissions by this seeder
        // (only Admin worked via Permission::all() above).
        $branchManager = Role::find(EnumRole::BRANCH_MANAGER);
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
            ];
            $branchManager->givePermissionTo(
                Permission::whereIn('name', $branchManagerPermissionNames)->get()
            );
        }

        $posOperatorManager = Role::find(EnumRole::POS_OPERATOR);
        if ($posOperatorManager) {
            $posOperatorManagerPermissionNames = [
                'dashboard',
                'pos',
                'pos-orders',
                // [POS-9.1.1] cashier = up-to-10% discount
                'pos-discount-up-to-10',
            ];
            $posOperatorManager->givePermissionTo(
                Permission::whereIn('name', $posOperatorManagerPermissionNames)->get()
            );
        }

        $chef = Role::find(EnumRole::CHEF);
        if ($chef) {
            $chefPermissionNames = [
                'dashboard',
                'kitchen-display-system',
                'order-status-screen',
            ];
            $chef->givePermissionTo(
                Permission::whereIn('name', $chefPermissionNames)->get()
            );
        }

        // [GAP-19-5] POS Operator also needs KDS + OSS visibility.
        // In a small restaurant (Le Cayenne), the cashier monitors the kitchen
        // and the order status screen directly from the POS station.
        // [GAP-29-1] FIX: whereIn expects scalar strings, not associative arrays
        $posOperatorManager = Role::find(EnumRole::POS_OPERATOR);
        if ($posOperatorManager) {
            $extraPermissions = Permission::whereIn('name', [
                'kitchen-display-system',
                'order-status-screen',
            ])->get();
            $posOperatorManager->givePermissionTo($extraPermissions);
        }

        // [GAP-19-5] Stuff role had zero permissions — blocked after login.
        // Stuff = floor staff (runners, helpers). They need KDS read access
        // to see which orders are ready to serve, and the OSS to track status.
        // [GAP-29-1] FIX: whereIn expects scalar strings, not associative arrays
        $stuff = Role::find(EnumRole::STUFF);
        if ($stuff) {
            $stuffPermissions = Permission::whereIn('name', [
                'dashboard',
                'kitchen-display-system',
                'order-status-screen',
            ])->get();
            $stuff->givePermissionTo($stuffPermissions);
        }

        // [GAP-19-5] Waiter role — needs table orders + KDS + OSS visibility.
        // [GAP-29-1] FIX: whereIn expects scalar strings, not associative arrays
        $waiter = Role::find(EnumRole::WAITER);
        if ($waiter) {
            $waiterPermissions = Permission::whereIn('name', [
                'dashboard',
                'table-orders',
                'kitchen-display-system',
                'order-status-screen',
            ])->get();
            $waiter->givePermissionTo($waiterPermissions);
        }
    }
}