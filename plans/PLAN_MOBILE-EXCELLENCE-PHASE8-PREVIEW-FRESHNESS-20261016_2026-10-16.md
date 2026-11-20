# Plan — MOBILE-EXCELLENCE-PHASE8-PREVIEW-FRESHNESS-20261016 — 2026-10-16

## Statut

PLAN_REVIEW_REQUIRED — correctif preview local uniquement.

## Tier

routine contrôlée — chargement d’assets locaux et test de non-régression ; aucun
invariant métier touché.

## Fichiers autorisés

| Fichier | Action |
|---|---|
| `mobile/index.html` | ajouter une version explicite aux liens CSS/scripts locaux |
| `mobile/tests/mobile-preview-freshness.spec.js` | vérifier le contrat de version |
| `reports/execution/.../report.md` | consigner la preuve |

## Règle d’implémentation

Définir une version unique `20261016` pour les ressources locales du preview.
Ne pas ajouter cette query aux CDN React/Babel, ne pas modifier les integrity
hashes et ne pas modifier l’ordre des scripts.

## Validation

`node mobile/tests/mobile-preview-freshness.spec.js`,
`node mobile/tests/mobile-identity.spec.js`,
`node mobile/tests/navigation.spec.js`, puis E2E catalogue Playwright.

Le test statique doit aussi verrouiller :

- URLs et `integrity` CDN React/ReactDOM/Babel inchangés ;
- ordre exact React → ReactDOM → Babel ;
- aucune query de version sur CDN ou Google Fonts ;
- query `v=20261016` présente uniquement sur les ressources locales ;
- `window.LC.config` inchangé (`apiBase`, `branchId`, `onlineCardEnabled`).

## PLAN_REVIEW

PLAN_REVIEW_VERDICT: PASS
PLAN_REVIEW_NOTES: cache-bust strictement local ; CDN, ordre, integrity et config
runtime verrouillés par le test statique
