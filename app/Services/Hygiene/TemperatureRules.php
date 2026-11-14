<?php

namespace App\Services\Hygiene;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] LA règle des relevés de température : un relevé est conforme ou en écart selon les seuils de
 * son équipement. Une seule implémentation, côté serveur — l'écran ne recalcule jamais un verdict, il l'affiche.
 */
final class TemperatureRules
{
    /** Seuils par défaut d'un type d'équipement, ou null si le type est inconnu. @return array{min:?float,max:?float}|null */
    public static function defaults(string $kind): ?array
    {
        $k = config("hygiene.kinds.$kind");

        return $k ? ['min' => $k['min'], 'max' => $k['max']] : null;
    }

    /**
     * @return array{status:string, reason:?string}  status ok|alert ; reason too_warm|too_cold|null
     */
    public static function evaluate(float $value, ?float $min, ?float $max): array
    {
        // arrondi à la décimale du thermomètre : la comparaison est faite sur ce qui sera écrit et imprimé
        $value = round($value, 1);
        if ($max !== null && $value > $max) return ['status' => 'alert', 'reason' => 'too_warm'];
        if ($min !== null && $value < $min) return ['status' => 'alert', 'reason' => 'too_cold'];

        return ['status' => 'ok', 'reason' => null];
    }

    /** Moment du relevé d'après l'heure locale : avant 15 h = matin, après = soir. */
    public static function periodFor(\Carbon\CarbonInterface $at): string
    {
        return (int) $at->format('G') >= (int) config('hygiene.evening_from_hour', 15) ? 'evening' : 'morning';
    }
}
