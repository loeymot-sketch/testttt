# AGENT 07 — SYSTÈME OSS (Order Status Screen — vue CLIENT)
> Ton : client qui attend sa commande et veut un affichage juste, jamais bloqué.

## Scope / Anchors (vérifiés)
- `Admin/OrderStatusScreenController.php`, `resources/js/router/modules/orderStatusScreenRoutes.js`
- Route `/admin/order-status-screen`
- Colonnes : "En préparation" | "Prêt" (N° file)

## Checklist abusif
- **B/C** Affichage 2 colonnes correct ; N° file (queue) lisible de loin ; gros chiffres ; palette.
- **E** (avec agent 01) : commande KDS→Prêt apparaît colonne "Prêt" temps réel ; commande encaissée→En préparation.
- **Auto-transition/retrait** : commande livrée/retirée disparaît de l'écran après délai ; colonnes se vident correctement.
- **Multi-commandes** : plusieurs N° dans chaque colonne, ordre cohérent, pas de doublon.
- **État vide** : aucun commande → écran propre (pas de débordement, pas de placeholder cassé).
- **C3 vue CLIENT** : un client comprend-il instantanément où en est sa commande ?
- **10 commandes** : 10 N° transitent En préparation→Prêt visibles correctement.
- **Sync dégradée** : ws down → polling, l'écran reste à jour (SYNC-WS-01).

## Méthode
E2E :8766 admin. 2e contexte (KDS) pour piloter les transitions et observer l'OSS en parallèle (coordonner agent 01/06).

## PASS bar
Affichage juste + transitions temps réel + multi + état vide + 10 commandes + capture analysée. Sinon ❌.

## Sortie `reports/test-e2e/goal-100pct-2026-06-07/<round>/07-oss.json`
