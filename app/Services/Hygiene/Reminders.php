<?php

namespace App\Services\Hygiene;

use App\Models\HygieneEquipment;
use App\Models\HygieneOilControl;
use App\Models\HygieneTaskCompletion;
use App\Models\HygieneTaskTemplate;
use App\Models\HygieneTemperatureReading;
use App\Models\HygieneTraceEntry;
use App\Models\LabelPrintLog;
use App\Support\Hygiene\Kinds;
use Carbon\CarbonInterface;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · méga-fichier §7] Ce qui est EN RETARD ou à traiter MAINTENANT, dit en une phrase et relié à son écran :
 * relevé du matin ou du soir non fait, nettoyage en retard, huile à changer ou test non fait, DLC dépassées, rien tracé depuis N jours.
 * LECTURE SEULE, calculée côté serveur à partir des mêmes journaux que les écrans (une seule vérité) ; l'accueil l'affiche et la tuile
 * du domaine passe en alerte (App\Services\Hygiene\Overview).
 *
 * Aucune heure n'est une obligation légale : ce sont des rappels réglables (config/hygiene.php › reminders). Un rappel qui n'a pas de
 * sens pour ce restaurant ne se déclenche pas (pas de relevé du soir si on ne relève jamais le soir).
 *
 * level : alert (à traiter maintenant) · todo (à faire, sans urgence).
 */
final class Reminders
{
    /** Au-delà de ce nombre de fiches de nettoyage en retard, les rappels se regroupent en une seule phrase. */
    private const LATE_TASKS_LISTED = 3;

    /** @return list<array{key:string, domain:string, level:string, text:string, route:string}> */
    public function all(?CarbonInterface $now = null): array
    {
        $now = $now ?? now();

        return array_values(array_merge($this->temperature($now), $this->tasks($now), $this->oil($now), $this->dlc($now), $this->trace($now)));
    }

    private function item(string $key, string $domain, string $level, string $text, string $route): array
    {
        return compact('key', 'domain', 'level', 'text', 'route');
    }

    /** « 11:30 » → ce jour-là à 11 h 30 (fuseau de l'application) ; null si la valeur est absente ou illisible (rappel désactivé). */
    private function at(CarbonInterface $now, mixed $hhmm): ?CarbonInterface
    {
        if (! is_string($hhmm) || ! preg_match('/^([01]\d|2[0-3]):([0-5]\d)$/', $hhmm, $m)) return null;

        return $now->copy()->startOfDay()->setTime((int) $m[1], (int) $m[2]);
    }

    /** « A, B, C (+2) » : trois noms au plus, le reste compté. @param list<string> $names */
    private function names(array $names): string
    {
        $shown = array_slice($names, 0, 3);

        return implode(', ', $shown).(count($names) > 3 ? ' (+'.(count($names) - 3).')' : '');
    }

