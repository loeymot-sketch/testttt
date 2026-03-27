<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * EMERGENCY MIGRATION - Reset Menu to French (Le Grill House)
 * This migration purges the English menu and recreates the French menu
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        $verbose = !app()->environment('testing');
        if ($verbose) {
            echo "\n=== EMERGENCY MENU RESET - Le Grill House ===\n";
        }
        
        // [FIX] SQLite-compatible foreign key handling
        $driver = DB::getDriverName();
        
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = OFF;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        }
        
        // Truncate all menu-related tables (delete for SQLite compatibility)
        if ($driver === 'sqlite') {
            DB::table('item_addons')->delete();
            DB::table('item_extras')->delete();
            DB::table('item_variations')->delete();
            DB::table('item_attributes')->delete();
            DB::table('items')->delete();
            DB::table('item_categories')->delete();
            // Reset SQLite sequences
            DB::statement("DELETE FROM sqlite_sequence WHERE name IN ('items', 'item_categories', 'item_addons', 'item_extras', 'item_variations', 'item_attributes');");
        } else {
            DB::table('item_addons')->truncate();
            DB::table('item_extras')->truncate();
            DB::table('item_variations')->truncate();
            DB::table('item_attributes')->truncate();
            DB::table('items')->truncate();
            DB::table('item_categories')->truncate();
        }
        
        if ($verbose) {
            echo "✓ Purged item_addons\n";
            echo "✓ Purged item_extras\n";
            echo "✓ Purged item_variations\n";
            echo "✓ Purged item_attributes\n";
            echo "✓ Purged items\n";
            echo "✓ Purged item_categories\n";
        }

        // Re-enable foreign key checks
        if ($driver === 'sqlite') {
            DB::statement('PRAGMA foreign_keys = ON;');
        } else {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        }

        if ($verbose) {
            echo "✅ All menu tables purged successfully\n";
            echo "Run: php artisan db:seed --class=MenuSeeder\n";
            echo "=============================================\n\n";
        }
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        if (!app()->environment('testing')) {
            echo "Cannot restore previous menu data - migration was emergency reset\n";
        }
    }
};
