<?php

/**
 * Génère des SVG « sauces » cohérents (couleur / type) — pas de photos hors sujet.
 * Usage : php scripts/generate-menu-sauce-svgs.php
 */
$dir = dirname(__DIR__) . '/public/images/menu';
if (!is_dir($dir)) {
    mkdir($dir, 0755, true);
}

$tpl = static function (string $light, string $dark, string $label = 'Sauce'): string {
    $light = htmlspecialchars($light, ENT_XML1);
    $dark = htmlspecialchars($dark, ENT_XML1);
    $label = htmlspecialchars($label, ENT_XML1);

    return <<<SVG
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200" role="img" aria-label="{$label}">
  <defs>
    <radialGradient id="g" cx="32%" cy="28%" r="75%">
      <stop offset="0%" stop-color="{$light}"/>
      <stop offset="100%" stop-color="{$dark}"/>
    </radialGradient>
  </defs>
  <rect width="200" height="200" rx="28" fill="#f3f4f6"/>
  <ellipse cx="100" cy="108" rx="74" ry="58" fill="url(#g)"/>
</svg>
SVG;
};

$sans = <<<'SVG'
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 200 200" width="200" height="200" role="img" aria-label="Sans sauce">
  <rect width="200" height="200" rx="28" fill="#f3f4f6"/>
  <ellipse cx="100" cy="108" rx="74" ry="58" fill="#e5e7eb" stroke="#9ca3af" stroke-width="3"/>
  <line x1="56" y1="56" x2="144" y2="160" stroke="#d1d5db" stroke-width="6" stroke-linecap="round"/>
</svg>
SVG;

$map = [
    'sauce_ketchup.svg'    => $tpl('#f87171', '#991b1b', 'Ketchup'),
    'sauce_mayo.svg'       => $tpl('#ffffff', '#e7e5e4', 'Mayonnaise'),
    'sauce_algerienne.svg' => $tpl('#fb923c', '#9a3412', 'Algérienne'),
    'sauce_samourai.svg'   => $tpl('#fdba74', '#c2410c', 'Samouraï'),
    'sauce_burger.svg'     => $tpl('#facc15', '#a16207', 'Sauce burger'),
    'sauce_harissa.svg'    => $tpl('#b91c1c', '#450a0a', 'Harissa'),
    'sauce_blanche.svg'    => $tpl('#fafaf9', '#d6d3d1', 'Sauce blanche'),
    'sauce_andalouse.svg'  => $tpl('#fdba74', '#ea580c', 'Andalouse'),
    'sauce_fish.svg'       => $tpl('#fef9c3', '#eab308', 'Sauce fish'),
    'sauce_curry.svg'      => $tpl('#fde047', '#a16207', 'Curry'),
    'sauce_poivre.svg'     => $tpl('#78716c', '#292524', 'Poivre'),
    'sauce_cesar.svg'      => $tpl('#e7e5e4', '#78716c', 'Sauce César'),
    'sauce_barbecue.svg'   => $tpl('#92400e', '#431407', 'Barbecue'),
    'sauce_cocktail.svg'   => $tpl('#fecdd3', '#be123c', 'Cocktail'),
    'sauce_americaine.svg' => $tpl('#fda4af', '#be123c', 'Américaine'),
    'sauce_hannibal.svg'   => $tpl('#7f1d1d', '#1c1917', 'Hannibal'),
    'sauce_bbq.svg'        => $tpl('#b45309', '#451a03', 'BBQ'),
];

foreach ($map as $file => $content) {
    file_put_contents($dir . '/' . $file, $content);
}
file_put_contents($dir . '/sauce_sans.svg', $sans);

echo 'OK: ' . (count($map) + 1) . " SVG dans {$dir}\n";
