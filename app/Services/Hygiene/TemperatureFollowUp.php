<?php

namespace App\Services\Hygiene;

use App\Models\HygieneTemperatureReading;
use Carbon\CarbonInterface;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « relevé de suivi après écart »] Un écart traité par « porte refermée », « dégivrage »,
 * « plats réchauffés » ou « nouvelle mesure » n'est PAS clos tant qu'une nouvelle mesure n'a pas prouvé le retour à la normale.
 *
 * Un suivi est ATTENDU pour un équipement quand son DERNIER relevé (non annulé, des dernières 24 h) est un écart dont l'action corrective est
 * marquée `followup` (config/hygiene.php) et qu'aucun relevé plus récent n'existe. Il est DÛ `followup.minutes` après l'écart. Un relevé plus
 * récent, conforme OU non, clôt cette attente (un nouvel écart ouvre la sienne). Annuler le relevé d'écart (erreur de saisie) la supprime.
 * LECTURE SEULE, une seule vérité pour l'écran des relevés et les rappels.
 */
final class TemperatureFollowUp
{
    /**
     * @param list<int> $equipmentIds
     * @return array<int, array{reading_id:int, value_c:float, recorded_at:string, due_at:string, overdue:bool, minutes:int}> indexé par equipment_id
     */
    public static function pending(array $equipmentIds, ?CarbonInterface $now = null): array
    {
        $now = $now ?? now();
        if ($equipmentIds === []) return [];
        $minutes = (int) config('hygiene.followup.minutes', 30);

        $last = HygieneTemperatureReading::query()->whereNull('voided_at')->whereIn('equipment_id', $equipmentIds)
            ->where('recorded_at', '>=', $now->copy()->subHours(24))->where('recorded_at', '<=', $now)
            ->orderBy('recorded_at')->orderBy('id')->get()->groupBy('equipment_id')->map(fn ($rows) => $rows->last());

        $out = [];
        foreach ($last as $equipmentId => $r) {
            if ($r->status !== HygieneTemperatureReading::STATUS_ALERT) continue;
            if (! config("hygiene.corrective_actions.{$r->corrective_action}.followup")) continue;
            $due = $r->recorded_at->copy()->addMinutes($minutes);
            $out[(int) $equipmentId] = [
                'reading_id' => $r->id, 'value_c' => (float) $r->value_c, 'recorded_at' => $r->recorded_at->toIso8601String(),
                'due_at' => $due->toIso8601String(), 'overdue' => $now->gte($due), 'minutes' => $minutes,
            ];
        }

        return $out;
    }
}
