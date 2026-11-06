<?php

namespace App\Models\Concerns;

/**
 * [CAYENNE HYGIÈNE 2026-10-18 · tour 9] Le dossier de contrôle date le MASQUAGE d'un équipement ou d'une fiche par son `updated_at` (aucune colonne ne
 * porte ce moment). Renommer ou réordonner un élément déjà masqué repoussait donc sa fin à aujourd'hui : des jours « non faits » inventés apparaissaient dans
 * le PDF remis à l'inspecteur (fiche à 100 % → 65 %, dix dates fantômes). Une écriture sur un élément qui reste masqué ne touche donc plus `updated_at` ;
 * celle qui le masque (ou le réactive) le met à jour normalement.
 */
trait KeepsHideMoment
{
    protected static function bootKeepsHideMoment(): void
    {
        static::updating(function ($model): void {
            if (! $model->is_active && ! $model->isDirty('is_active')) {
                $model->timestamps = false; // Eloquent horodate APRÈS cet évènement : la ligne garde le moment du masquage
            }
        });
        static::updated(function ($model): void {
            $model->timestamps = true; // l'instance reste utilisable pour d'autres écritures horodatées
        });
        // [VG-2026-10-21 M8-102] La suppression douce écrit aussi `updated_at` (SoftDeletes::runSoftDelete) : supprimer un élément DÉJÀ masqué
        // effaçait le moment du masquage et repoussait sa fin au jour de la suppression (3 → 20 jours « non faits » dans le dossier d'inspection).
        // L'élément masqué garde donc son `updated_at` ; le dossier prend la plus ancienne des deux dates (ReportBuilder::endDate).
        static::deleting(function ($model): void {
            if (! $model->is_active) {
                $model->timestamps = false;
            }
        });
        static::deleted(function ($model): void {
            $model->timestamps = true;
        });
    }
}
