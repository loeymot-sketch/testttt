# Plan — MOBILE-OPTIMIZATION-BOOT-20261018

TASK_ID: `MOBILE-OPTIMIZATION-BOOT-20261018`
EXECUTION_TIER: `complex`

## Fichiers autorisés

`mobile/index.html`, `mobile/tests/mobile-preview-freshness.spec.js`,
`mobile/tests/mobile-ui-excellence.spec.js`, `tests/mobile-e2e/utils/waitForLoyaltyReady.js`,
`tasks/`, `plans/`, `reports/`, `.cursor/ACTIVE_CYCLE.md`.

## Invariants

Backend pricing SSOT, aucun prix frontend, branch_id isolé, OrderStatus inchangé,
dispatch après commit, zones frozen inchangées.

## Stratégie

Chargement de `data/dev-helpers.js` uniquement si `URLSearchParams` contient `dev`; vérifier
que le build normal ne dépend pas de `LC.dev`, conserver le mode E2E `?dev`, mesurer le budget
de chargement et relancer les tests critiques.
