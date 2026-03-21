<?php

$aiDir = '/Users/1millnonstop/.gemini/antigravity/brain/20c3ae55-8d98-4325-8bd1-7fe988fe1d36/';
$menuDir = '/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/public/images/menu/';


$mappings = [
    // Sandwiches
    'sandwich_sub_beautiful_1773433172685.png' => [
        'sandwich_terminator.png', 
        'sandwich_mega.png', 
        'sandwich_supreme.png', 
        'sandwich_cayenne.png'
    ],
    // Chicken
    'chicken_tenders_wings_beautiful_1773433199373.png' => [
        'chicken_wings.png',
        'tenders.png'
    ],
    // Assiettes
    'assiette_mixte_beautiful_1773433212197.png' => [
        'assiette_poulet.png',
        'assiette_kefta.png',
        'assiette_merguez.png',
        'assiette_mixte.png'
    ],
    // Burger
    'chicken_burger_beautiful_1773433241712.png' => 'chicken_burger.png'
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
                echo "Copied to $dest\n";
                $copied++;
            } else {
                echo "Failed to copy to $dest\n";
            }
        }
    } else {
        echo "Source not found: $src\n";
    }
}

echo "Total images copied: $copied\n";
