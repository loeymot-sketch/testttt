<?php

namespace App\Support\Storefront;

/**
 * [APP HYBRIDE W2a · 2026-11-03] Rapport de contraste WCAG 2.1 entre deux couleurs `#RRGGBB`
 * (https://www.w3.org/TR/WCAG21/#dfn-contrast-ratio). Même formule que l'indicateur de l'écran d'administration.
 */
final class Contrast
{
    public static function isHex(mixed $value): bool
    {
        return is_string($value) && preg_match('/^#[0-9A-Fa-f]{6}$/', $value) === 1;
    }

    public static function luminance(string $hex): float
    {
        $c = [];
        foreach ([1, 3, 5] as $i) {
            $s = hexdec(substr($hex, $i, 2)) / 255;
            $c[] = $s <= 0.03928 ? $s / 12.92 : (($s + 0.055) / 1.055) ** 2.4;
        }

        return 0.2126 * $c[0] + 0.7152 * $c[1] + 0.0722 * $c[2];
    }

    public static function ratio(string $a, string $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    /** « 3,12:1 » — affichage français, tronqué (jamais arrondi vers le haut : 4,499 ne doit pas s'afficher 4,50). */
    public static function format(float $ratio): string
    {
        return number_format(floor($ratio * 100) / 100, 2, ',', '').':1';
    }
}
