<?php
// Authoritative board photo alignment: use config/menu_images.php (the board's real image SSOT,
// V2 2026-05-21). Copy the ENTIRE board photo set (public/images/menu/*.png) into both standalone
// asset trees (board filenames preserved), and dump the full config mapping as JSON so the
// standalone menu.js can be repointed to board photos 1:1.
$ROOT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
require $ROOT.'/vendor/autoload.php';
$app = require $ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$cfg = config('menu_images');
$srcDir = $ROOT.'/public/images/menu';
$trees = [ 'mobile' => $ROOT.'/mobile/assets/menu', 'web' => '/Users/1millnonstop/Downloads/web/assets/menu' ];

// 0. Remove the stale seeder-based board_*.png copies (they used outdated supplement candidates).
foreach ($trees as $dir) { foreach (glob($dir.'/board_*.png') as $f) @unlink($f); }

// 1. Copy the entire board photo set into both trees (board filenames preserved).
$copied = 0; $allFiles = glob($srcDir.'/*.png');
foreach ($allFiles as $src) {
  $name = basename($src);
  foreach ($trees as $dir) { if (is_dir($dir)) copy($src, $dir.'/'.$name); }
  $copied++;
}
echo "copied_board_pngs=$copied (x2 trees) from public/images/menu\n";

// 2. Resolve each bucket key -> board filename, verify the file exists, dump JSON.
$buckets = ['items','supplements','sauces','crudite_extras','crudites','viandes','addons','categories'];
$out = []; $missingFiles = [];
foreach ($buckets as $b) {
  if (empty($cfg[$b]) || !is_array($cfg[$b])) continue;
  foreach ($cfg[$b] as $key => $file) {
    $exists = is_file($srcDir.'/'.$file);
    $out[$b][$key] = $file;
    if (!$exists) $missingFiles[] = "$b/$key -> $file (NOT FOUND)";
  }
}
file_put_contents($ROOT.'/reports/test-e2e/frontends-abuse-2026-05-30/board-image-map.json', json_encode($out, JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE));
echo "board-image-map.json written (buckets: ".implode(',', array_keys($out)).")\n";
echo "missing_files=".count($missingFiles)."\n";
foreach (array_slice($missingFiles,0,20) as $m) echo "  MISSING $m\n";

// 3. Print the items bucket (products) for quick ITEM_IMG repoint reference.
echo "\n=== ITEMS bucket (slug -> board file) — for ITEM_IMG repoint ===\n";
foreach (($cfg['items'] ?? []) as $slug => $file) printf("    '%s': '%s',\n", $slug, $file);
