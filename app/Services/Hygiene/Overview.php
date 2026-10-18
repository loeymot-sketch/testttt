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

/**
 * [CAYENNE HYGIÈNE 2026-10-17 · owner] L'accueil du module : une tuile par domaine, avec ce qu'il reste à faire AUJOURD'HUI et ce qui est
 * en alerte (comme la tuile rouge « 0/5 effectués » de Komia, pour tous les domaines). LECTURE SEULE.
 *
 * status : alert (à traiter maintenant) · todo (à faire) · ok (tout est fait) · empty (rien de configuré : l'écran propose de démarrer).
 * idle : rien n'est attendu et rien n'a eu lieu aujourd'hui (réception, traçabilité) — neutre, jamais vert : « vert » dit « fait et conforme »,
 * pas « aucune donnée ».
 */
final class Overview
{
    /**
     * Les tuiles du jour. Un RAPPEL en alerte (relevé du matin non fait, nettoyage en retard, DLC dépassées…) fait passer la tuile de son
     * domaine en alerte : « vert » ou « à faire » ne dit plus la vérité une fois l'heure dépassée.
     *
     * @return list<array<string,mixed>>
     */
    public function tiles(?array $reminders = null): array
    {
        $reminders = $reminders ?? (new Reminders)->all();
        $tiles = [$this->temperature(), $this->tasks(), $this->dlc(), $this->reception(), $this->trace(), $this->oil()];

        foreach ($tiles as &$tile) {
            $late = array_values(array_filter($reminders, fn ($r) => $r['domain'] === $tile['key'] && $r['level'] === 'alert'));
            if ($late !== [] && in_array($tile['status'], ['todo', 'ok', 'idle'], true)) {
                $tile['status'] = 'alert';
                $tile['detail'] = $late[0]['text'];
            }
            $tile['reminders'] = count(array_filter($reminders, fn ($r) => $r['domain'] === $tile['key']));
        }
        unset($tile);

        return $tiles;
    }

    /** Le bouton d'action de la tuile (un grand geste visible, comme les cartes d'action de Komia) : jamais « Ouvrir ». */
    private const CTA = [
        'temperature' => 'Réaliser un relevé', 'tasks' => 'Faire le nettoyage', 'dlc' => 'Imprimer une étiquette',
        'reception' => 'Réceptionner une livraison', 'trace' => 'Tracer un produit', 'oil' => 'Tester l’huile',
    ];

    private function tile(string $key, string $label, string $icon, string $status, string $headline, string $detail = '', array $counts = []): array
    {
        return [
            'key' => $key, 'label' => $label, 'icon' => $icon, 'route' => $key === 'dlc' ? '/admin/labels/dlc' : '/admin/hygiene/'.$key, 'status' => $status,
            'headline' => $headline, 'detail' => $detail, 'counts' => $counts,
            'cta' => $status === 'empty' ? 'Configurer' : (self::CTA[$key] ?? 'Ouvrir'),
        ];
    }

    private function plural(int $n, string $one, ?string $many = null): string
    {
        return $n.' '.($n > 1 ? ($many ?? $one.'s') : $one);
    }

    private function temperature(): array
    {
        $start = now()->startOfDay();
        $equipments = HygieneEquipment::query()->whereIn('kind', Kinds::keys('temperature'))->where('is_active', true)->get(['id']);
        if ($equipments->isEmpty()) {
            return $this->tile('temperature', 'Températures', 'fa-solid fa-temperature-half', 'empty', 'À configurer', 'Frigos et congélateurs');
        }
        $last = HygieneTemperatureReading::query()->whereNull('voided_at')->where('recorded_at', '>=', $start)->whereIn('equipment_id', $equipments->pluck('id'))
            ->orderBy('recorded_at')->orderBy('id')->get()->groupBy('equipment_id')->map->last();
        $total = $equipments->count();
        $done = $last->count();
        $alerts = $last->where('status', 'alert')->count();
        // Écart traité mais PAS encore prouvé : la nouvelle mesure est attendue (même source que l'écran des relevés et les rappels). Tant qu'elle
        // manque, « tout est relevé » serait faux : la tuile reste « à faire » (elle passe en alerte quand l'échéance est dépassée, par le rappel).
        $followups = TemperatureFollowUp::pending($equipments->pluck('id')->all());
        $status = $alerts > 0 ? 'alert' : ($done < $total || $followups !== [] ? 'todo' : 'ok');
        $due = $followups === [] ? null : \Carbon\Carbon::parse(collect($followups)->min('due_at'))->setTimezone(now()->getTimezone())->format('H:i');
        $detail = $alerts > 0 ? $this->plural($alerts, 'écart') : ($done < $total ? ($total - $done).' à relever' : ($followups !== [] ? 'Nouvelle mesure à faire avant '.$due : 'Tout est relevé'));

        return $this->tile('temperature', 'Températures', 'fa-solid fa-temperature-half', $status, "$done/$total relevés", $detail, ['done' => $done, 'total' => $total, 'alerts' => $alerts, 'followups' => count($followups)]);
    }

