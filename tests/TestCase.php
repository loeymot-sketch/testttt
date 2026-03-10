<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

abstract class TestCase extends BaseTestCase
{
    use CreatesApplication;

    /**
     * Set up minimal application settings required by middleware.
     * Called AFTER migrations (RefreshDatabase trait migrates before setUp hooks).
     *
     * Only runs when the `settings` table exists and is empty.
     * This prevents the "Attempt to read property faviconLogo on null" crash
     * that occurs when controllers call Setting::get() with no rows in DB.
     */
    protected function seedMinimalSettings(): void
    {
        $table = config('settings.repositories.database.table', 'settings');

        if (!Schema::hasTable($table)) {
            return;
        }

        if (DB::table($table)->count() > 0) {
            return;
        }

        DB::table($table)->insert([
            ['key' => 'site_title', 'payload' => json_encode('FoodKing Test'), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'favicon_logo', 'payload' => json_encode(null), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_logo', 'payload' => json_encode(null), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency', 'payload' => json_encode('EUR'), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency_symbol', 'payload' => json_encode('€'), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'order_prefix', 'payload' => json_encode('FK'), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'order_setup_food_preparation_time', 'payload' => json_encode(30), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'order_setup_takeaway', 'payload' => json_encode(1), 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'order_setup_delivery', 'payload' => json_encode(1), 'created_at' => now(), 'updated_at' => now()],
        ]);

        // Seed theme_settings to prevent 'faviconLogo on null' crash in notification builders
        if (Schema::hasTable('theme_settings') && DB::table('theme_settings')->count() === 0) {
            DB::table('theme_settings')->insert([
                ['key' => 'theme_favicon_logo', 'payload' => json_encode(null), 'created_at' => now(), 'updated_at' => now()],
                ['key' => 'theme_logo',         'payload' => json_encode(null), 'created_at' => now(), 'updated_at' => now()],
            ]);
        }
    }

    /**
     * Seed Spatie roles and permissions required for authorization tests.
     * Matches production RoleTableSeeder: guard_name = 'sanctum', PascalCase names.
     * Assigns permissions to Admin role to enable POS/KDS access.
     * Prevents "RoleDoesNotExist" exceptions in SQLite memory mode.
     */
    protected function seedSpatieRoles(): void
    {
        // Create roles with production-aligned names and guard
        Role::firstOrCreate(['name' => 'Admin',          'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Chef',           'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Branch Manager', 'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'POS Operator',   'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Customer',       'guard_name' => 'sanctum']);
        Role::firstOrCreate(['name' => 'Stuff',          'guard_name' => 'sanctum']);

        // Create permissions and assign to Admin role
        $permissions = ['online-orders', 'pos-orders', 'kitchen-display-system', 'pos'];
        foreach ($permissions as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }

        $adminRole = Role::where('name', 'Admin')->where('guard_name', 'sanctum')->first();
        if ($adminRole) {
            $adminRole->syncPermissions($permissions);
        }
    }
}
