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
     * [WEBP-MIGRATION 2026-07-07] Chemin relatif public du jumeau WebP plein
     * format posé À CÔTÉ du PNG source (`<base>/<name>.webp`), sans test
     * d'existence. Null si la source est déjà `.webp` (le jumeau serait
     * elle-même) ou non-raster. Sert de repli quand aucune vignette `thumbs/`
     * n'a été générée : le PNG lourd est alors remplacé par un WebP visuellement
     * sans perte (`cwebp -q 90`), tout en gardant le PNG comme ultime repli
     * navigateur.
     */
    public static function siblingPath(string $basePath, string $filename): ?string
    {
        if ($filename === '' || !preg_match(self::RASTER_PATTERN, $filename)) {
            return null;
        }
        // Une source déjà WebP n'a pas de jumeau distinct à préférer.
        if (preg_match('/\.webp$/i', $filename)) {
            return null;
        }
        $webpName = preg_replace(self::RASTER_PATTERN, '.webp', $filename);

        return trim($basePath, '/') . "/{$webpName}";
    }

    /**
     * URL asset versionnée (?v=filemtime) du meilleur WebP disponible pour la
     * source, ou null si aucun n'existe — l'appelant sert alors le PNG/JPG
     * original (comportement pré-WebP préservé à l'identique, 0 régression).
     *
     * Ordre de préférence :
     *   1. Vignette ≤320px `thumbs/<name>.webp` (poids minimal pour les grilles).
     *   2. Jumeau plein format `<base>/<name>.webp` (repli si aucune vignette).
     */
    public static function url(string $basePath, string $filename): ?string
    {
        // 1. Vignette pré-générée (la plus légère) si présente.
        if (($relative = self::relativePath($basePath, $filename)) !== null) {
            if (($versioned = self::versionedIfExists($relative)) !== null) {
                return $versioned;
            }
        }

        // 2. Jumeau WebP plein format à côté du PNG si présent.
        if (($sibling = self::siblingPath($basePath, $filename)) !== null) {
            if (($versioned = self::versionedIfExists($sibling)) !== null) {
                return $versioned;
            }
        }

        return null;
    }

    /**
     * URL asset versionnée (?v=filemtime) d'un chemin relatif public s'il existe
     * physiquement, sinon null.
     */
    private static function versionedIfExists(string $relative): ?string
    {
        $absolute = public_path($relative);
        if (!file_exists($absolute)) {
            return null;
        }
        $hash = @filemtime($absolute) ?: 0;

        return asset($relative) . "?v={$hash}";
    }
}
