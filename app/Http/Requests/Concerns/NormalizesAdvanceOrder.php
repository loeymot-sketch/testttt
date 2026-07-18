<?php

namespace App\Http\Requests\Concerns;

use App\Enums\Ask;

trait NormalizesAdvanceOrder
{
    /**
     * Normalise is_advance_order vers l'enum KDS/OSS {YES=5, NO=10}.
     *
     * [P2-o / S3-02 — REGISTRE_FINAL goal-intelligence-2026-07-18] Les 5 fenêtres
     * KDS/OSS (KitchenDisplaySystemOrderService::list x2, KdsSyncService::sync,
     * OrderStatusScreenOrderService::list + listForBranch) filtrent en
     * `where(is_advance_order, NO)->orWhere(is_advance_order, YES)`. Toute autre
     * valeur (0, 1, 2, null) ne matche AUCUNE branche → la commande est invisible
     * cuisine + mur À VIE (24+ commandes PREPARING DB-prouvées jamais servies).
     *
     * On ramène toute valeur PRÉSENTE hors {5,10} vers NO(10) : c'est le choix le
     * plus sûr — la commande est simplement traitée comme non-advance et reste
     * VISIBLE, jamais silencieusement perdue. Un champ ABSENT est laissé tel quel
     * pour que la règle `required` le rejette proprement (422) — comportement
     * historique (les 3 requests gardaient déjà `$this->has(...)`).
     *
     * Defense-in-depth : les fronts actuels envoient les bons enums ; cette garde
     * protège contre un payload forgé, un client legacy ou une désync UI.
     */
    protected function normalizeAdvanceOrder(): void
    {
        if (! $this->has('is_advance_order')) {
            return; // absent → laissé à la règle `required` (rejet 422 propre)
        }

        $value = (int) $this->input('is_advance_order');
        if ($value !== Ask::YES && $value !== Ask::NO) {
            $this->merge(['is_advance_order' => Ask::NO]);
        }
    }
}
