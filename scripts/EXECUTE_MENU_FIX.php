<?php
/**
 * EXECUTE MENU FIX - Maintenance script (CLI only)
 *
 * [AUDIT-FIX P0-3] Ce script doit être exécuté UNIQUEMENT en CLI.
 * Usage: php scripts/EXECUTE_MENU_FIX.php
 */

// [AUDIT-FIX P0-3] Guard: refuse HTTP execution
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit('Ce script ne peut être exécuté que via la ligne de commande.');
}

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Artisan;

header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html>
<head>
    <title>FoodKing Menu Fix - Le Grill House</title>
    <style>
        body { font-family: monospace; background: #1a1a1a; color: #00ff00; padding: 20px; }
        .box { border: 2px solid #00ff00; padding: 20px; margin: 20px 0; }
        .success { color: #00ff00; }
        .error { color: #ff0000; }
        .warning { color: #ffff00; }
        pre { background: #0a0a0a; padding: 10px; overflow-x: auto; }
    </style>
</head>
<body>
    <div class="box">
        <h1>🚨 FOODKING MENU FIX - Le Grill House</h1>
    </div>

    <pre><?php
    echo "Step 1/3: Running emergency purge migration...\n";
    echo str_repeat("-", 50) . "\n";
    
    try {
        Artisan::call('migrate', [
            '--path' => 'database/migrations/2026_03_11_999999_emergency_purge_english_menu.php',
            '--force' => true,
        ]);
        echo Artisan::output();
        echo "<span class='success'>✓ Purge completed</span>\n";
    } catch (Exception $e) {
        echo "<span class='error'>✗ Error: " . $e->getMessage() . "</span>\n";
    }

    echo "\nStep 2/3: Creating French menu...\n";
    echo str_repeat("-", 50) . "\n";
    
    try {
        Artisan::call('db:seed', [
            '--class' => 'MenuSeeder',
            '--force' => true,
        ]);
        echo Artisan::output();
        echo "<span class='success'>✓ Menu created</span>\n";
    } catch (Exception $e) {
        echo "<span class='error'>✗ Error: " . $e->getMessage() . "</span>\n";
    }

    echo "\nStep 3/3: Verification...\n";
    echo str_repeat("-", 50) . "\n";
    
    $categories = DB::table('item_categories')->count();
    $items = DB::table('items')->count();
    
    echo "Categories: {$categories}\n";
    echo "Items: {$items}\n\n";
    
    echo "First 10 categories:\n";
    foreach (DB::table('item_categories')->limit(10)->get(['name', 'slug']) as $cat) {
        echo "  ✓ {$cat->name}\n";
    }
    
    echo "\nFirst 10 items:\n";
    foreach (DB::table('items')->limit(10)->get(['name', 'price']) as $item) {
        $price = number_format($item->price, 2);
        echo "  ✓ {$item->name} (€{$price})\n";
    }
    
    // Check for any remaining English items
    $english = DB::table('items')
        ->where('name', 'like', '%Chicken%')
        ->orWhere('name', 'like', '%Dumpling%')
        ->orWhere('name', 'like', '%Egg Roll%')
        ->count();
    
    if ($english > 0) {
        echo "\n<span class='warning'>⚠️  WARNING: {$english} English items still found!</span>\n";
    } else {
        echo "\n<span class='success'>✅ No English items found - Menu is clean!</span>\n";
    }
    ?></pre>

    <div class="box">
        <h2>✅ MENU FIX COMPLETE</h2>
        <ol>
            <li>Refresh your browser</li>
            <li>Go to <a href="/admin/pos" style="color: #00ff00;">/admin/pos</a></li>
            <li>Verify you see "Nos Tacos" (not Chicken Dumplings)</li>
        </ol>
        
        <h3>Delete these files after successful fix:</h3>
        <ul>
            <li>EXECUTE_MENU_FIX.php</li>
            <li>EXECUTE_MENU_FIX.sh</li>
            <li>RESET_MENU_FRENCH.php</li>
        </ul>
    </div>
</body>
</html>
