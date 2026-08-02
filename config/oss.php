<?php

/**
 * Order Status Screen (customer wall) configuration.
 *
 * [Sprint H5-B Z4-P2-03 2026-05-17] Centralises the "how stale is too stale"
 * threshold for orders that remain on the customer wall after they enter
 * PREPARED state. Previously the OSS query relied on the whereDate(today())
 * filter alone, meaning a 11:55 PM order in PREPARED state would still flash
 * on the customer wall at 11:00 AM the next morning until the midnight
 * rollover purged it. This caused stale display, customer confusion, and
 * (rarely) duplicate-pickup attempts. The stale_window_hours config caps the
 * wall visibility to N hours from order_datetime — anything older drops off
 * the wall regardless of its status. Default 8h aligns with a typical
 * single-shift restaurant operating window; SaaS branches with longer hours
 * can override via OSS_STALE_WINDOW_HOURS.
 */
return [
    /*
     * Maximum age (in hours) of an order for it to appear on the OSS
     * customer wall. Beyond this threshold, orders are pruned regardless
     * of their PREPARING/PREPARED status — they should have been bumped
     * to DELIVERED long before this point.
     *
     * Used by:
     *   - App\Services\OrderStatusScreenOrderService::list()
     *   - App\Services\OrderStatusScreenOrderService::listForBranch()
     */
    'stale_window_hours' => (int) env('OSS_STALE_WINDOW_HOURS', 8),

    /*
     * [F-02 AUDIT CUISINIER 2026-08-01] Plancher d'âge des commandes À L'AVANCE / programmées
     * NON LIVRÉES sur le board cuisine. Cette branche de requête n'avait aucun plancher : une
     * programmée jamais retirée y restait POUR TOUJOURS et occupait un des 3 slots visibles
     * (zombies de 9 à 49 jours constatés en base, poussant les vraies commandes en « +N en
     * attente »). 48 h couvre largement un retard légitime (commande de la veille non retirée)
     * sans laisser s'accumuler des fantômes. Rien n'est supprimé : la commande reste en base,
     * dans l'historique et côté admin — elle quitte seulement l'écran de production.
     */
    'advance_stale_window_hours' => (int) env('OSS_ADVANCE_STALE_WINDOW_HOURS', 48),
];
