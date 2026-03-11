<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

/**
 * EMERGENCY MIGRATION - PURGE ENGLISH MENU
 * This migration forcefully removes all English menu items
 * and prepares the database for French menu creation.
 */
return new class extends Migration
{
    /**
     * Run the migration.
     */
    public function up(): void
    {
        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║  🚨 EMERGENCY PURGE - English Menu Removal              ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo "\n";

        // Show current state
        $categoriesBefore = DB::table('item_categories')->count();
        $itemsBefore = DB::table('items')->count();
        
        echo "Current database state:\n";
        echo "  - Categories: {$categoriesBefore}\n";
        echo "  - Items: {$itemsBefore}\n\n";

        // Check for English items
        $englishCount = DB::table('items')
            ->where(function ($query) {
                $query->where('name', 'like', '%Chicken%')
                    ->orWhere('name', 'like', '%Dumpling%')
                    ->orWhere('name', 'like', '%Egg Roll%')
                    ->orWhere('name', 'like', '%Wonton%')
                    ->orWhere('name', 'like', '%Appetizer%')
                    ->orWhere('name', 'like', '%BBQ%');
            })
            ->count();

        if ($englishCount > 0) {
            echo "⚠️  Found {$englishCount} English items! Purging...\n\n";
        }

        // Disable foreign key checks
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        } catch (\Exception $e) {
            // SQLite doesn't support this, ignore
        }

        // Truncate all menu-related tables
        $tables = [
            'item_addons',
            'item_extras', 
            'item_variations',
            'item_attributes',
            'items',
            'item_categories'
        ];

        foreach ($tables as $table) {
            try {
                DB::table($table)->truncate();
                echo "  ✓ Truncated {$table}\n";
            } catch (\Exception $e) {
                echo "  ⚠️  Could not truncate {$table}: " . $e->getMessage() . "\n";
            }
        }

        // Re-enable foreign key checks
        try {
            DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        } catch (\Exception $e) {
            // SQLite doesn't support this, ignore
        }

        // Verify purge
        $categoriesAfter = DB::table('item_categories')->count();
        $itemsAfter = DB::table('items')->count();

        echo "\n";
        echo "╔════════════════════════════════════════════════════════════╗\n";
        echo "║  ✅ PURGE COMPLETE                                       ║\n";
        echo "╠════════════════════════════════════════════════════════════╣\n";
        echo "║  Categories: {$categoriesBefore} → {$categoriesAfter}                              ║\n";
        echo "║  Items: {$itemsBefore} → {$itemsAfter}                                    ║\n";
        echo "╠════════════════════════════════════════════════════════════╣\n";
        echo "║  NEXT STEP: Run MenuSeeder                                  ║\n";
        echo "║  Command: php artisan db:seed --class=MenuSeeder           ║\n";
        echo "╚════════════════════════════════════════════════════════════╝\n";
        echo "\n";
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        echo "Cannot restore previous menu data - this was an emergency purge.\n";
    }
};
