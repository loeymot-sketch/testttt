<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

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
    }
}
