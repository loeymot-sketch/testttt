<?php

namespace App\Services\Hygiene;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] LA règle du contrôle à réception : une température au-dessus de la limite de sa famille, ou un contrôle
 * visuel non conforme, fait de la livraison un ÉCART. Une seule implémentation, côté serveur.
 */
final class ReceptionRules
{
    /** Verdict d'une température de famille `$category` (null = pas de limite : relevé tracé seulement). */
    public static function temperatureStatus(string $category, float $value): string
    {
        $max = config("hygiene.reception.categories.$category.max");

        return $max !== null && round($value, 1) > (float) $max ? 'alert' : 'ok';
    }

    /**
     * @param  list<array{status:string}>  $temperatures
     * @param  list<array{ok:bool}>  $checks
     */
    public static function overall(array $temperatures, array $checks): string
    {
        foreach ($temperatures as $t) {
            if ($t['status'] === 'alert') return 'alert';
        }
        foreach ($checks as $c) {
            if (! $c['ok']) return 'alert';
        }

        return 'ok';
    }
}
