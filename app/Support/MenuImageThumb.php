<?php

namespace App\Support;

/**
 * [W5-PERF #1 2026-07-06] Résolution des vignettes pré-générées du catalogue
 * POS/borne (fallback config/menu_images.php).
 *
 * Problème mesuré (reports/test-e2e/owner-8-problemes/w5-perf/verdicts.md A4) :
 * les PNG détourés de public/images/menu pèsent jusqu'à 2,9 Mo pièce et étaient
 * servis PLEIN FORMAT par les fallbacks `getThumbAttribute` (Item, ItemCategory,
 * ItemVariation, ItemExtra) dès qu'aucune conversion medialibrary n'existe —
 * ~15-32 Mo transférés pour UNE grille catégorie POS (ratio ×70 vs conversions).
 *
 * `php artisan images:generate-pos-thumbs` pré-génère une vignette WebP ≤320 px
 * à côté de chaque source (`<base_path>/thumbs/<nom>.webp`). Ce helper renvoie
 * l'URL versionnée de la vignette quand elle existe, sinon null — l'appelant
 * conserve alors le comportement plein-format actuel (fallback visuel inchangé,
 * y compris pour les images ajoutées après coup sans vignette).
 *
 * NB : config/menu_images.php n'est PAS modifié (LOCK parallèle) — la résolution
 * `thumbs/` est dérivée dynamiquement du filename déjà résolu par l'appelant.
 */
class MenuImageThumb
{
    /** Sous-dossier des vignettes, relatif au base_path de menu_images. */
    public const SUBDIR = 'thumbs';

    /** Extensions raster converties par la commande (le SVG par défaut passe au travers). */
    private const RASTER_PATTERN = '/\.(png|jpe?g|webp)$/i';

    /**
     * Chemin relatif public de la vignette pour un fichier source (sans test
     * d'existence). Null pour les sources non-raster (ex. item-default.svg).
     */
    public static function relativePath(string $basePath, string $filename): ?string
    {
        if ($filename === '' || !preg_match(self::RASTER_PATTERN, $filename)) {
            return null;
        }
        $webpName = preg_replace(self::RASTER_PATTERN, '.webp', $filename);

        return trim($basePath, '/') . '/' . self::SUBDIR . "/{$webpName}";
    }

    /**
     * URL asset versionnée (?v=filemtime) de la vignette générée, ou null si
     * elle n'existe pas — l'appelant sert alors l'original (comportement
     * pré-W5 préservé à l'identique).
     */
    public static function url(string $basePath, string $filename): ?string
    {
        $relative = self::relativePath($basePath, $filename);
        if ($relative === null) {
            return null;
        }
        $absolute = public_path($relative);
        if (!file_exists($absolute)) {
            return null;
        }
        $hash = @filemtime($absolute) ?: 0;

        return asset($relative) . "?v={$hash}";
    }
}
