<?php

namespace App\Services\Hygiene;

use App\Models\HygieneEquipment;
use App\Models\HygieneOilControl;
use App\Models\HygieneReception;
use App\Models\HygieneTaskCompletion;
use App\Models\HygieneTaskTemplate;
use App\Models\HygieneTemperatureReading;
use App\Models\HygieneTraceEntry;
use App\Models\LabelPrintLog;
use App\Support\Hygiene\Kinds;
use Carbon\Carbon;
use Carbon\CarbonInterface;

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] Le dossier de contrôle sanitaire : tout ce qui a été enregistré sur une période, par module,
 * avec les TAUX DE COUVERTURE (relevés et nettoyages réellement faits sur ceux qui étaient attendus) et la liste des INCIDENTS avec
 * l'action corrective prise. Une seule source pour l'écran (résumé), le PDF et les exports CSV : ils ne peuvent pas diverger.
 *
 * LECTURE SEULE : aucune écriture. Les lignes annulées figurent (marquées) dans les journaux — jamais comptées dans les taux.
 */
final class ReportBuilder
{
    /** Au-delà, les lignes d'un journal sont tronquées dans le PDF (le CSV, lui, donne tout). */
    public const ROW_LIMIT = 3000;

    public function __construct(private readonly CarbonInterface $from, private readonly CarbonInterface $to)
    {
    }

    /** Début/fin de journée dans le fuseau de l'application. */
    public static function between(string $from, string $to): self
    {
        $tz = config('app.timezone');

        return new self(Carbon::createFromFormat('Y-m-d', $from, $tz)->startOfDay(), Carbon::createFromFormat('Y-m-d', $to, $tz)->endOfDay());
    }

