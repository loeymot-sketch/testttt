<?php

namespace App\Support;

/**
 * [STORES T-3.3.2 · 2026-10-01] Comparaison des versions de l'application des stores.
 *
 * Deux règles, et une seule raison : ne JAMAIS fermer la boutique par erreur.
 *  · Les versions se comparent en NOMBRES (1.10 vient après 1.9), pas en texte.
 *  · Ce qui ne se lit pas (en-tête absent ou bizarre, réglage mal tapé) ne bloque personne.
 *    Un refus de commande est une décision lourde : elle n'est prise que sur deux versions
 *    lisibles dont l'une est réellement plus ancienne.
 */
final class AppVersion
{
    /** 1 à 3 nombres séparés par des points, 4 chiffres au plus chacun. */
    private const FORME = '/^\d{1,4}(\.\d{1,4}){0,2}$/';

    /** @return array{0:int,1:int,2:int}|null */
    public static function lire(?string $version): ?array
    {
        $version = trim((string) $version);
        if ($version === '' || ! preg_match(self::FORME, $version)) {
            return null;
        }
        $parties = array_map('intval', explode('.', $version));

        return [$parties[0], $parties[1] ?? 0, $parties[2] ?? 0];
    }

    /** Vrai seulement si les deux versions sont lisibles et que la première est plus ancienne. */
    public static function estAvant(?string $version, ?string $minimum): bool
    {
        $v = self::lire($version);
        $m = self::lire($minimum);
        if ($v === null || $m === null) {
            return false;
        }

        return $v < $m;
    }

    /** Version minimale publiée : le réglage s'il est lisible, sinon 0.0.0 (personne bloqué). */
    public static function minimum(): string
    {
        $reglage = (string) config('app_mobile.min_version', '1.0.0');

        return self::lire($reglage) === null ? '0.0.0' : $reglage;
    }

    /** Ce que l'application lit pour décider d'afficher « Mise à jour nécessaire ». */
    public static function configuration(): array
    {
        return [
            'min_version' => self::minimum(),
            'message'     => (string) config('app_mobile.message'),
            'ios_url'     => (string) config('app_mobile.ios_url', ''),
            'android_url' => (string) config('app_mobile.android_url', ''),
        ];
    }
}
