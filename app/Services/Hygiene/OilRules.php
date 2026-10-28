<?php

namespace App\Services\Hygiene;

/**
 * [CAYENNE HYGIÈNE 2026-10-17] LA règle de l'huile de friture, côté serveur seulement.
 *   alert — composés polaires AU-DESSUS de la limite légale (25 %) ou bain AU-DESSUS de la limite : action corrective obligatoire.
 *   warn  — composés polaires à partir du seuil « à surveiller » (20 %) : tracé, aucune action exigée.
 *   ok    — le reste. Bornes : la limite elle-même est tolérée (25,0 % = conforme, 25,1 % = écart).
 */
final class OilRules
{
    /** @return array{status:string, reasons:list<string>} */
    public static function evaluate(?float $polar, ?float $bath): array
    {
        $limit = (float) config('hygiene.oil.polar_limit');
        $warn = (float) config('hygiene.oil.polar_warn');
        $hot = (float) config('hygiene.oil.bath_max_c');
        $reasons = [];
        $status = 'ok';
        if ($polar !== null) {
            $p = round($polar, 1);
            if ($p > $limit) { $reasons[] = 'polar_over'; $status = 'alert'; }
            elseif ($p >= $warn) { $reasons[] = 'polar_warn'; $status = 'warn'; }
        }
        if ($bath !== null && round($bath, 1) > $hot) {
            $reasons[] = 'bath_hot';
            $status = 'alert';
        }

        return ['status' => $status, 'reasons' => $reasons];
    }

    /**
     * Un test d'huile en écart ou « à surveiller » reste À TRAITER tant que l'huile n'a pas été changée : soit l'opérateur l'a déclaré (action corrective
     * « Huile changée » sur le test), soit une vidange a été saisie ensuite. UNE définition pour la tuile, le rappel et l'écran (tour 4 : « Huile changée »
     * ne clôturait rien ; le rappel « à changer » et ses e-mails continuaient).
     */
    public static function isOpen(string $status, ?string $correctiveAction, bool $changedSince): bool
    {
        return in_array($status, ['warn', 'alert'], true) && $correctiveAction !== 'oil_changed' && ! $changedSince;
    }
}
