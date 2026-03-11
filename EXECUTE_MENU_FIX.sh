#!/bin/bash
# EXECUTE MENU FIX - EMERGENCY SCRIPT
# This script purges the English menu and recreates the French menu

echo ""
echo "╔════════════════════════════════════════════════════════════╗"
echo "║  🚨 FOODKING MENU FIX - Le Grill House                    ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""

cd "$(dirname "$0")"

echo "Step 1/3: Running emergency purge migration..."
echo "------------------------------------------------"
php artisan migrate --path=database/migrations/2026_03_11_999999_emergency_purge_english_menu.php --force

echo ""
echo "Step 2/3: Creating French menu..."
echo "------------------------------------------------"
php artisan db:seed --class=MenuSeeder --force

echo ""
echo "Step 3/3: Verification..."
echo "------------------------------------------------"
php artisan tinker --execute="
use Illuminate\Support\Facades\DB;
\$cats = DB::table('item_categories')->count();
\$items = DB::table('items')->count();
echo 'Categories: ' . \$cats . PHP_EOL;
echo 'Items: ' . \$items . PHP_EOL;
echo PHP_EOL;
echo 'First 5 categories:' . PHP_EOL;
foreach (DB::table('item_categories')->limit(5)->pluck('name') as \$name) {
    echo '  - ' . \$name . PHP_EOL;
}
"

echo ""
echo "╔════════════════════════════════════════════════════════════╗"
echo "║  ✅ MENU FIX COMPLETE                                      ║"
echo "╠════════════════════════════════════════════════════════════╣"
echo "║                                                            ║"
echo "║  1. Refresh your browser                                  ║"
echo "║  2. Go to /admin/pos                                        ║"
echo "║  3. Verify you see 'Nos Tacos' (not Chicken Dumplings)      ║"
echo "║                                                            ║"
echo "╚════════════════════════════════════════════════════════════╝"
echo ""

read -p "Press Enter to continue..."
