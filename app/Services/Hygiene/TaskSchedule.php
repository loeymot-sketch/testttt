<?php

namespace App\Services\Hygiene;

use App\Models\HygieneTaskTemplate;
use Carbon\CarbonInterface;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] Quelle fiche de nettoyage est due quel jour, et à quelle heure elle est en retard.
 * Une seule règle, côté serveur : l'écran du jour, la tuile de l'accueil et les rappels l'utilisent (isDueOn et isLate) ; le dossier de contrôle utilise isDueOn.
 */
final class TaskSchedule
{
    public static function isDueOn(HygieneTaskTemplate $t, CarbonInterface $day): bool
    {
        switch ($t->frequency) {
            case 'weekly':
                return (int) $day->isoWeekday() === (int) ($t->weekday ?: 1);
            case 'monthly':
                // « le 31 » n'existe pas tous les mois : la fiche tombe le dernier jour du mois si le jour demandé le dépasse.
                $wanted = min((int) ($t->month_day ?: 1), (int) $day->daysInMonth);

                return (int) $day->day === $wanted;
            default:
                return true;
        }
    }

    /** Heure limite du jour `$day` (fuseau de l'application), ou null si la fiche n'a pas d'horaire. */
    public static function dueAt(HygieneTaskTemplate $t, CarbonInterface $day): ?CarbonInterface
    {
        if (! $t->due_time || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $t->due_time, $m)) {
            return null;
        }

        return $day->copy()->setTime((int) $m[1], (int) $m[2], 0);
    }

    /**
     * UNE définition du « en retard » (écran du jour, tuile de l'accueil, rappels, indicateur « après l'heure » d'une validation) :
     * l'heure limite du jour est passée ET la fiche existait déjà à cette heure-là. Une fiche créée APRÈS son heure limite (le propriétaire installe
     * son plan en cours de journée) n'était pas encore attendue : elle est « à faire », jamais « en retard ». La règle vivait en trois exemplaires
     * qui divergeaient (tour 3 : l'accueil disait « à faire », l'écran Nettoyage criait « en retard »). Sans `created_at` (ligne ancienne) : attendue.
     * L'appelant ajoute ses propres conditions (fiche non validée…).
     */
    public static function isLate(HygieneTaskTemplate $t, CarbonInterface $now, CarbonInterface $day): bool
    {
        $due = self::dueAt($t, $day);
        if ($due === null || $now->lte($due)) {
            return false;
        }

        return $t->created_at === null || $t->created_at->lt($due);
    }
}
