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
        $equipments = HygieneEquipment::query()->whereIn('kind', Kinds::keys('temperature'))->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
        if ($equipments->isEmpty()) return [];
        $start = $now->copy()->startOfDay();
        $byPeriod = HygieneTemperatureReading::query()->whereNull('voided_at')->where('recorded_at', '>=', $start)->whereIn('equipment_id', $equipments->pluck('id'))
            ->get(['equipment_id', 'period'])->groupBy('period')->map(fn ($rows) => $rows->pluck('equipment_id')->unique()->all());

        $out = [];
        foreach (['morning' => 'matin', 'evening' => 'soir'] as $period => $label) {
            $deadline = $this->at($now, config("hygiene.reminders.temperature.$period"));
            if ($deadline === null || $now->lt($deadline)) continue;
            // le soir : seulement si le restaurant relève réellement le soir (sinon le rappel râlerait chaque jour pour rien)
            if ($period === 'evening' && ! HygieneTemperatureReading::query()->whereNull('voided_at')->where('period', 'evening')->where('recorded_at', '>=', $start->copy()->subDays(7))->exists()) continue;
            $done = $byPeriod->get($period, []);
            $missing = $equipments->reject(fn ($e) => in_array($e->id, $done, true));
            if ($missing->isEmpty()) continue;
            $out[] = $this->item("temperature-late-$period", 'temperature', 'alert',
                "Relevé du $label non fait : ".$this->names($missing->pluck('name')->all()).'.', '/admin/hygiene/temperature');
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
        $out = [];
        foreach ($templates as $t) {
            $due = TaskSchedule::dueAt($t, $day);
            if ($due === null || in_array($t->id, $done, true) || $now->lte($due)) continue;
            $out[] = $this->item("tasks-late-{$t->id}", 'tasks', 'alert', "Nettoyage en retard : « {$t->name} » (prévu à ".$due->format('H:i').').', '/admin/hygiene/tasks');
        }

        return $out;
    }

    /** @return list<array<string,mixed>> */
    private function oil(CarbonInterface $now): array
    {
        $fryers = HygieneEquipment::query()->where('kind', 'fryer')->where('is_active', true)->orderBy('sort_order')->orderBy('id')->get(['id', 'name']);
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
                    $out[] = $this->item("oil-change-{$f->id}", 'oil', $over ? 'alert' : 'todo',
                        $over ? "Huile « {$f->name} » : $pct % de composés polaires, au-dessus de la limite légale — à changer."
                              : "Huile « {$f->name} » : $pct % de composés polaires — changement à prévoir.", '/admin/hygiene/oil');
                }
            }
        }

        // test du jour non fait après l'heure : seulement si le restaurant teste réellement (un test dans les 7 derniers jours)
        $deadline = $this->at($now, config('hygiene.reminders.oil_test'));
        if ($deadline !== null && $now->gte($deadline)) {
            $practised = $rows->flatten(1)->contains(fn ($r) => $r->type === 'test' && $r->performed_at->gte($start->copy()->subDays(7)));
            if ($practised) {
                $missing = $fryers->reject(fn ($f) => $rows->get($f->id, collect())->contains(fn ($r) => $r->type === 'test' && $r->performed_at->gte($start)));
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

        return [$this->item('dlc-expired', 'dlc', 'alert', $expired.' étiquette'.($expired > 1 ? 's' : '').' DLC dépassée'.($expired > 1 ? 's' : '').' : à jeter, ou à marquer « utilisé ».', '/admin/labels/dlc')];
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
