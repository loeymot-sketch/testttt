# MOBILE-EXCELLENCE-PHASE16-DRINK-ASSET-PARITY-20261016

## Mandate

Aligner les visuels boissons de l'application mobile sur les assets canoniques
présents dans `public/images/menu`, sans substituer une marque par une autre.

## Scope

- `mobile/assets/menu/` — copies binaires exactes des assets boissons déjà
  présents côté site ;
- `mobile/data/menu.js` — mappings et commentaires de vérité asset ;
- `mobile/tests/mobile-catalog-visual.spec.js` ;
- `tests/mobile-e2e/catalog-visual-critical.spec.js` ;
- `reports/execution/MOBILE-EXCELLENCE-PHASE16-DRINK-ASSET-PARITY-20261016/report.md` ;
- plans/task/ACTIVE_CYCLE/activity log associés.

## Explicit boundary

- Ne pas inventer d'image pour Hawaï, Perrier, Schweppes, Oasis Cassis ou
  bouteille Coca si l'asset exact et le SKU canonique ne sont pas présents.
- Le packshot Fuze Tea pêche retrouvé dans le checkout de la borne est une
  source canonique locale autorisée et doit être intégré avec traçabilité.
- Ne pas modifier les prix, le catalogue backend, les routes, les données de
  commande ou les zones POS/kiosk gelées.
- Les produits sans image de marque exacte restent sur l'asset neutre et sont
  listés comme blocage owner dans le report.

## Invariants

- Le backend reste la source de vérité des prix.
- Aucun changement `OrderStatus`, `branch_id`, dispatch ou service de commande.
- Parité mobile/site prouvée par SHA-256 pour chaque asset disponible.