    private function tasks(): array
    {
        $now = now();
        $day = $now->copy()->startOfDay();
        $templates = HygieneTaskTemplate::query()->where('is_active', true)->get()->filter(fn ($t) => TaskSchedule::isDueOn($t, $day))->values();
        if (HygieneTaskTemplate::query()->count() === 0) {
            return $this->tile('tasks', 'Nettoyage', 'fa-solid fa-broom', 'empty', 'À configurer', 'Plan de nettoyage');
        }
        $done = HygieneTaskCompletion::query()->whereNull('voided_at')->whereDate('due_date', $day->toDateString())->get()->groupBy('template_id');
        $total = $templates->count();
        // « faite » = validée COMPLÈTE : une fiche validée avec des points non faits reste à reprendre (même règle que l'écran du jour et le dossier)
        $finished = $templates->filter(fn ($t) => $done->has($t->id) && $done->get($t->id)->contains('complete', true))->count();
        $partial = $templates->filter(fn ($t) => $done->has($t->id) && ! $done->get($t->id)->contains('complete', true))->count();
        $overdue = $templates->filter(fn ($t) => ! $done->has($t->id) && TaskSchedule::isLate($t, $now, $day))->count();
        // aucune fiche due aujourd'hui : neutre (« vert » = fait et conforme, pas « rien à faire »)
        $status = $total === 0 ? 'idle' : ($overdue > 0 ? 'alert' : ($finished < $total ? 'todo' : 'ok'));

        return $this->tile('tasks', 'Nettoyage', 'fa-solid fa-broom', $status, $total === 0 ? 'Rien à faire' : "$finished/$total fiches",
            $total === 0 ? '' : ($overdue > 0 ? $overdue.' en retard' : ($finished < $total ? ($partial > 0 ? $this->plural($partial, 'incomplète').' · ' : '').($total - $finished - $partial).' à faire' : 'Tout est fait')),
            ['done' => $finished, 'total' => $total, 'overdue' => $overdue, 'partial' => $partial]);
    }

    /**
     * DLC : seule une étiquette DÉPASSÉE appelle une action (la jeter, ou la marquer « utilisée ») — c'est le rappel `dlc-expired`. Une étiquette qui
     * expire dans la journée est le fonctionnement NORMAL d'une cuisine (20 produits du catalogue durent 24 h) : elle s'affiche, elle ne retient plus
     * « Journée en règle ». Et « vert » dit « fait et conforme », pas « aucune donnée » : sans aucune étiquette en cours la tuile est neutre (`idle`).
     */
    private function dlc(): array
    {
        $now = now();
        $done = fn () => LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_DLC)->watchable($now)->whereNull('discarded_at')->whereNotNull('expires_at');
        $rows = $done()->where('expires_at', '>=', $now->copy()->subDays(LabelPrintLog::WATCH_BEHIND_DAYS))->where('expires_at', '<=', $now->copy()->addHours(24))->get(['expires_at']);
        $expired = $rows->filter(fn ($l) => $l->expires_at->lt($now))->count();
        $soon = $rows->count() - $expired;
        $live = $done()->where('expires_at', '>=', $now)->get(['status', 'anomaly']);
        // une réservée sans verdict depuis plus de 10 min est surveillée comme « non confirmée » (même définition que l'écran DLC)
        $unconfirmed = $live->whereIn('status', [LabelPrintLog::STATUS_UNCONFIRMED, LabelPrintLog::STATUS_CLAIMED])->count();
        $anomaly = $live->filter(fn ($l) => (bool) $l->anomaly)->count();
        $counts = ['expired' => $expired, 'soon' => $soon, 'live' => $live->count(), 'unconfirmed' => $unconfirmed, 'anomaly' => $anomaly];
        $nbsp = "\u{00A0}"; // « 24 h », « à échéance », « à vérifier » ne se coupent jamais en laissant « h » ou « à » seul au bout de la ligne
        $soonText = $soon.' à'.$nbsp.'échéance sous 24'.$nbsp.'h';