    /** @return list<array<string,mixed>> */
    private function temperature(CarbonInterface $now): array
    {
        $all = HygieneEquipment::query()->whereIn('kind', Kinds::keys('temperature'))->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'kind', 'created_at']);
        if ($all->isEmpty()) return [];
        $start = $now->copy()->startOfDay();
        $readings = HygieneTemperatureReading::query()->whereNull('voided_at')->where('recorded_at', '>=', $start)->whereIn('equipment_id', $all->pluck('id'))->get(['equipment_id', 'period']);
        $byPeriod = $readings->groupBy('period')->map(fn ($rows) => $rows->pluck('equipment_id')->unique()->all());
        $anyToday = $readings->pluck('equipment_id')->unique()->all();

        $out = [];
        foreach (['morning' => 'matin', 'evening' => 'soir'] as $period => $label) {
            $deadline = $this->at($now, config("hygiene.reminders.temperature.$period"));
            if ($deadline === null || $now->lt($deadline)) continue;
            // le soir : seulement si le restaurant relève réellement le soir (sinon le rappel râlerait chaque jour pour rien)
            if ($period === 'evening' && ! HygieneTemperatureReading::query()->whereNull('voided_at')->where('period', 'evening')->where('recorded_at', '>=', $start->copy()->subDays(7))->exists()) continue;
            // Matin : « fait » = relevé de CE jour, quelle que soit l'heure (relevé pris à 15 h 05 ou une seule fois par jour à 16 h : pas de fausse
            // alerte permanente) ; soir : un relevé du soir. Un équipement créé APRÈS l'heure du rappel n'était pas encore attendu (même règle que le
            // dossier de contrôle) ; un bain-marie ne sert qu'au service : il n'est pas exigé au relevé du matin.
            $done = $period === 'morning' ? $anyToday : $byPeriod->get($period, []);
            $missing = $all->reject(fn ($e) => in_array($e->id, $done, true) || $e->created_at->gte($deadline)
                || ($period === 'morning' && config("hygiene.kinds.{$e->kind}.hot")));
            if ($missing->isEmpty()) continue;
            $out[] = $this->item("temperature-late-$period", 'temperature', 'alert',
                "Relevé du $label non fait : ".$this->names($missing->pluck('name')->all()).'.', '/admin/hygiene/temperature');
        }

        // Écart traité mais PAS encore prouvé : la nouvelle mesure est attendue (`followup.minutes` après l'écart) — rappel urgent tant qu'elle manque.
        $names = $all->pluck('name', 'id');
        foreach (TemperatureFollowUp::pending($all->pluck('id')->all(), $now) as $equipmentId => $f) {
            if (! $f['overdue']) continue;
            $due = \Carbon\Carbon::parse($f['due_at'])->setTimezone($now->getTimezone());
            $at = \Carbon\Carbon::parse($f['recorded_at'])->setTimezone($now->getTimezone());
            $v = str_replace('.', ',', rtrim(rtrim(number_format($f['value_c'], 1, '.', ''), '0'), '.'));
            $out[] = $this->item("temperature-followup-$equipmentId-{$f['reading_id']}", 'temperature', 'alert',
                'Contrôle de suite à faire : « '.($names[$equipmentId] ?? 'Équipement')." » était à $v °C à ".$at->format('H:i').' — nouvelle mesure attendue depuis '.$due->format('H:i').'.', '/admin/hygiene/temperature');
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function tasks(CarbonInterface $now): array
    {
        $day = $now->copy()->startOfDay();
        $templates = HygieneTaskTemplate::query()->where('is_active', true)->get()->filter(fn ($t) => TaskSchedule::isDueOn($t, $day));
        if ($templates->isEmpty()) return [];
        $done = HygieneTaskCompletion::query()->whereNull('voided_at')->whereDate('due_date', $day->toDateString())->pluck('template_id')->unique()->all();
        $late = [];
        foreach ($templates as $t) {
            $due = TaskSchedule::dueAt($t, $day);
            // une fiche créée APRÈS son heure limite du jour n'était pas encore attendue
            if ($due === null || in_array($t->id, $done, true) || $now->lte($due) || $t->created_at->gte($due)) continue;
            $late[] = [$t, $due];
        }
        // Jusqu'à 3 fiches : une phrase chacune. Au-delà, UNE phrase (10 lignes quasi identiques enterraient le relevé de température non fait, sous le pli d'un téléphone).
        if (count($late) <= self::LATE_TASKS_LISTED) {
            return array_map(fn ($l) => $this->item("tasks-late-{$l[0]->id}", 'tasks', 'alert', "Nettoyage en retard : « {$l[0]->name} » (prévu à ".$l[1]->format('H:i').').', '/admin/hygiene/tasks'), $late);
        }
        $names = array_map(fn ($l) => '« '.$l[0]->name.' »', array_slice($late, 0, self::LATE_TASKS_LISTED));
        $more = count($late) - self::LATE_TASKS_LISTED;

        return [$this->item('tasks-late-many', 'tasks', 'alert', count($late).' nettoyages en retard : '.implode(', ', $names).' et '.$more.' autre'.($more > 1 ? 's' : '').'.', '/admin/hygiene/tasks')];
    }

    /** @return list<array<string,mixed>> */
    private function oil(CarbonInterface $now): array
    {
        $fryers = HygieneEquipment::query()->where('kind', 'fryer')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name', 'created_at']);
        if ($fryers->isEmpty()) return [];
        $start = $now->copy()->startOfDay();
        $rows = HygieneOilControl::query()->whereNull('voided_at')->whereIn('equipment_id', $fryers->pluck('id'))->where('performed_at', '>=', $start->copy()->subDays(60))
            ->orderByDesc('performed_at')->orderByDesc('id')->get()->groupBy('equipment_id');

        $out = [];
        $limit = (float) config('hygiene.oil.polar_limit');
        foreach ($fryers as $f) {
            $mine = $rows->get($f->id, collect());
            $lastTest = $mine->first(fn ($r) => $r->type === 'test');
            if ($lastTest && in_array($lastTest->status, ['warn', 'alert'], true)) {
                // l'huile a été changée depuis ce test : le rappel n'a plus lieu d'être
                $changedSince = $mine->contains(fn ($r) => $r->type === 'change' && $r->performed_at->gte($lastTest->performed_at));
                if (! $changedSince) {
                    $pct = str_replace('.', ',', rtrim(rtrim(number_format((float) $lastTest->polar_pct, 1, '.', ''), '0'), '.'));
                    $over = $lastTest->polar_pct !== null && (float) $lastTest->polar_pct > $limit;
                    // le VRAI motif de l'écart : un bain trop chaud avec des polaires bas n'est pas « 10 % — changement à prévoir »
                    $bathOnly = ! $over && $lastTest->status === 'alert' && str_contains((string) $lastTest->reasons, 'bath_hot');
                    $bath = $lastTest->bath_temp_c !== null ? str_replace('.', ',', rtrim(rtrim(number_format((float) $lastTest->bath_temp_c, 1, '.', ''), '0'), '.')) : '';
                    $out[] = $this->item("oil-change-{$f->id}", 'oil', $over || $bathOnly ? 'alert' : 'todo',
                        $bathOnly ? "Huile « {$f->name} » : bain à $bath °C, trop chaud — à régler."
                        : ($over ? "Huile « {$f->name} » : $pct % de composés polaires, au-dessus de la limite légale — à changer."
                                 : "Huile « {$f->name} » : $pct % de composés polaires — changement à prévoir."), '/admin/hygiene/oil');
                }
            }
        }

        // test du jour non fait après l'heure : seulement si le restaurant teste réellement (un test dans les 7 derniers jours)
        $deadline = $this->at($now, config('hygiene.reminders.oil_test'));
        if ($deadline !== null && $now->gte($deadline)) {
            $practised = $rows->flatten(1)->contains(fn ($r) => $r->type === 'test' && $r->performed_at->gte($start->copy()->subDays(7)));
            if ($practised) {
                $missing = $fryers->reject(fn ($f) => $f->created_at->gte($deadline) || $rows->get($f->id, collect())->contains(fn ($r) => $r->type === 'test' && $r->performed_at->gte($start)));
                if ($missing->isNotEmpty()) {
                    $out[] = $this->item('oil-test-late', 'oil', 'todo', 'Test d’huile non fait : '.$this->names($missing->pluck('name')->all()).'.', '/admin/hygiene/oil');
                }
            }
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function dlc(CarbonInterface $now): array
    {
        $expired = LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_DLC)->whereIn('status', LabelPrintLog::DONE_STATUSES)->whereNull('discarded_at')
            ->whereNotNull('expires_at')->where('expires_at', '<', $now)->where('expires_at', '>=', $now->copy()->subDays(LabelPrintLog::WATCH_BEHIND_DAYS))->count();
        if ($expired === 0) return [];

        return [$this->item("dlc-expired-$expired", 'dlc', 'alert', $expired.' étiquette'.($expired > 1 ? 's' : '').' DLC dépassée'.($expired > 1 ? 's' : '').' : à jeter, ou à marquer « utilisé ».', '/admin/labels/dlc')];
    }

    /** @return list<array<string,mixed>> */
    private function trace(CarbonInterface $now): array
    {
        $days = (int) config('hygiene.reminders.trace_silent_days');
        if ($days < 1) return [];
        $last = HygieneTraceEntry::query()->whereNull('voided_at')->max('traced_at');
        if ($last === null) return []; // la traçabilité ne sert pas encore : le rappel n'a pas de sens
        $silent = (int) \Carbon\Carbon::parse($last)->startOfDay()->diffInDays($now->copy()->startOfDay());
        if ($silent < $days) return [];

        return [$this->item('trace-silent', 'trace', 'todo', "Aucun produit tracé depuis $silent jours.", '/admin/hygiene/trace')];
    }
}
