<?php

$aiDir = __DIR__ . '/public/images/ai_food/';
$menuDir = __DIR__ . '/public/images/menu/';

$mappings = [
    // Tacos
    'tacos_mixte.png' => 'tacos.png',
    
    // Sandwichs
    'sandwich_kebab.png' => ['sandwich_cayenne.png', 'sandwich_mega.png', 'sandwich_terminator.png'],
    'sandwich_poulet.png' => 'sandwich_supreme.png',
    
    // Panini
    'panini_francais.png' => 'panini.png',
    
    // Chicken
    'chicken.jpeg' => ['chicken_wings.png', 'tenders.png'],
    
    // Desserts
    'tiramisu_dessert.png' => 'tiramisu.png',
    'tarte_pommes.png' => 'tarte_daim.png',
    'mousse_chocolat.png' => 'glace.png',
    
    // Boissons
    'coca_canette.png' => 'coca_cola.png',
    'coca_cola.png' => 'coca_zero.png',
    'fanta_canette.png' => 'fanta.png',
    'sprite_canette.png' => 'sprite.png',
    'eau_minerale.png' => ['eau.png', 'eau_gazeuse.png'],
    'soda_cola.png' => ['boisson.png', 'orangina.png', 'oasis_tropical.png', 'oasis_pomme.png', 'capri_sun.png'],
    
    // Default logo
    'logo.png' => 'logo.png'
];

$copied = 0;

foreach ($mappings as $src => $dests) {
    if (!is_array($dests)) {
        $dests = [$dests];
    }
    
    $sourcePath = $aiDir . $src;
    
    if (file_exists($sourcePath)) {
        foreach ($dests as $dest) {
            $destPath = $menuDir . $dest;
            if (copy($sourcePath, $destPath)) {
                echo "Copied $src to $dest\n";
                $copied++;
            } else {
                echo "Failed to copy $src to $dest\n";
            }
        }
    } else {
        echo "Source not found: $src\n";
    }
}

echo "Total images copied: $copied\n";
