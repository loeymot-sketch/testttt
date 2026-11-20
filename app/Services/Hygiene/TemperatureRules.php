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

    /**
     * Le restaurant relève-t-il le SOIR ? Oui s'il existe au moins un relevé du soir (non annulé) dans les 7 derniers jours — la règle du rappel du soir
     * (config/hygiene.php › reminders). Sans cela, exiger un relevé du soir inventerait une obligation que le restaurant ne s'est pas donnée.
     */
    public static function eveningExpected(\Carbon\CarbonInterface $now): bool
    {
        // [2026-11-18 · relecture adverse P1-2] Une HABITUDE, pas un geste isolé : des relevés du soir sur au moins DEUX jours
        // distincts des 7 derniers jours (avant aujourd'hui). Un seul contrôle ponctuel à 15 h 30, ou la nouvelle mesure
        // prise l'après-midi après un écart, armait « Soir 0/n » sur la tuile pendant une semaine.
        $jours = \App\Models\HygieneTemperatureReading::query()->whereNull('voided_at')->where('period', 'evening')
            ->where('recorded_at', '>=', $now->copy()->startOfDay()->subDays(7))
            ->where('recorded_at', '<', $now->copy()->startOfDay())
            ->pluck('recorded_at')
            ->map(fn ($at) => \Carbon\Carbon::parse($at)->setTimezone($now->getTimezone())->toDateString())
            ->unique()->count();

        return $jours >= 2;
    }

    /**
     * [2026-11-18 · audit E2E P1-5] UNE règle pour « reste à relever MAINTENANT » (écran, tuile de l'accueil) : la pastille et le bouton « Saisir tout ce qui
     * reste » ne se contredisent plus. Le soir, si le restaurant relève le soir : pas de relevé du soir aujourd'hui = à relever. Sinon (matin, ou pas de
     * relevé du soir dans les habitudes) : aucun relevé aujourd'hui = à relever (même définition que le rappel du matin).
     *
     * @param iterable<string> $periodsDoneToday moments des relevés non annulés de l'équipement aujourd'hui
     */
    public static function isDue(string $period, bool $eveningExpected, iterable $periodsDoneToday): bool
    {
        $done = collect($periodsDoneToday);
        if ($period === 'evening' && $eveningExpected) return ! $done->contains('evening');

        return $done->isEmpty();
    }
}
