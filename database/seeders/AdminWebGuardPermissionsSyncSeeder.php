<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * [HEAL 2026-05-27] Sync sanctum-guard permissions to web-guard for Admin role.
 *
 * Bug discovered owner-side: /admin/ingredients showed "Impossible de charger"
 * because Vue admin pages use browser session cookie (web guard), but
 * IngredientPermissionSeeder only granted on sanctum guard. Same root cause
 * affected /admin/kitchen-display-system sync endpoint (401 on polling fallback)
 * and many other admin pages.
 *
 * Root cause: Most seeders create permissions on sanctum guard only. The Admin
 * web-guard role had only 1 permission (vs 82 on sanctum). Vue SPA backend
 * calls use web guard for browser sessions, leading to silent 403s.
 *
 * Fix: This seeder mirrors ALL sanctum-guard permissions to web-guard +
 * grants to Admin (web) role. Idempotent + cache-flushed.
 *
 * Run order: AFTER all permission-creation seeders, BEFORE app boot.
 * In DatabaseSeeder: chain after IngredientPermissionSeeder + Composer +
 * RolePermissionTableSeeder etc.
 */
class AdminWebGuardPermissionsSyncSeeder extends Seeder
{
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $registrar->forgetCachedPermissions();

        $sanctumPerms = Permission::query()
            ->where('guard_name', 'sanctum')
            ->pluck('name')
            ->toArray();

        if (empty($sanctumPerms)) {
            $this->command?->warn('No sanctum permissions found — nothing to mirror.');
            return;
        }

        $adminWeb = Role::query()
            ->where('name', 'Admin')
            ->where('guard_name', 'web')
            ->first();

        if (!$adminWeb) {
            $this->command?->warn('Admin (web guard) role not found — Admin sync skipped.');
            return;
        }

        $created = 0;
        $skipped = 0;

        foreach ($sanctumPerms as $name) {
            $exists = Permission::query()
                ->where('name', $name)
                ->where('guard_name', 'web')
                ->exists();

            $perm = Permission::firstOrCreate([
                'name' => $name,
                'guard_name' => 'web',
            ]);

            $adminWeb->givePermissionTo($perm);

            if ($exists) {
                $skipped++;
            } else {
                $created++;
            }
        }

        $registrar->forgetCachedPermissions();

        $this->command?->info("Mirrored sanctum→web: created={$created} skipped={$skipped} total_synced=" . count($sanctumPerms));
    }
}
