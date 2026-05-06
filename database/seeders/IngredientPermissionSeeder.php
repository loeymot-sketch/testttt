<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class IngredientPermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permission = Permission::firstOrCreate([
            'name' => 'ingredients_manage',
            'guard_name' => 'sanctum',
        ]);

        foreach (['Admin', 'Tenant Admin', 'Manager', 'Branch Manager'] as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'sanctum')
                ->first();

            if ($role) {
                $role->givePermissionTo($permission);
            }
        }

        // Healing H3 (audit technique final 2026-05-04) : Spatie Permission caches
        // role/permission lookups. Without this flush, the new permission
        // `ingredients_manage` is not visible to the running app until the next
        // cache reset, causing a transient 403 on /admin/ingredients post-seed.
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
