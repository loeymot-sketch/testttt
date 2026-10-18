<?php

namespace App\Services\Hygiene;

use App\Models\DlcLabelProduct;
use App\Models\HygieneAlertSetting;
use App\Models\HygieneEquipment;
use App\Models\HygieneOperatorPin;
use App\Models\HygieneTaskTemplate;
use App\Models\HygieneTemperatureReading;
use App\Models\LabelPrintLog;
use App\Support\Hygiene\Kinds;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · rapport Komia « blocage total pour un nouveau client » + effet de progression] Où en est le restaurant dans la mise en place :
 * ce qui est fait, ce qu'il reste, et LA prochaine étape. Un restaurant neuf voit ce qu'il doit faire (et combien il en reste), pas sept tuiles « à configurer ».
 *
 * Chaque étape se calcule à partir des MÊMES journaux que les écrans (rien n'est coché à la main) : elle est faite quand la chose existe vraiment.
 * Étapes obligatoires : de quoi tenir le registre ; facultatives : pour aller plus loin (friteuse, codes, alertes). LECTURE SEULE.
 */
final class SetupProgress
{
    /**
     * @return array{steps:list<array<string,mixed>>, required_total:int, required_done:int, next:?array<string,mixed>, complete:bool}
     */
    public function all(): array
    {
        $equipment = HygieneEquipment::query()->whereIn('kind', Kinds::keys('temperature'))->where('is_active', true)->count();
        $tasks = HygieneTaskTemplate::query()->where('is_active', true)->count();
        $products = DlcLabelProduct::query()->where('is_active', true)->count();
        $toValidate = DlcLabelProduct::query()->where('is_active', true)->whereNull('durations_checked_at')
            ->whereIn('duration_source', [DlcLabelProduct::SOURCE_STARTING, DlcLabelProduct::SOURCE_IMPORTED])->count();
        $reading = HygieneTemperatureReading::query()->whereNull('voided_at')->exists();
        $label = LabelPrintLog::query()->where('kind', LabelPrintLog::KIND_DLC)->where('status', LabelPrintLog::STATUS_PRINTED)->exists(); // « envoyée » sans confirmation de l'imprimante ne prouve pas qu'une étiquette est sortie
        $fryers = HygieneEquipment::query()->where('kind', 'fryer')->where('is_active', true)->count();
        $pins = HygieneOperatorPin::query()->count();
        $alerts = (bool) (HygieneAlertSetting::query()->where('branch_id', AlertNotifier::branchId())->value('enabled'));

        $s = fn (string $key, string $label, string $detail, bool $done, string $route, string $cta, bool $optional = false): array => compact('key', 'label', 'detail', 'done', 'route', 'cta', 'optional');

        $steps = [
            $s('equipment', 'Créer vos frigos et congélateurs', $equipment ? "$equipment équipement".($equipment > 1 ? 's' : '').' prêt'.($equipment > 1 ? 's' : '').'.' : 'Dites combien vous en avez : un appui, les seuils sont déjà réglés.', $equipment > 0, '/admin/hygiene/temperature', 'Créer mes équipements'),
            $s('tasks', 'Installer votre plan de nettoyage', $tasks ? "$tasks fiche".($tasks > 1 ? 's' : '').' en place.' : 'Huit fiches recommandées, prêtes à l\'emploi, que vous pouvez adapter.', $tasks > 0, '/admin/hygiene/tasks', 'Installer le plan'),
            $s('durations', 'Valider vos durées de conservation',
                $products === 0 ? 'Le catalogue de départ s\'installe à la première ouverture des étiquettes.'
                    : ($toValidate > 0 ? "$toValidate durée".($toValidate > 1 ? 's' : '').' de départ à relire et à valider : c\'est vous qui les engagez devant un inspecteur.' : 'Toutes vos durées sont validées.'),
                $products > 0 && $toValidate === 0, '/admin/labels/dlc', $products === 0 ? 'Ouvrir les étiquettes' : 'Contrôler le catalogue'),
            $s('reading', 'Faire un premier relevé de température', $reading ? 'Premier relevé enregistré.' : ($equipment ? 'Un appui par équipement : la valeur d\'hier est proposée.' : 'Après avoir créé vos équipements.'), $reading, '/admin/hygiene/temperature', 'Faire un relevé'),
            $s('label', 'Imprimer une première étiquette', $label ? 'Première étiquette imprimée.' : 'Touchez un produit : l\'étiquette sort aussitôt.', $label, '/admin/labels/dlc', 'Imprimer une étiquette'),
            $s('fryer', 'Déclarer vos friteuses', $fryers ? "$fryers friteuse".($fryers > 1 ? 's' : '').' suivie'.($fryers > 1 ? 's' : '').'.' : 'Seulement si vous en avez : tests de l\'huile et changements tracés.', $fryers > 0, '/admin/hygiene/oil', 'Déclarer mes friteuses', true),
            $s('team', 'Protéger les noms de l\'équipe par un code', $pins ? "$pins personne".($pins > 1 ? 's' : '').' protégée'.($pins > 1 ? 's' : '').'.' : 'Personne ne signe alors à la place d\'un collègue.', $pins > 0, '/admin/hygiene/team', 'Donner des codes', true),
            $s('alerts', 'Recevoir les alertes par e-mail', $alerts ? 'Alertes actives.' : 'Un relevé en retard vous est signalé même si personne n\'ouvre l\'écran.', $alerts, '/admin/hygiene/alerts', 'Régler les alertes', true),
        ];

        $required = array_values(array_filter($steps, fn ($x) => ! $x['optional']));
        $requiredDone = count(array_filter($required, fn ($x) => $x['done']));
        $next = collect($required)->first(fn ($x) => ! $x['done']) ?? collect($steps)->first(fn ($x) => ! $x['done']);

        return [
            'steps' => $steps, 'required_total' => count($required), 'required_done' => $requiredDone,
            'next' => $next, 'complete' => $requiredDone === count($required),
        ];
    }
}
