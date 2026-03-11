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
        echo "\n=== EMERGENCY MENU RESET - Le Grill House ===\n";
        
        // Disable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=0;');
        
        // Truncate all menu-related tables
        DB::table('item_addons')->truncate();
        echo "✓ Truncated item_addons\n";
        
        DB::table('item_extras')->truncate();
        echo "✓ Truncated item_extras\n";
        
        DB::table('item_variations')->truncate();
        echo "✓ Truncated item_variations\n";
        
        DB::table('item_attributes')->truncate();
        echo "✓ Truncated item_attributes\n";
        
        DB::table('items')->truncate();
        echo "✓ Truncated items\n";
        
        DB::table('item_categories')->truncate();
        echo "✓ Truncated item_categories\n";
        
        // Re-enable foreign key checks
        DB::statement('SET FOREIGN_KEY_CHECKS=1;');
        
        echo "✅ All menu tables purged successfully\n";
        echo "Run: php artisan db:seed --class=MenuSeeder\n";
        echo "=============================================\n\n";
    }

    /**
     * Reverse the migration.
     */
    public function down(): void
    {
        // Cannot restore previous menu data
        echo "Cannot restore previous menu data - migration was emergency reset\n";
    }
};
