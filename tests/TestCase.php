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

    protected function setUp(): void
    {
        parent::setUp();
        // Aligné routes `apiKey` (auth, admin, frontend) avec phpunit.xml MIX_API_KEY
        $this->withHeaders([
            'x-api-key' => config('app.api_key', env('MIX_API_KEY', 'test-api-key')),
            'Accept'    => 'application/json',
        ]);
    }

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
            // [WAVE5-KIOSK-001] POS group — pos_dine_in_enabled defaults to TRUE in test
            // env so existing kiosk happy-path tests posting OrderType::KIOSK (sur place)
            // keep passing. Production default remains FALSE (see Settings::group('pos')
            // ->get('pos_dine_in_enabled', false) fallback). V1-disabled scenarios in
            // sentinel tests must explicitly write pos_dine_in_enabled=0.
            ['key' => 'pos_dine_in_enabled', 'payload' => json_encode(1), 'group' => 'pos', 'created_at' => now(), 'updated_at' => now()],
            // Company settings required for notifications (group = 'company')
            ['key' => 'company_name', 'payload' => json_encode('FoodKing Test'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_email', 'payload' => json_encode('test@foodking.com'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_phone', 'payload' => json_encode('+33123456789'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_website', 'payload' => json_encode('https://foodking.test'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_city', 'payload' => json_encode('Paris'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_state', 'payload' => json_encode('IDF'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_country_code', 'payload' => json_encode('FR'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_zip_code', 'payload' => json_encode('75001'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
            ['key' => 'company_address', 'payload' => json_encode('1 rue Test'), 'group' => 'company', 'created_at' => now(), 'updated_at' => now()],
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
        $adminRoleModel = Role::firstOrCreate(['name' => 'Admin', 'guard_name' => 'sanctum']);
        $chefRoleModel = Role::firstOrCreate(['name' => 'Chef', 'guard_name' => 'sanctum']);
        $branchManagerRoleModel = Role::firstOrCreate(['name' => 'Branch Manager', 'guard_name' => 'sanctum']);
        $posRoleModel = Role::firstOrCreate(['name' => 'POS Operator', 'guard_name' => 'sanctum']);
        $customerRoleModel = Role::firstOrCreate(['name' => 'Customer', 'guard_name' => 'sanctum']);
        $stuffRoleModel = Role::firstOrCreate(['name' => 'Stuff', 'guard_name' => 'sanctum']);

        $adminRoleModel->landing_url = 'dashboard';
        $adminRoleModel->save();
        $chefRoleModel->landing_url = 'kitchen-display-system';
        $chefRoleModel->save();
        $branchManagerRoleModel->landing_url = 'dashboard';
        $branchManagerRoleModel->save();
        $posRoleModel->landing_url = 'pos';
        $posRoleModel->save();
        $customerRoleModel->landing_url = '#';
        $customerRoleModel->save();
        $stuffRoleModel->landing_url = 'dashboard';
        $stuffRoleModel->save();

        $permissionNames = [
            'online-orders',
            'pos-orders',
            'kitchen-display-system',
            'pos',
            'dashboard',
            'order-status-screen',
            'settings',
            'items',
            'items_create',
            'items_edit',
            'items_delete',
            'items_show',
            'coupons',
            'coupons_create',
            'coupons_edit',
            'coupons_delete',
            'coupons_show',
            'dining-tables',
            'dining_tables_create',
            'dining_tables_edit',
            'dining_tables_delete',
            'dining_tables_show',
            'offers',
            'offers_create',
            'offers_edit',
            'offers_delete',
            'offers_show',
            // [POS-9.1.1] POS discount permission gate
            'pos-discount-up-to-10',
            'pos-discount-over-10-requires-manager',
            'pos-discount-unlimited',
            // [POS-9.1.2] destroy paid orders
            'pos-destroy-paid',
            // [POS-9.4.12] fiscal management (NF525 Z/X reports, drawer audit)
            'pos-manage-fiscal',
            'pos-reopen-z',
            // [Sprint 1D / F-4 — 2026-05-16] Cash variance override
            'cash.reconcile.variance.override',
            // [Wave O — O4 2026-05-20] Admin daily cash sessions read-only report.
            'cash-sessions-report',
            // [OWNER 2026-08-13 « ameliore l'acces du caissier »] Ticket promo plateformes/Uber.
            'pos-flyer-print',
        ];
        foreach ($permissionNames as $perm) {
            Permission::firstOrCreate(['name' => $perm, 'guard_name' => 'sanctum']);
        }

        $adminRole = Role::where('name', 'Admin')->where('guard_name', 'sanctum')->first();
        if ($adminRole) {
            $adminRole->syncPermissions($permissionNames);
        }

        // Aligné RolePermissionTableSeeder (POS / Chef ont besoin de dashboard + écrans métier)
        $posRole = Role::where('name', 'POS Operator')->where('guard_name', 'sanctum')->first();
        if ($posRole) {
            $posRole->syncPermissions([
                'dashboard',
                'pos',
                'pos-orders',
                'kitchen-display-system',
                'order-status-screen',
                // [POS-9.1.1] cashier = up to 10% discount
                'pos-discount-up-to-10',
                // [WEB-ORDER-ACCEPT 2026-07-30] Aligné RolePermissionTableSeeder : le caissier
                // accepte/gère les commandes web (bouton « Accepter » du tracker). Le refund reste
                // gardé `pos-refund` (hors de ce set) → frontière de permission préservée.
                'online-orders',
                // [OWNER 2026-08-13 « ameliore l'acces du caissier »] Ticket promo plateformes/Uber :
                // creer / reimprimer / annuler depuis le comptoir.
                'pos-flyer-print',
            ]);
        }

        // [POS-9.1.1] Branch Manager = 10%-50% discount
        $branchManagerRole = Role::where('name', 'Branch Manager')->where('guard_name', 'sanctum')->first();
        if ($branchManagerRole) {
            $branchManagerRole->syncPermissions([
                'dashboard',
                'pos',
                'pos-orders',
                'pos-discount-up-to-10',
                'pos-discount-over-10-requires-manager',
                // [POS-9.4.12] fiscal management is a manager-level responsibility.
                'pos-manage-fiscal',
                'pos-reopen-z',
                // [Sprint 1D / F-4] approve cash variance beyond threshold.
                'cash.reconcile.variance.override',
                // [Wave O — O4 2026-05-20] Branch Manager voit le rapport caisses
                // quotidien (scoped à sa branche via BranchScope du model).
                'cash-sessions-report',
                // [OWNER 2026-08-13 « ameliore l'acces du caissier »] Ticket promo plateformes/Uber.
                'pos-flyer-print',
            ]);
        }

        $chefRole = Role::where('name', 'Chef')->where('guard_name', 'sanctum')->first();
        if ($chefRole) {
            $chefRole->syncPermissions([
                'dashboard',
                'kitchen-display-system',
                'order-status-screen',
            ]);
        }
    }
}
