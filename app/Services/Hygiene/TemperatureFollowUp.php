<?php

namespace App\Services\Hygiene;

use App\Models\HygieneTemperatureReading;
use Carbon\CarbonInterface;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia P1 « relevé de suivi après écart »] Un écart traité par « porte refermée », « dégivrage »,
 * « plats réchauffés » ou « nouvelle mesure » n'est PAS clos tant qu'une nouvelle mesure n'a pas prouvé le retour à la normale.
 *
 * On suit chaque équipement dans l'ordre de ses relevés (non annulés, des dernières 24 h). Un écart dont l'action est marquée `followup`
 * (config/hygiene.php) OUVRE une attente, due `followup.minutes` après lui. Elle se CLÔT par un relevé pris au moins `followup.min_minutes` après
 * l'écart (conforme OU non : un nouvel écart ouvre la sienne) — une mesure prise une minute après n'a pas laissé le temps de redescendre et ne
 * prouve rien. Elle se clôt aussi par `followup_closed_at` : le responsable du geste a dit POURQUOI la mesure est impossible (équipement éteint,
 * en panne…), ce qui reste au journal. Annuler le relevé d'écart (erreur de saisie) la supprime. LECTURE SEULE, une seule vérité pour l'écran
 * des relevés et les rappels.
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
        $minGap = (int) config('hygiene.followup.min_minutes', 10);

        $byEquipment = HygieneTemperatureReading::query()->whereNull('voided_at')->whereIn('equipment_id', $equipmentIds)
            ->where('recorded_at', '>=', $now->copy()->subHours(24))->where('recorded_at', '<=', $now)
            ->orderBy('recorded_at')->orderBy('id')->get()->groupBy('equipment_id');

        $out = [];
        foreach ($byEquipment as $equipmentId => $rows) {
            $open = null;
            foreach ($rows as $r) {
                // une mesure prise assez longtemps après l'écart (ou une clôture motivée) ferme l'attente en cours
                if ($open !== null && $r->recorded_at->gte($open->recorded_at->copy()->addMinutes($minGap))) $open = null;
                if ($r->status === HygieneTemperatureReading::STATUS_ALERT && config("hygiene.corrective_actions.{$r->corrective_action}.followup") && $r->followup_closed_at === null) {
                    $open = $r;
                }
            }
            if ($open === null) continue;
            $due = $open->recorded_at->copy()->addMinutes($minutes);
            $out[(int) $equipmentId] = [
                'reading_id' => $open->id, 'value_c' => (float) $open->value_c, 'recorded_at' => $open->recorded_at->toIso8601String(),
                'due_at' => $due->toIso8601String(), 'overdue' => $now->gte($due), 'minutes' => $minutes, 'min_minutes' => $minGap,
            ];
        }

        return $out;
    }
}
