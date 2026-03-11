<?php
/**
 * RESET MENU TO FRENCH - STANDALONE SCRIPT
 * 
 * This script purges the English menu and recreates the French "Le Grill House" menu.
 * 
 * USAGE:
 *   Method 1: Via browser - http://your-domain.com/RESET_MENU_FRENCH.php
 *   Method 2: Via CLI - php RESET_MENU_FRENCH.php
 */

// Show all errors for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<html><head><title>Reset Menu to French</title></head><body>";
echo "<h1>🚨 RESET MENU - Le Grill House</h1>";
echo "<pre>";

try {
    // Bootstrap Laravel
    require __DIR__ . '/vendor/autoload.php';
    $app = require_once __DIR__ . '/bootstrap/app.php';
    $kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
    $kernel->bootstrap();

    use Illuminate\Support\Facades\DB;
    use Database\Seeders\MenuSeeder;

    echo "=== STEP 1: Checking current menu... ===\n";
    
    $categories = DB::table('item_categories')->count();
    $items = DB::table('items')->count();
    
    echo "Current state:\n";
    echo "  - Categories: {$categories}\n";
    echo "  - Items: {$items}\n";
    
    // Check for English items
    $englishItems = DB::table('items')
        ->where('name', 'like', '%Chicken%')
        ->orWhere('name', 'like', '%Dumpling%')
        ->orWhere('name', 'like', '%Egg Roll%')
        ->orWhere('name', 'like', '%Wonton%')
        ->count();
    
    if ($englishItems > 0) {
        echo "  ⚠️  Found {$englishItems} English items!\n";
    }
    
    echo "\n=== STEP 2: Purging menu data... ===\n";
    
    // Disable foreign key checks
    DB::statement('SET FOREIGN_KEY_CHECKS=0;');
    
    // Truncate all menu tables
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
    
    echo "\n✅ All menu data purged successfully!\n";
    
    echo "\n=== STEP 3: Creating French menu... ===\n";
    
    // Run the MenuSeeder
    $seeder = new MenuSeeder();
    $seeder->run();
    
    echo "\n=== STEP 4: Verification ===\n";
    
    $newCategories = DB::table('item_categories')->count();
    $newItems = DB::table('items')->count();
    
    echo "New state:\n";
    echo "  - Categories: {$newCategories}\n";
    echo "  - Items: {$newItems}\n";
    
    // Show some French categories
    $frenchCats = DB::table('item_categories')->limit(5)->pluck('name');
    echo "\nFrench categories created:\n";
    foreach ($frenchCats as $cat) {
        echo "  - {$cat}\n";
    }
    
    // Show some French items
    $frenchItems = DB::table('items')->limit(5)->pluck('name');
    echo "\nFrench items created:\n";
    foreach ($frenchItems as $item) {
        echo "  - {$item}\n";
    }
    
    echo "\n";
    echo "╔════════════════════════════════════════════════════════╗\n";
    echo "║  ✅ SUCCESS! Menu reset to French (Le Grill House)     ║\n";
    echo "║                                                        ║\n";
    echo "║  Refresh your POS to see:                              ║\n";
    echo "║  - Nos Tacos                                           ║\n";
    echo "║  - Nos Sandwichs                                       ║\n";
    echo "║  - Nos Burgers                                         ║\n";
    echo "║  - And more...                                         ║\n";
    echo "╚════════════════════════════════════════════════════════╝\n";
    
} catch (Exception $e) {
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Stack trace:\n" . $e->getTraceAsString() . "\n";
}

echo "</pre>";
echo "<p>You can now <a href='/admin/pos'>go to POS</a> to see the French menu.</p>";
echo "</body></html>";
