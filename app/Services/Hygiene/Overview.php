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
 */
final class Overview
{
    /** @return list<array<string,mixed>> */
    public function tiles(): array
    {
        return [$this->temperature(), $this->tasks(), $this->dlc(), $this->reception(), $this->trace(), $this->oil()];
    }

    private function tile(string $key, string $label, string $icon, string $status, string $headline, string $detail = '', array $counts = []): array
    {
        return ['key' => $key, 'label' => $label, 'icon' => $icon, 'route' => $key === 'dlc' ? '/admin/labels/dlc' : '/admin/hygiene/'.$key, 'status' => $status, 'headline' => $headline, 'detail' => $detail, 'counts' => $counts];
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
        $status = $alerts > 0 ? 'alert' : ($done < $total ? 'todo' : 'ok');

        return $this->tile('temperature', 'Températures', 'fa-solid fa-temperature-half', $status, "$done/$total relevés",
            $alerts > 0 ? $this->plural($alerts, 'écart') : ($done < $total ? ($total - $done).' à relever' : 'Tout est relevé'), ['done' => $done, 'total' => $total, 'alerts' => $alerts]);
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
        $finished = $templates->filter(fn ($t) => $done->has($t->id))->count();
        $overdue = $templates->filter(function ($t) use ($done, $now, $day) {
            $due = TaskSchedule::dueAt($t, $day);

            return ! $done->has($t->id) && $due !== null && $now->gt($due);
        })->count();
        $status = $total === 0 ? 'ok' : ($overdue > 0 ? 'alert' : ($finished < $total ? 'todo' : 'ok'));

        return $this->tile('tasks', 'Nettoyage', 'fa-solid fa-broom', $status, $total === 0 ? 'Rien à faire' : "$finished/$total fiches",
            $overdue > 0 ? $overdue.' en retard' : ($finished < $total ? ($total - $finished).' à faire' : 'Tout est fait'), ['done' => $finished, 'total' => $total, 'overdue' => $overdue]);
    }

    private function dlc(): array
    {
        $now = now();
        $rows = LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_DLC)->whereIn('status', LabelPrintLog::DONE_STATUSES)->whereNull('discarded_at')
            ->whereNotNull('expires_at')->where('expires_at', '>=', $now->copy()->subDays(LabelPrintLog::WATCH_BEHIND_DAYS))->where('expires_at', '<=', $now->copy()->addHours(24))
            ->get(['expires_at']);
        $expired = $rows->filter(fn ($l) => $l->expires_at->lt($now))->count();
        $soon = $rows->count() - $expired;
        $status = $expired > 0 ? 'alert' : ($soon > 0 ? 'todo' : 'ok');

        return $this->tile('dlc', 'DLC', 'fa-solid fa-tags', $status, $expired > 0 ? $this->plural($expired, 'DLC dépassée') : ($soon > 0 ? $soon.' à échéance 24 h' : 'Aucune alerte'),
            $expired > 0 && $soon > 0 ? $soon.' à échéance sous 24 h' : '', ['expired' => $expired, 'soon' => $soon]);
    }

    private function reception(): array
    {
        $rows = HygieneReception::query()->whereNull('voided_at')->where('received_at', '>=', now()->startOfDay())->get(['status', 'decision']);
        $alerts = $rows->where('status', 'alert')->count();

        return $this->tile('reception', 'Réception', 'fa-solid fa-truck', $alerts > 0 ? 'alert' : 'ok', $rows->isEmpty() ? 'Aucune livraison' : $this->plural($rows->count(), 'livraison'),
            $alerts > 0 ? $this->plural($alerts, 'écart') : ($rows->isEmpty() ? '' : 'Toutes conformes'), ['count' => $rows->count(), 'alerts' => $alerts]);
    }

    private function trace(): array
    {
        $rows = HygieneTraceEntry::query()->whereNull('voided_at')->where('traced_at', '>=', now()->startOfDay())->get(['status']);
        $alerts = $rows->where('status', 'alert')->count();

        return $this->tile('trace', 'Traçabilité', 'fa-solid fa-barcode', $alerts > 0 ? 'alert' : 'ok', $rows->isEmpty() ? 'Rien tracé' : $this->plural($rows->count(), 'produit tracé', 'produits tracés'),
            $alerts > 0 ? $this->plural($alerts, 'écart') : '', ['count' => $rows->count(), 'alerts' => $alerts]);
    }

    private function oil(): array
    {
        $start = now()->startOfDay();
        $fryers = HygieneEquipment::query()->where('kind', 'fryer')->where('is_active', true)->get(['id']);
        if ($fryers->isEmpty()) {
            return $this->tile('oil', 'Huile', 'fa-solid fa-bacon', 'empty', 'À configurer', 'Friteuses');
        }
        $last = HygieneOilControl::query()->whereNull('voided_at')->where('type', 'test')->where('performed_at', '>=', $start)->whereIn('equipment_id', $fryers->pluck('id'))
            ->orderBy('performed_at')->orderBy('id')->get()->groupBy('equipment_id')->map->last();
        $total = $fryers->count();
        $done = $last->count();
        $alerts = $last->where('status', 'alert')->count();
        $status = $alerts > 0 ? 'alert' : ($done < $total ? 'todo' : 'ok');

        return $this->tile('oil', 'Huile', 'fa-solid fa-bacon', $status, "$done/$total testées",
            $alerts > 0 ? $this->plural($alerts, 'écart') : ($done < $total ? ($total - $done).' à tester' : 'Toutes testées'), ['done' => $done, 'total' => $total, 'alerts' => $alerts]);
    }
}
