# AGENT 06 — SYSTÈME KDS (Écran Cuisine — vue OPÉRATEUR)
> Ton : chef sous pression qui veut un écran qui ne ment jamais.

## Scope / Anchors (vérifiés)
- `Admin/KitchenDisplaySystemController.php` (index, changeStatus, orderItems, historyToday, recall)
- `Services/KitchenDisplaySystemOrderService.php` (date-scoping today + visibleStatuses + PAID)
- Composants : KitchenDisplaySystemComponent, KdsV2Grid, KdsOrderCard, KdsStatusBanner, KdsHistoryDrawer, KdsUndoToast
- Route `/admin/kitchen-display-system` (alias `/kds`)

## Checklist abusif (6 axes)
- **B1 CHAQUE bouton/CTA** : `kds-card-cta-ready` (Démarrer 4→7, Prêt 7→8), recall/annuler bump, history drawer, station filter.
- **Date-scoping** : seules les commandes du JOUR + visibleStatuses + PAID s'affichent (vérifié : vieux soak orders exclus = correct, PAS un bug).
- **Multi-commandes concurrentes** : plusieurs cartes, colonnes En préparation / Prêt, ordre cohérent.
- **OOS badge** (`kds-oos-warning-badge`), cash-pending note (`kds-card-cash-pending`) — chef peut bump non-payé (owner a levé le gate).
- **Recall/Undo** : `kds-card-recall-badge`, undo toast — re-transition tracée (`reason=kitchen_recall`).
- **B3** 0 raw label (déjà healé `kds_counter_payment_unpaid` 5 locales).
- **C** (agent 03) capture chaque transition ; vue OPÉRATEUR lisible (gros chiffres, contraste).
- **E** (avec agent 01) changeStatus → reflété OSS+tracker+dashboard temps réel.
- **F** chaque transition auditée (`order_status_transitions` actor+correlation_id+occurred_at).
- **10 CYCLES** : 10 commandes passées par le KDS (accept→prepare→ready) → toutes tracées.
- **D** Bump < 1s, pas de freeze, ws-reconnect-banner si sync down.

## Méthode
E2E :8766 (admin ou loginAsChefOperator si dispo). Commande fraîche du jour requise (créer via borne/POS d'abord — coordonner avec agents 04/05).

## PASS bar
Chaque bouton + multi-commandes + recall + 10 cycles + transitions auditées + capture. Sinon ❌.

## Sortie `reports/test-e2e/goal-100pct-2026-06-07/<round>/06-kds.json`
