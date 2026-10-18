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
    /** Lignes de journal par domaine dans le PDF : au-delà, les PLUS RÉCENTES sont listées et le PDF dit combien manquent (le CSV donne tout). */
    public const PDF_ROW_CAP = 250;

    /** null = toutes les lignes (CSV) ; un nombre = les plus récentes seulement (PDF). Les compteurs et taux, eux, portent TOUJOURS sur la période entière. */
    private ?int $rowCap = null;

    public function __construct(private readonly CarbonInterface $from, private readonly CarbonInterface $to)
    {
    }

    public function capRows(?int $n): self
    {
        $this->rowCap = $n;

        return $this;
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

    /** Les jours de la période déjà écoulés (hier et avant) ; aujourd'hui n'entre jamais dans le taux (la journée n'est pas finie : il est dit à part). */
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

    /** Dernier jour où l'élément était attendu : sa suppression, ou le moment où il a été masqué (approximé par sa dernière modification). null = toujours actif. */
    private function endDate($model): ?string
    {
        if ($model->deleted_at) return $this->localDate($model->deleted_at);

        return $model->is_active ? null : ($model->updated_at ? $this->localDate($model->updated_at) : null);
    }

    /** Un élément masqué/supprimé avant le début de la période n'a rien attendu pendant celle-ci. */
    private function stillRelevant($model): bool
    {
        $end = $this->endDate($model);

        return $end === null || $end >= $this->from->toDateString();
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
        // masqué ou supprimé : ses jours manqués AVANT ce moment restent attendus (masquer un frigo n'efface pas les trous de la période)
        $equipments = HygieneEquipment::query()->withTrashed()->whereIn('kind', Kinds::keys('temperature'))->get()->filter(fn ($e) => $this->stillRelevant($e));

        $covered = $live->groupBy('equipment_id')->map(fn ($g) => $g->map(fn ($r) => $this->localDate($r->recorded_at))->unique()->flip());
        [$expected, $done, $todayTotal, $todayDone] = $this->coverage($equipments, $covered);

        $alerts = $live->where('status', 'alert');

        return [
            'count' => $live->count(), 'offline' => $live->where('source', 'offline')->count(), 'voided' => $rows->count() - $live->count(), 'alerts' => $alerts->count(),
            'equipment' => $equipments->where('is_active', true)->whereNull('deleted_at')->count(), 'expected' => $expected, 'covered' => $done, 'coverage_pct' => $this->rate($done, $expected),
            // la journée EN COURS n'est pas finie : jamais comptée dans le taux (ni comme faite, ni comme manquée), dite à part, comme l'accueil
            'today' => $this->includesToday() ? ['expected' => $todayTotal, 'done' => $todayDone] : null,
            'incidents' => $alerts->map(fn ($r) => [
                'at' => $r->recorded_at->toIso8601String(), 'module' => 'temperature',
                'text' => $r->equipment_name.' : '.str_replace('.', ',', (string) $r->value_c).' °C ('.($r->alert_reason === 'too_cold' ? 'trop froid' : 'trop chaud').')',
                'action' => $r->corrective_action ? (config("hygiene.corrective_actions.{$r->corrective_action}.label").($r->corrective_note ? ' — '.$r->corrective_note : '')) : null,
                'operator' => $r->operator_name,
            ])->values()->all(),
            ...($withRows ? $this->capped($rows->map(fn ($r) => [
                'at' => $r->recorded_at->toIso8601String(), 'equipment' => $r->equipment_name, 'value_c' => $r->value_c, 'min_c' => $r->min_c, 'max_c' => $r->max_c,
                'status' => $r->status, 'period' => $r->period, 'operator' => $r->operator_name,
                'action' => $r->corrective_action ? config("hygiene.corrective_actions.{$r->corrective_action}.label") : null, 'note' => $r->corrective_note,
                'offline' => $r->source === 'offline', 'synced_at' => $r->source === 'offline' ? $r->created_at?->toIso8601String() : null,
                'followup_closed' => $r->followup_close_reason,
                'voided' => $r->voided_at !== null, 'void_reason' => $r->void_reason,
            ])->all()) : ['rows' => [], 'rows_total' => 0]),
        ];
    }

    /**
     * Pour chaque équipement : un jour attendu = un jour ÉCOULÉ entre sa création et son retrait ; couvert si au moins une ligne ce jour-là.
     *  - Le jour de CRÉATION et le jour de RETRAIT sont des journées partielles (l'heure n'est pas connue : « installer le plan puis retirer ce qui ne
     *    convient pas » le jour même) : comptées seulement si couvertes, JAMAIS « manquées » (tour 4 : un frigo créé puis supprimé le même jour
     *    abaissait le taux d'un dossier d'inspection, sans moyen de le retirer).
     *  - AUJOURD'HUI n'entre pas dans le taux : la journée n'est pas finie. Il est dit à part (`today`), calculé comme la tuile de l'accueil.
     *
     * @param  \Illuminate\Support\Collection<int,HygieneEquipment>  $equipments
     * @return array{0:int,1:int,2:int,3:int} [attendus, couverts, aujourd'hui attendus, aujourd'hui faits]
     */
    private function coverage($equipments, $coveredByEquipment): array
    {
        $days = $this->pastDays();
        $today = now()->toDateString();
        $todayIn = $this->includesToday();
        $expected = $done = $todayTotal = $todayDone = 0;
        foreach ($equipments as $e) {
            $created = $e->created_at ? $this->localDate($e->created_at) : '0000-00-00';
            $end = $this->endDate($e);
            $set = $coveredByEquipment->get($e->id);
            foreach ($days as $d) {
                if ($d < $created || ($end !== null && $d > $end)) continue;
                $isCovered = (bool) ($set && isset($set[$d]));
                if (! $isCovered && ($d === $created || $d === $end)) continue;
                $expected++;
                if ($isCovered) $done++;
            }
            if ($todayIn && $end === null && $today >= $created) {
                $todayTotal++;
                if ($set && isset($set[$today])) $todayDone++;
            }
        }

        return [$expected, $done, $todayTotal, $todayDone];
    }

    // ───────────────────────────── nettoyage ─────────────────────────────

    private function tasks(bool $withRows): array
    {
        $rows = HygieneTaskCompletion::query()->whereBetween('completed_at', [$this->from, $this->to])->orderBy('completed_at')->orderBy('id')->get();
        $live = $rows->whereNull('voided_at');
        $templates = HygieneTaskTemplate::query()->withTrashed()->get()->filter(fn ($t) => $this->stillRelevant($t));

        $byKey = $live->groupBy(fn ($r) => $r->template_id.'|'.$r->due_date->toDateString());
        $days = $this->pastDays();
        $today = now()->startOfDay();
        $todayIn = $this->includesToday();
        $expected = $done = $partial = $missed = 0;
        $todayTotal = $todayDone = $todayPartial = 0;
        $missedList = [];
        foreach ($templates as $t) {
            $created = $t->created_at ? $this->localDate($t->created_at) : '0000-00-00';
            $end = $this->endDate($t);
            $check = function (string $d) use ($t, $byKey, &$expected, &$done, &$partial, &$missed, &$missedList, $created, $end): void {
                if ($d < $created || ($end !== null && $d > $end) || ! TaskSchedule::isDueOn($t, Carbon::parse($d, config('app.timezone')))) return;
                $g = $byKey->get($t->id.'|'.$d);
                // jour de création / de retrait : journée partielle (heure inconnue), comptée seulement si la fiche a été traitée — jamais « manquée »
                if ($g === null && ($d === $created || $d === $end)) return;
                $expected++;
                if ($g && $g->contains('complete', true)) $done++;
                elseif ($g) $partial++;
                else { $missed++; $missedList[] = ['date' => $d, 'task' => $t->name]; }
            };
            foreach ($days as $d) $check($d);
            // aujourd'hui : hors du taux (journée en cours), dit à part comme la tuile de l'accueil (fiches actives dues aujourd'hui)
            if ($todayIn && $end === null && $today->toDateString() >= $created && TaskSchedule::isDueOn($t, $today)) {
                $g = $byKey->get($t->id.'|'.$today->toDateString());
                $todayTotal++;
                if ($g && $g->contains('complete', true)) $todayDone++;
                elseif ($g) $todayPartial++;
            }
        }

        $partials = $live->where('complete', false);

        return [
            'count' => $live->count(), 'voided' => $rows->count() - $live->count(), 'expected' => $expected, 'done' => $done, 'partial' => $partial, 'missed' => $missed,
            'completion_pct' => $this->rate($done, $expected), 'missed_list' => array_slice($missedList, -100),
            'today' => $todayIn ? ['expected' => $todayTotal, 'done' => $todayDone, 'partial' => $todayPartial] : null,
            'incidents' => $partials->map(fn ($r) => [
                'at' => $r->completed_at->toIso8601String(), 'module' => 'tasks',
                'text' => $r->task_name.' : '.$r->items_done.'/'.$r->items_total.' points faits', 'action' => $r->note, 'operator' => $r->operator_name,
            ])->values()->all(),
            ...($withRows ? $this->capped($rows->map(fn ($r) => [
                'at' => $r->completed_at->toIso8601String(), 'due_date' => $r->due_date->toDateString(), 'task' => $r->task_name, 'zone' => $r->zone,
                'items_done' => $r->items_done, 'items_total' => $r->items_total, 'complete' => $r->complete, 'late' => $r->late, 'note' => $r->note,
                'not_done' => collect($r->items)->where('done', false)->pluck('label')->values()->all(), 'operator' => $r->operator_name,
                'attested' => $r->attested_at !== null,
                'voided' => $r->voided_at !== null, 'void_reason' => $r->void_reason,
            ])->all()) : ['rows' => [], 'rows_total' => 0]),
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
            ...($withRows ? $this->capped($rows->map(fn ($r) => [
                'at' => $r->received_at->toIso8601String(), 'supplier' => $r->supplier_name, 'delivery_note' => $r->delivery_note_no, 'driver' => $r->delivered_by,
                'temperatures' => collect($r->temperatures)->map(fn ($t) => ['label' => $t['label'], 'value_c' => $t['value_c'], 'max_c' => $t['max_c'], 'status' => $t['status']])->all(),
                'checks_ko' => collect($r->checks)->where('ok', false)->pluck('label')->values()->all(), 'status' => $r->status,
                'decision' => config("hygiene.reception.decisions.{$r->decision}"), 'note' => $r->note, 'operator' => $r->operator_name,
                'voided' => $r->voided_at !== null, 'void_reason' => $r->void_reason,
            ])->all()) : ['rows' => [], 'rows_total' => 0]),
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
            ...($withRows ? $this->capped($rows->map(fn ($r) => [
                'at' => $r->traced_at->toIso8601String(), 'product' => $r->product_name, 'supplier' => $r->supplier_name, 'delivery_note' => $r->delivery_note_no, 'lot' => $r->lot,
                'dlc_date' => $r->dlc_date?->toDateString(), 'dlc_kind' => $r->dlc_kind, 'status' => $r->status, 'note' => $r->note, 'operator' => $r->operator_name,
                'voided' => $r->voided_at !== null, 'void_reason' => $r->void_reason,
            ])->all()) : ['rows' => [], 'rows_total' => 0]),
        ];
    }

    // ───────────────────────────── huile ─────────────────────────────

    private function oil(bool $withRows): array
    {
        $rows = HygieneOilControl::query()->whereBetween('performed_at', [$this->from, $this->to])->orderBy('performed_at')->orderBy('id')->get();
        $live = $rows->whereNull('voided_at');
        $tests = $live->where('type', 'test');
        $fryers = HygieneEquipment::query()->withTrashed()->where('kind', 'fryer')->get()->filter(fn ($e) => $this->stillRelevant($e));
        $covered = $tests->groupBy('equipment_id')->map(fn ($g) => $g->map(fn ($r) => $this->localDate($r->performed_at))->unique()->flip());
        [$expected, $done, $todayTotal, $todayDone] = $this->coverage($fryers, $covered);
        $alerts = $live->where('status', 'alert');

        return [
            'tests' => $tests->count(), 'filters' => $live->where('type', 'filter')->count(), 'changes' => $live->where('type', 'change')->count(),
            'voided' => $rows->count() - $live->count(), 'alerts' => $alerts->count(), 'fryers' => $fryers->where('is_active', true)->whereNull('deleted_at')->count(),
            'expected' => $expected, 'covered' => $done, 'coverage_pct' => $this->rate($done, $expected),
            'today' => $this->includesToday() ? ['expected' => $todayTotal, 'done' => $todayDone] : null,
            'max_polar' => $tests->max('polar_pct'),
            'incidents' => $alerts->map(fn ($r) => [
                'at' => $r->performed_at->toIso8601String(), 'module' => 'oil',
                'text' => $r->equipment_name.' : '.($r->polar_pct !== null ? str_replace('.', ',', (string) $r->polar_pct).' % de composés polaires' : '').(in_array('bath_hot', explode(',', (string) $r->reasons), true) ? ' · bain '.str_replace('.', ',', (string) $r->bath_temp_c).' °C' : ''),
                'action' => $r->corrective_action ? (config("hygiene.oil.corrective_actions.{$r->corrective_action}.label").($r->corrective_note ? ' — '.$r->corrective_note : '')) : null,
                'operator' => $r->operator_name,
            ])->values()->all(),
            ...($withRows ? $this->capped($rows->map(fn ($r) => [
                'at' => $r->performed_at->toIso8601String(), 'fryer' => $r->equipment_name, 'type' => config("hygiene.oil.types.{$r->type}"), 'polar_pct' => $r->polar_pct,
                'bath_temp_c' => $r->bath_temp_c, 'oil_litres' => $r->oil_litres, 'oil_brand' => $r->oil_brand, 'status' => $r->status,
                'action' => $r->corrective_action ? config("hygiene.oil.corrective_actions.{$r->corrective_action}.label") : null, 'note' => $r->corrective_note ?: $r->note,
                'operator' => $r->operator_name, 'voided' => $r->voided_at !== null, 'void_reason' => $r->void_reason,
            ])->all()) : ['rows' => [], 'rows_total' => 0]),
        ];
    }

    // ───────────────────────────── DLC (étiquettes) ─────────────────────────────

    private function dlc(bool $withRows): array
    {
        $now = now();
        // Les COMPTEURS sont calculés par la base sur la période entière (jamais sur un échantillon) ; seules les lignes détaillées peuvent être plafonnées.
        $base = fn () => LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_DLC)->whereIn('status', LabelPrintLog::DONE_STATUSES)
            ->whereBetween('opened_at', [$this->from, $this->to])
            ->where(fn ($q) => $q->whereNull('discard_reason')->orWhere('discard_reason', '!=', LabelPrintLog::DISCARD_CANCELLED));

        $rows = ['rows' => [], 'rows_total' => 0];
        if ($withRows) {
            $list = $base()->orderBy('opened_at')->orderBy('id')
                ->get(array_merge(['opened_at', 'product_name', 'action'], \Illuminate\Support\Facades\Schema::hasColumn('label_print_logs', 'meal_slot') ? ['meal_slot'] : [], ['lot', 'supplier_lot', 'expires_at', 'copies', 'storage', 'discarded_at', 'discard_reason']))
                ->map(fn ($l) => [
                    'at' => $l->opened_at?->toIso8601String(), 'product' => $l->product_name, 'action' => $l->action, 'meal_slot' => $l->getAttribute('meal_slot'), 'lot' => $l->lot, 'supplier_lot' => $l->supplier_lot,
                    'expires_at' => $l->expires_at?->toIso8601String(), 'copies' => $l->copies, 'storage' => $l->storage,
                    'out' => $l->discarded_at ? ['at' => $l->discarded_at->toIso8601String(), 'reason' => $l->discard_reason ?: LabelPrintLog::DISCARD_DISCARDED] : null,
                ])->values()->all();
            $rows = $this->capped($list);
        }

        return [
            'count' => $base()->count(), 'copies' => (int) $base()->sum('copies'),
            'discarded' => $base()->where('discard_reason', LabelPrintLog::DISCARD_DISCARDED)->whereNotNull('discarded_at')->count(),
            'used' => $base()->where('discard_reason', LabelPrintLog::DISCARD_USED)->whereNotNull('discarded_at')->count(),
            // échus et jamais sortis : le point que regarde un contrôleur
            // même définition que l'écran DLC et l'accueil (LabelPrintLog::scopeWatchable) : une réservée sans verdict depuis plus de 10 min compte aussi
            'expired_not_out' => LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_DLC)->watchable($now)->whereBetween('opened_at', [$this->from, $this->to])
                ->where(fn ($q) => $q->whereNull('discard_reason')->orWhere('discard_reason', '!=', LabelPrintLog::DISCARD_CANCELLED))
                ->whereNull('discarded_at')->whereNotNull('expires_at')->where('expires_at', '<', $now)->count(),
            ...$rows,
        ];
    }

    /** @return array{rows: array, rows_total: int} */
    private function capped(array $rows): array
    {
        $total = count($rows);

        return ['rows' => $this->rowCap !== null && $total > $this->rowCap ? array_slice($rows, -$this->rowCap) : $rows, 'rows_total' => $total];
    }
}
