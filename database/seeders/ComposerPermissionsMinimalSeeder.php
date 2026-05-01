<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class ComposerPermissionsMinimalSeeder extends Seeder
{
    public const PERMISSIONS = ['catalog.compose', 'catalog.publish'];
    public const ROLE_NAMES = ['Admin', 'Branch Manager', 'Tenant Admin', 'Branch Admin'];

    public function run(): void
    {
        $permissions = collect(self::PERMISSIONS)
            ->map(fn (string $name) => Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'sanctum',
            ]));

        foreach (self::ROLE_NAMES as $roleName) {
            $role = Role::query()
                ->where('name', $roleName)
                ->where('guard_name', 'sanctum')
                ->first();

            if ($role) {
                $role->givePermissionTo($permissions);
            }
        }
    }
}
