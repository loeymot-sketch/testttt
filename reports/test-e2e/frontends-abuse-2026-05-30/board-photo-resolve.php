<?php
// Resolve the board's canonical product->photo mapping (from RestoreLeCayenneItemImagesSeeder
// via reflection), copy each resolved board photo into BOTH standalone asset trees, and emit the
// slug -> standalone-filename map for ITEM_IMG repointing. READ-the-board, WRITE-the-standalone.
$ROOT = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt';
require $ROOT.'/vendor/autoload.php';
$app = require $ROOT.'/bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

$ref = new ReflectionClass(\Database\Seeders\RestoreLeCayenneItemImagesSeeder::class);
$SOURCES = $ref->getConstant('IMAGE_SOURCES'); // slug => [candidate rel paths]

$trees = [
  'mobile' => $ROOT.'/mobile/assets/menu',
  'web'    => '/Users/1millnonstop/Downloads/web/assets/menu',
];

$resolved = []; $missing = [];
foreach ($SOURCES as $slug => $cands) {
  $src = null;
  foreach ($cands as $rel) { $abs = $ROOT.'/'.$rel; if (is_file($abs)) { $src = $abs; break; } }
  if (!$src) { $missing[$slug] = $cands; continue; }
  $destName = 'board_'.$slug.'.png';
  foreach ($trees as $t => $dir) {
    if (!is_dir($dir)) { echo "WARN missing tree dir: $dir\n"; continue; }
    copy($src, $dir.'/'.$destName);
  }
  $resolved[$slug] = ['file' => $destName, 'src' => str_replace($ROOT.'/', '', $src)];
}

echo "=== RESOLVED (".count($resolved)." slugs -> board photo copied to both trees) ===\n";
foreach ($resolved as $slug => $r) printf("%-28s -> %-40s (from %s)\n", $slug, $r['file'], $r['src']);
echo "\n=== NO BOARD PHOTO (".count($missing)." slugs — keep existing render, owner-upload TODO) ===\n";
foreach ($missing as $slug => $c) printf("%-28s (candidates: %s)\n", $slug, implode(' | ', $c));

// Emit a JS-ready ITEM_IMG fragment for repointing
echo "\n=== ITEM_IMG repoint fragment (slug: 'board_<slug>.png') ===\n";
foreach ($resolved as $slug => $r) printf("    '%s': '%s',\n", $slug, $r['file']);
