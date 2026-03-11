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
            // Site settings (group = 'site')
            ['key' => 'site_title', 'payload' => json_encode('FoodKing Test'), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'favicon_logo', 'payload' => json_encode(null), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_logo', 'payload' => json_encode(null), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'site_copyright', 'payload' => json_encode('© 2026 FoodKing'), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            // Currency settings (group = 'site')
            ['key' => 'currency', 'payload' => json_encode('EUR'), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'currency_symbol', 'payload' => json_encode('€'), 'group' => 'site', 'created_at' => now(), 'updated_at' => now()],
            // Order setup settings (group = 'order_setup')
            ['key' => 'order_prefix', 'payload' => json_encode('FK'), 'group' => 'order_setup', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'order_setup_food_preparation_time', 'payload' => json_encode(30), 'group' => 'order_setup', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'order_setup_takeaway', 'payload' => json_encode(1), 'group' => 'order_setup', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'order_setup_delivery', 'payload' => json_encode(1), 'group' => 'order_setup', 'created_at' => now(), 'updated_at' => now()],
            // Company settings required for notifications (group = 'company')
            ['key' => 'company_name', 'payload' => json_encode('FoodKing Test'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_email', 'payload' => json_encode('test@foodking.com'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_phone', 'payload' => json_encode('+33123456789'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            // Theme settings (ThemeSetting model uses 'settings' table, not 'theme_settings') (group = 'theme')
            ['key' => 'theme_favicon_logo', 'payload' => json_encode(null), 'group' => 'theme', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'theme_logo', 'payload' => json_encode(null), 'group' => 'theme', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'theme_footer_logo', 'payload' => json_encode(null), 'group' => 'theme', 'created_at' => now(), 'updated_at' => now()],
        ]);
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
