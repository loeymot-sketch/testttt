# Task — MOBILE-EXCELLENCE-PHASE8-PREVIEW-FRESHNESS-20261016

## Objet

Faire en sorte que le preview local de l’application Le Cayenne recharge les
dernières données, styles et écrans au lieu de conserver un ancien menu ou un
ancien numéro de téléphone dans le cache navigateur.

## Constat vérifié

- Le code source et l’API backend renvoient `03 65 67 82 91`.
- Les tests Playwright dans un contexte neuf voient ce numéro.
- Le navigateur de test déjà ouvert continue d’afficher `33 65 13 00 00 0`.
- `mobile/index.html` charge les scripts et styles locaux sans version de cache.

## Périmètre borné

- `mobile/index.html`
- `mobile/tests/mobile-preview-freshness.spec.js`
- `reports/execution/MOBILE-EXCELLENCE-PHASE8-PREVIEW-FRESHNESS-20261016/report.md`

## Hors périmètre

- Aucun changement de menu, prix, images, API, paiement, fidélité ou commande.
- Aucun nettoyage de localStorage et aucune suppression de données utilisateur.

## Invariants

- Les fichiers locaux restent servis depuis `mobile/`.
- Les scripts externes React/Babel gardent leurs URL et intégrité inchangées.
- Le cache-bust ne modifie aucun payload, prix, ID, slug ou branche.
- Le preview doit charger le même logo, menu, téléphone et styles que les tests.

## Validation prévue

- Test statique : tous les assets locaux applicatifs portent la même version,
  les CDN/Google Fonts n’ont aucune version ajoutée, l’ordre et les hashes CDN
  restent inchangés, et `window.LC.config` garde `apiBase`, `branchId` et
  `onlineCardEnabled` identiques.
- Reload cache-bust du navigateur déjà ouvert sur `8088`.
- Playwright catalogue Phase 3 et tests Node d’identité/navigation.
