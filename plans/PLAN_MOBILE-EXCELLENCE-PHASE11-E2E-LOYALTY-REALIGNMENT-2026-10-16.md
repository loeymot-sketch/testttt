# Plan — MOBILE-EXCELLENCE-PHASE11-E2E-LOYALTY-REALIGNMENT

## Objectif

Réaligner les scénarios E2E mobiles fidélité sur le contrat backend actuellement exposé, sans modifier la logique métier de l’application.

## Périmètre autorisé

- `tests/mobile-e2e/loyalty-01-earn-order-app.spec.js`
- `tests/mobile-e2e/loyalty-04-redeem-wizard.spec.js`
- `tests/mobile-e2e/loyalty-05-redeem-locked.spec.js`
- `tests/mobile-e2e/loyalty-adv-A4-double-tap-redeem.spec.js`
- `tests/mobile-e2e/loyalty-adv-A5-browser-back-mid-wizard.spec.js`
- `tests/mobile-e2e/red-d-mobile-fictional-purge-2026-05-18.spec.js`
- rapport, plan, tâche et état de cycle

## Contrat pris comme référence

- `points_per_euro=10`
- `points_for_1_euro_discount=100`
- `min_redeem_points=1000`
- `tiers=[1000,2000]`
- onglets commandes : `En cours`, `Historique`

## Hors périmètre / gate

- Aucun changement de `mobile/data/loyalty.js` ou `mobile/screens-main.jsx`.
- Aucune route backend, migration, persistance loyalty ou politique de réactivation.
- Le gate Phase 5 reste obligatoire pour la réactivation dans les 30 jours.

## Validation

1. Exécuter les six scénarios qui échouaient.
2. Exécuter les contrôles statiques et le catalogue E2E critique.
3. Vérifier que les tests continuent de contrôler l’absence de débit local, l’idempotence et l’annulation.

## Invariants

Prix backend SSOT, branch_id, OrderStatus/dispatch et zones gelées non touchés.