        if ($expired > 0) {
            return $this->tile('dlc', 'DLC', 'fa-solid fa-tags', 'alert', $this->plural($expired, 'DLC dépassée'), $soon > 0 ? $soonText : '', $counts);
        }
        if ($live->isEmpty()) {
            return $this->tile('dlc', 'DLC', 'fa-solid fa-tags', 'idle', 'Aucune étiquette en cours', '', $counts);
        }
        // Une impression « non confirmée » ne se lève par aucune action (seulement la jeter ou la réimprimer) : la compter « à compléter » retiendrait
        // « Journée en règle » jusqu'à l'expiration. On la DIT à côté du chiffre, sans la transformer en reproche impossible à lever.
        $parts = array_values(array_filter([
            $soon > 0 ? $soonText : '',
            $unconfirmed > 0 ? $unconfirmed.' '.($unconfirmed > 1 ? 'non confirmées' : 'non confirmée') : '',
            $anomaly > 0 ? $anomaly.' à'.$nbsp.'vérifier' : '',
        ]));

        return $this->tile('dlc', 'DLC', 'fa-solid fa-tags', 'ok', $this->plural($live->count(), 'étiquette').' en cours', $parts === [] ? '' : 'dont '.implode(', ', $parts), $counts);
    }

    private function reception(): array
    {
        $rows = HygieneReception::query()->whereNull('voided_at')->where('received_at', '>=', now()->startOfDay())->get(['status', 'decision']);
        $alerts = $rows->where('status', 'alert')->count();

        return $this->tile('reception', 'Réception', 'fa-solid fa-truck', $alerts > 0 ? 'alert' : ($rows->isEmpty() ? 'idle' : 'ok'), $rows->isEmpty() ? 'Aucune livraison' : $this->plural($rows->count(), 'livraison'),
            $alerts > 0 ? $this->plural($alerts, 'écart') : ($rows->isEmpty() ? '' : 'Toutes conformes'), ['count' => $rows->count(), 'alerts' => $alerts]);
    }

    private function trace(): array
    {
        $rows = HygieneTraceEntry::query()->whereNull('voided_at')->where('traced_at', '>=', now()->startOfDay())->get(['status']);
        $alerts = $rows->where('status', 'alert')->count();

        return $this->tile('trace', 'Traçabilité', 'fa-solid fa-barcode', $alerts > 0 ? 'alert' : ($rows->isEmpty() ? 'idle' : 'ok'), $rows->isEmpty() ? 'Rien tracé' : $this->plural($rows->count(), 'produit tracé', 'produits tracés'),
            $alerts > 0 ? $this->plural($alerts, 'écart') : '', ['count' => $rows->count(), 'alerts' => $alerts]);
    }

    private function oil(): array
    {
        $start = now()->startOfDay();
        $fryers = HygieneEquipment::query()->where('kind', 'fryer')->where('is_active', true)->get(['id']);
        if ($fryers->isEmpty()) {
            return $this->tile('oil', 'Huile', 'fa-solid fa-bacon', 'empty', 'À configurer', 'Friteuses');
        }
        $today = HygieneOilControl::query()->whereNull('voided_at')->where('performed_at', '>=', $start)->whereIn('equipment_id', $fryers->pluck('id'))
            ->orderBy('performed_at')->orderBy('id')->get()->groupBy('equipment_id');
        $total = $fryers->count();
        $done = $alerts = $warns = 0;
        foreach ($fryers as $f) {
            $rows = $today->get($f->id, collect());
            $test = $rows->last(fn ($r) => $r->type === 'test');
            if ($test === null) continue;
            $done++;
            // UNE règle (OilRules::isOpen) pour la tuile, le rappel et l'écran : un écart soldé par « Huile changée » ou par une vidange saisie
            // ensuite n'est plus « à traiter » ; des polaires « à surveiller » ne sont pas « conformes » (tuile verte, écran ambre : tour 4).
            $changedSince = $rows->contains(fn ($r) => $r->countsAsChange() && $r->performed_at->gte($test->performed_at));
            if (! OilRules::isOpen((string) $test->status, $test->corrective_action, $changedSince)) continue;
            $test->status === 'alert' ? $alerts++ : $warns++;
        }
        $status = $alerts > 0 ? 'alert' : ($done < $total || $warns > 0 ? 'todo' : 'ok');
        $detail = $alerts > 0 ? $this->plural($alerts, 'écart') : ($warns > 0 ? $warns.' à surveiller' : ($done < $total ? ($total - $done).' à tester' : 'Toutes testées'));

        return $this->tile('oil', 'Huile', 'fa-solid fa-bacon', $status, "$done/$total testées", $detail, ['done' => $done, 'total' => $total, 'alerts' => $alerts, 'warns' => $warns]);
    }
}