    /** @return array<string,mixed> */
    public function build(bool $withRows = true): array
    {
        $temperature = $this->temperature($withRows);
        $tasks = $this->tasks($withRows);
        $receptions = $this->receptions($withRows);
        $trace = $this->trace($withRows);
        $oil = $this->oil($withRows);
        $dlc = $this->dlc($withRows);

        $incidents = collect()
            ->merge($temperature['incidents'])->merge($tasks['incidents'])->merge($receptions['incidents'])->merge($trace['incidents'])->merge($oil['incidents'])
            ->sortByDesc('at')->values()->all();

        return [
            'period' => ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'days' => (int) $this->from->copy()->startOfDay()->diffInDays($this->to->copy()->startOfDay()) + 1],
            'temperature' => $temperature, 'tasks' => $tasks, 'receptions' => $receptions, 'trace' => $trace, 'oil' => $oil, 'dlc' => $dlc,
            'incidents' => $incidents,
        ];
    }

    // ───────────────────────────── jours ─────────────────────────────

    /** Les jours de la période déjà écoulés (hier et avant) ; aujourd'hui n'est « attendu » que s'il est déjà fait (la journée n'est pas finie). */
    private function pastDays(): array
    {
        $today = now()->startOfDay();
        $days = [];
        for ($d = $this->from->copy()->startOfDay(); $d->lte($this->to) && $d->lt($today); $d->addDay()) {
            $days[] = $d->toDateString();
        }

        return $days;
    }

    private function includesToday(): bool
    {
        $today = now()->startOfDay();

        return $this->from->lte($today->copy()->endOfDay()) && $this->to->gte($today);
    }

    private function localDate($ts): string
    {
        return Carbon::parse($ts)->timezone(config('app.timezone'))->toDateString();
    }

    private function rate(int $done, int $expected): ?float
    {
        return $expected > 0 ? round($done / $expected * 100, 1) : null;
    }

    // ───────────────────────────── températures ─────────────────────────────

    private function temperature(bool $withRows): array
    {
        $rows = HygieneTemperatureReading::query()->whereBetween('recorded_at', [$this->from, $this->to])->orderBy('recorded_at')->orderBy('id')->get();
        $live = $rows->whereNull('voided_at');
        $equipments = HygieneEquipment::query()->whereIn('kind', Kinds::keys('temperature'))->where('is_active', true)->get();

        $covered = $live->groupBy('equipment_id')->map(fn ($g) => $g->map(fn ($r) => $this->localDate($r->recorded_at))->unique()->flip());
        [$expected, $done] = $this->coverage($equipments, $covered);

        $alerts = $live->where('status', 'alert');

        return [
            'count' => $live->count(), 'voided' => $rows->count() - $live->count(), 'alerts' => $alerts->count(),
            'equipment' => $equipments->count(), 'expected' => $expected, 'covered' => $done, 'coverage_pct' => $this->rate($done, $expected),
            'incidents' => $alerts->map(fn ($r) => [
                'at' => $r->recorded_at->toIso8601String(), 'module' => 'temperature',
                'text' => $r->equipment_name.' : '.str_replace('.', ',', (string) $r->value_c).' °C ('.($r->alert_reason === 'too_cold' ? 'trop froid' : 'trop chaud').')',
                'action' => $r->corrective_action ? (config("hygiene.corrective_actions.{$r->corrective_action}.label").($r->corrective_note ? ' — '.$r->corrective_note : '')) : null,
                'operator' => $r->operator_name,
            ])->values()->all(),
            'rows' => $withRows ? $this->limit($rows->map(fn ($r) => [
                'at' => $r->recorded_at->toIso8601String(), 'equipment' => $r->equipment_name, 'value_c' => $r->value_c, 'min_c' => $r->min_c, 'max_c' => $r->max_c,
                'status' => $r->status, 'period' => $r->period, 'operator' => $r->operator_name,
                'action' => $r->corrective_action ? config("hygiene.corrective_actions.{$r->corrective_action}.label") : null, 'note' => $r->corrective_note,
                'voided' => $r->voided_at !== null, 'void_reason' => $r->void_reason,
            ])->all()) : [],
        ];
    }

    /**
     * Pour chaque équipement : un jour attendu = un jour écoulé depuis sa création ; couvert si au moins une ligne ce jour-là.
     * Aujourd'hui compte seulement s'il est déjà couvert (la journée n'est pas terminée).
     *
     * @param  \Illuminate\Support\Collection<int,HygieneEquipment>  $equipments
     * @return array{0:int,1:int} [attendus, couverts]
     */
    private function coverage($equipments, $coveredByEquipment): array
    {
        $days = $this->pastDays();
        $today = now()->toDateString();
        $todayIn = $this->includesToday();
        $expected = $done = 0;
        foreach ($equipments as $e) {
            $created = $e->created_at ? $this->localDate($e->created_at) : '0000-00-00';
            $set = $coveredByEquipment->get($e->id);
            foreach ($days as $d) {
                if ($d < $created) continue;
                $expected++;
                if ($set && isset($set[$d])) $done++;
            }
            if ($todayIn && $set && isset($set[$today])) { $expected++; $done++; }
        }

        return [$expected, $done];
    }

    // ───────────────────────────── nettoyage ─────────────────────────────

    private function tasks(bool $withRows): array
    {
        $rows = HygieneTaskCompletion::query()->whereBetween('completed_at', [$this->from, $this->to])->orderBy('completed_at')->orderBy('id')->get();
        $live = $rows->whereNull('voided_at');
        $templates = HygieneTaskTemplate::query()->where('is_active', true)->get();

        $byKey = $live->groupBy(fn ($r) => $r->template_id.'|'.$r->due_date->toDateString());
        $days = $this->pastDays();
        $today = now()->startOfDay();
        $todayIn = $this->includesToday();
        $expected = $done = $partial = $missed = 0;
        $missedList = [];
        foreach ($templates as $t) {
            $created = $t->created_at ? $this->localDate($t->created_at) : '0000-00-00';
            $check = function (string $d) use ($t, $byKey, &$expected, &$done, &$partial, &$missed, &$missedList, $created): void {
                if ($d < $created || ! TaskSchedule::isDueOn($t, Carbon::parse($d, config('app.timezone')))) return;
                $g = $byKey->get($t->id.'|'.$d);
                $expected++;
                if ($g && $g->contains('complete', true)) $done++;
                elseif ($g) $partial++;
                else { $missed++; $missedList[] = ['date' => $d, 'task' => $t->name]; }
            };
            foreach ($days as $d) $check($d);
            if ($todayIn) {
                $td = $today->toDateString();
                if ($byKey->has($t->id.'|'.$td)) $check($td); // aujourd'hui : seulement si déjà traité
            }
        }

        $partials = $live->where('complete', false);

        return [
            'count' => $live->count(), 'voided' => $rows->count() - $live->count(), 'expected' => $expected, 'done' => $done, 'partial' => $partial, 'missed' => $missed,
            'completion_pct' => $this->rate($done, $expected), 'missed_list' => array_slice($missedList, -100),
            'incidents' => $partials->map(fn ($r) => [
                'at' => $r->completed_at->toIso8601String(), 'module' => 'tasks',
                'text' => $r->task_name.' : '.$r->items_done.'/'.$r->items_total.' points faits', 'action' => $r->note, 'operator' => $r->operator_name,
            ])->values()->all(),
            'rows' => $withRows ? $this->limit($rows->map(fn ($r) => [
                'at' => $r->completed_at->toIso8601String(), 'due_date' => $r->due_date->toDateString(), 'task' => $r->task_name, 'zone' => $r->zone,
                'items_done' => $r->items_done, 'items_total' => $r->items_total, 'complete' => $r->complete, 'late' => $r->late, 'note' => $r->note,
                'not_done' => collect($r->items)->where('done', false)->pluck('label')->values()->all(), 'operator' => $r->operator_name,
                'voided' => $r->voided_at !== null, 'void_reason' => $r->void_reason,
            ])->all()) : [],
        ];
    }

    // ───────────────────────────── réceptions ─────────────────────────────

    private function receptions(bool $withRows): array
    {
        $rows = HygieneReception::query()->whereBetween('received_at', [$this->from, $this->to])->orderBy('received_at')->orderBy('id')->get();
        $live = $rows->whereNull('voided_at');
        $alerts = $live->where('status', 'alert');

        return [
            'count' => $live->count(), 'voided' => $rows->count() - $live->count(), 'alerts' => $alerts->count(),
            'refused' => $live->where('decision', 'refused')->count(), 'reserve' => $live->where('decision', 'reserve')->count(),
            'incidents' => $alerts->map(fn ($r) => [
                'at' => $r->received_at->toIso8601String(), 'module' => 'receptions',
                'text' => $r->supplier_name.($r->delivery_note_no ? ' · '.$r->delivery_note_no : '').' : '.mb_strtolower((string) config("hygiene.reception.decisions.{$r->decision}")),
                'action' => $r->note, 'operator' => $r->operator_name,
            ])->values()->all(),
            'rows' => $withRows ? $this->limit($rows->map(fn ($r) => [
                'at' => $r->received_at->toIso8601String(), 'supplier' => $r->supplier_name, 'delivery_note' => $r->delivery_note_no, 'driver' => $r->delivered_by,
                'temperatures' => collect($r->temperatures)->map(fn ($t) => ['label' => $t['label'], 'value_c' => $t['value_c'], 'max_c' => $t['max_c'], 'status' => $t['status']])->all(),
                'checks_ko' => collect($r->checks)->where('ok', false)->pluck('label')->values()->all(), 'status' => $r->status,
                'decision' => config("hygiene.reception.decisions.{$r->decision}"), 'note' => $r->note, 'operator' => $r->operator_name,
                'voided' => $r->voided_at !== null, 'void_reason' => $r->void_reason,
            ])->all()) : [],
        ];
    }

    // ───────────────────────────── traçabilité ─────────────────────────────

    private function trace(bool $withRows): array
    {
        $rows = HygieneTraceEntry::query()->whereBetween('traced_at', [$this->from, $this->to])->orderBy('traced_at')->orderBy('id')->get();
        $live = $rows->whereNull('voided_at');
        $alerts = $live->where('status', 'alert');

        return [
            'count' => $live->count(), 'voided' => $rows->count() - $live->count(), 'alerts' => $alerts->count(), 'without_lot' => $live->whereNull('lot')->count(),
            'incidents' => $alerts->map(fn ($r) => [
                'at' => $r->traced_at->toIso8601String(), 'module' => 'trace', 'text' => $r->product_name.' (lot '.($r->lot ?: '—').') : DLC dépassée à la réception',
                'action' => $r->note, 'operator' => $r->operator_name,
            ])->values()->all(),
            'rows' => $withRows ? $this->limit($rows->map(fn ($r) => [
                'at' => $r->traced_at->toIso8601String(), 'product' => $r->product_name, 'supplier' => $r->supplier_name, 'delivery_note' => $r->delivery_note_no, 'lot' => $r->lot,
                'dlc_date' => $r->dlc_date?->toDateString(), 'dlc_kind' => $r->dlc_kind, 'status' => $r->status, 'note' => $r->note, 'operator' => $r->operator_name,
                'voided' => $r->voided_at !== null, 'void_reason' => $r->void_reason,
            ])->all()) : [],
        ];
    }

    // ───────────────────────────── huile ─────────────────────────────

    private function oil(bool $withRows): array
    {
        $rows = HygieneOilControl::query()->whereBetween('performed_at', [$this->from, $this->to])->orderBy('performed_at')->orderBy('id')->get();
        $live = $rows->whereNull('voided_at');
        $tests = $live->where('type', 'test');
        $fryers = HygieneEquipment::query()->where('kind', 'fryer')->where('is_active', true)->get();
        $covered = $tests->groupBy('equipment_id')->map(fn ($g) => $g->map(fn ($r) => $this->localDate($r->performed_at))->unique()->flip());
        [$expected, $done] = $this->coverage($fryers, $covered);
        $alerts = $live->where('status', 'alert');

        return [
            'tests' => $tests->count(), 'filters' => $live->where('type', 'filter')->count(), 'changes' => $live->where('type', 'change')->count(),
            'voided' => $rows->count() - $live->count(), 'alerts' => $alerts->count(), 'fryers' => $fryers->count(),
            'expected' => $expected, 'covered' => $done, 'coverage_pct' => $this->rate($done, $expected),
            'max_polar' => $tests->max('polar_pct'),
            'incidents' => $alerts->map(fn ($r) => [
                'at' => $r->performed_at->toIso8601String(), 'module' => 'oil',
                'text' => $r->equipment_name.' : '.($r->polar_pct !== null ? str_replace('.', ',', (string) $r->polar_pct).' % de composés polaires' : '').(in_array('bath_hot', explode(',', (string) $r->reasons), true) ? ' · bain '.str_replace('.', ',', (string) $r->bath_temp_c).' °C' : ''),
                'action' => $r->corrective_action ? (config("hygiene.oil.corrective_actions.{$r->corrective_action}.label").($r->corrective_note ? ' — '.$r->corrective_note : '')) : null,
                'operator' => $r->operator_name,
            ])->values()->all(),
            'rows' => $withRows ? $this->limit($rows->map(fn ($r) => [
                'at' => $r->performed_at->toIso8601String(), 'fryer' => $r->equipment_name, 'type' => config("hygiene.oil.types.{$r->type}"), 'polar_pct' => $r->polar_pct,
                'bath_temp_c' => $r->bath_temp_c, 'oil_litres' => $r->oil_litres, 'oil_brand' => $r->oil_brand, 'status' => $r->status,
                'action' => $r->corrective_action ? config("hygiene.oil.corrective_actions.{$r->corrective_action}.label") : null, 'note' => $r->corrective_note ?: $r->note,
                'operator' => $r->operator_name, 'voided' => $r->voided_at !== null, 'void_reason' => $r->void_reason,
            ])->all()) : [],
        ];
    }

    // ───────────────────────────── DLC (étiquettes) ─────────────────────────────

    private function dlc(bool $withRows): array
    {
        $rows = LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_DLC)->whereIn('status', LabelPrintLog::DONE_STATUSES)
            ->whereBetween('opened_at', [$this->from, $this->to])->orderBy('opened_at')->orderBy('id')->limit(self::ROW_LIMIT + 1)->get();
        $now = now();
        $kept = $rows->filter(fn ($l) => $l->discard_reason !== LabelPrintLog::DISCARD_CANCELLED);

        return [
            'count' => $kept->count(), 'copies' => (int) $kept->sum('copies'),
            'discarded' => $kept->where('discard_reason', LabelPrintLog::DISCARD_DISCARDED)->whereNotNull('discarded_at')->count(),
            'used' => $kept->where('discard_reason', LabelPrintLog::DISCARD_USED)->whereNotNull('discarded_at')->count(),
            // échus et jamais sortis : le point que regarde un contrôleur
            'expired_not_out' => $kept->filter(fn ($l) => $l->discarded_at === null && $l->expires_at !== null && $l->expires_at->lt($now))->count(),
            'rows' => $withRows ? $this->limit($kept->map(fn ($l) => [
                'at' => $l->opened_at?->toIso8601String(), 'product' => $l->product_name, 'action' => $l->action, 'lot' => $l->lot, 'supplier_lot' => $l->supplier_lot,
                'expires_at' => $l->expires_at?->toIso8601String(), 'copies' => $l->copies, 'storage' => $l->storage,
                'out' => $l->discarded_at ? ['at' => $l->discarded_at->toIso8601String(), 'reason' => $l->discard_reason ?: LabelPrintLog::DISCARD_DISCARDED] : null,
            ])->values()->all()) : [],
        ];
    }

    private function limit(array $rows): array
    {
        return count($rows) > self::ROW_LIMIT ? array_slice($rows, 0, self::ROW_LIMIT) : $rows;
    }
}
