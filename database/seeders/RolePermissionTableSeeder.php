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

        $branchManager = Role::find(EnumRole::BRANCH_MANAGER);
        if ($branchManager) {
            $branchManagerPermissions = [
                ['name' => 'dashboard'],
                ['name' => 'dining-tables'],
                ['name' => 'pos'],
                ['name' => 'pos-orders'],
                // [POS-9.1.1] branch manager = 10%-50% discount
                ['name' => 'pos-discount-up-to-10'],
                ['name' => 'pos-discount-over-10-requires-manager'],
                ['name' => 'online-orders'],
                ['name' => 'table-orders'],
                ['name' => 'kitchen-display-system'],
                ['name' => 'order-status-screen'],
                ['name' => 'push-notifications'],
                ['name' => 'push-notifications_create'],
                ['name' => 'push-notifications_edit'],
                ['name' => 'push-notifications_delete'],
                ['name' => 'push-notifications_show'],
                ['name' => 'messages'],
                ['name' => 'delivery-boys'],
                ['name' => 'delivery-boys_create'],
                ['name' => 'delivery-boys_edit'],
                ['name' => 'delivery-boys_delete'],
                ['name' => 'delivery-boys_show'],
                ['name' => 'customers'],
                ['name' => 'customers_create'],
                ['name' => 'customers_edit'],
                ['name' => 'customers_delete'],
                ['name' => 'customers_show'],
                ['name' => 'employees'],
                ['name' => 'employees_create'],
                ['name' => 'employees_edit'],
                ['name' => 'employees_delete'],
                ['name' => 'employees_show'],
                ['name' => 'waiters'],
                ['name' => 'waiters_create'],
                ['name' => 'waiters_edit'],
                ['name' => 'waiters_delete'],
                ['name' => 'waiters_show'],
                ['name' => 'chefs'],
                ['name' => 'chefs_create'],
                ['name' => 'chefs_edit'],
                ['name' => 'chefs_delete'],
                ['name' => 'chefs_show'],
                ['name' => 'transactions'],
                ['name' => 'sales-report']
            ];
            $branchManagerPermissions = Permission::whereIn('name', $branchManagerPermissions)->get();
            $branchManager->givePermissionTo($branchManagerPermissions);
        }

        $posOperatorManager = Role::find(EnumRole::POS_OPERATOR);
        if ($posOperatorManager) {
            $posOperatorManagerPermissions = [
                ['name' => 'dashboard'],
                ['name' => 'pos'],
                ['name' => 'pos-orders'],
                // [POS-9.1.1] cashier = up-to-10% discount
                ['name' => 'pos-discount-up-to-10'],
            ];
            $posOperatorManagerPermissions = Permission::whereIn('name', $posOperatorManagerPermissions)->get();
            $posOperatorManager->givePermissionTo($posOperatorManagerPermissions);
        }

        $chef = Role::find(EnumRole::CHEF);
        if ($chef) {
            $chefPermissions = [
                ['name' => 'dashboard'],
                ['name' => 'kitchen-display-system'],
                ['name' => 'order-status-screen'],
            ];
            $chefPermissions = Permission::whereIn('name', $chefPermissions)->get();
            $chef->givePermissionTo($chefPermissions);
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