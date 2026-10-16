# Plan — MOBILE-EXCELLENCE-PHASE10-CATALOG-PARITY-20261016

## Objectif

Établir une preuve reproductible de parité entre le catalogue mobile Le Cayenne et le catalogue serveur de la borne, sans inventer de produit, de prix ou de marque côté frontend.

## Périmètre autorisé

- `mobile/tests/mobile-catalog-parity.spec.js`
- `reports/execution/MOBILE-EXCELLENCE-PHASE10-CATALOG-PARITY-20261016/report.md`
- `plans/PLAN_MOBILE-EXCELLENCE-PHASE10-CATALOG-PARITY-20261016_2026-10-16.md`
- `tasks/MOBILE-EXCELLENCE-PHASE10-CATALOG-PARITY-20261016.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/AGENT_ACTIVITY_LOG.md`

## Hors périmètre / gate nécessaire

- Aucun ajout ou renommage de boisson dans la base, la borne, le web ou l’app.
- Aucun prix frontend ajouté ou modifié.
- Aucune migration, aucun paiement, aucune modification loyalty/SMS.
- Aucun remplacement par une image de marque non fournie ou non validée.
- Les références Oasis Cassis, Schweppes Agrumes, bouteille Coca et « Fusty » restent à confirmer dans le catalogue backend avant toute implémentation.

## Plan de vérification

1. Charger `mobile/data/menu.js` dans un contexte isolé et lire le fixture serveur `reports/goal-web-app-sync/catalog-canonical.json`.
2. Comparer les 15 boissons de la catégorie serveur `boissons` par slug/alias, nom et prix.
3. Vérifier que le sélecteur des boissons de formule expose le même ensemble.
4. Vérifier l’existence physique des assets locaux et distinguer explicitement les assets neutres des vraies images.
5. Produire un rapport avec les blockers de release, sans masquer les écarts de visuels.

## Invariants

- Prix backend / fixture canonique comme source de vérité.
- `branch_id` non modifié et aucun croisement de branche.
- Aucun impact sur `OrderStatus`, dispatch ou `OrderService`.
- Aucun fichier de zone gelée modifié.

## Validation attendue

- Le contrôle structurel passe si noms, prix et ensembles canoniques sont alignés.
- Le rapport reste `NEEDS_FIX` tant qu’un produit canonique utilise un asset neutre ou qu’une référence demandée n’existe pas dans le backend.
