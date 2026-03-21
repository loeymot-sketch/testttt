<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\Item;

echo "=== E2E PERFORMANCE TEST: Item::select vs Item::get() ===\n";

// Assuming we want to fetch items for an order, e.g., Item IDs 1 to 5.
$itemIds = [1, 2, 3, 4, 5];

// 1. Benchmark Old Way: Item::get()
$startOld = microtime(true);
$dbItemsOld = Item::get()->pluck('price', 'id');
$endOld = microtime(true);
$timeOldMs = ($endOld - $startOld) * 1000;
echo "1. Old Way [Item::get()]: Loaded " . count($dbItemsOld) . " items in " . round($timeOldMs, 2) . " ms\n";

// Clear memory/cache if possible, though in a real scenario we'd do multiple runs.
unset($dbItemsOld);

// 2. Benchmark New Way: Item::select()->whereIn()
$startNew = microtime(true);
$dbItemsNew = Item::select('id', 'price')->whereIn('id', $itemIds)->pluck('price', 'id');
$endNew = microtime(true);
$timeNewMs = ($endNew - $startNew) * 1000;
echo "2. New Way [Item::select()]: Loaded " . count($dbItemsNew) . " items in " . round($timeNewMs, 2) . " ms\n";

if ($timeOldMs > 0) {
    $improvement = (($timeOldMs - $timeNewMs) / $timeOldMs) * 100;
    echo "✅ PERFORMANCE IMPROVEMENT: " . round($improvement, 2) . "%\n";
} else {
    echo "Performance difference not measurable.\n";
}

echo "---------------------------------------------------\n";
