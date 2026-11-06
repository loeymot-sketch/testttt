# Plan — MOBILE-EXCELLENCE-PHASE17-CANONICAL-WEBP-ASSETS-20261016

## Objective

Faire charger à l’application mobile Le Cayenne exactement les mêmes vignettes
WebP que le site et la borne pour les visuels de sandwiches, tacos, galettes
et catégories associées. Le site/borne résout ces vignettes via
`App\\Support\\MenuImageThumb` depuis `public/images/menu/thumbs`.

## Scope / allowlist

- `mobile/assets/menu/sandwich-cayenne.webp`
- `mobile/assets/menu/sandwich-supreme.webp`
- `mobile/assets/menu/sandwich-mega.webp`
- `mobile/assets/menu/sandwich-terminator.webp`
- `mobile/assets/menu/tacos-cayenne.webp`
- `mobile/assets/menu/galette.webp`
- `mobile/assets/menu/cat-sandwich-cayenne.webp`
- `mobile/assets/menu/cat-galette.webp`
- `mobile/assets/menu/cat-tacos.webp`
- `mobile/data/menu.js`
- `mobile/tests/mobile-catalog-visual.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
- `tasks/MOBILE-EXCELLENCE-PHASE17-CANONICAL-WEBP-ASSETS-20261016.md`
- `reports/execution/MOBILE-EXCELLENCE-PHASE17-CANONICAL-WEBP-ASSETS-20261016/report.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/AGENT_ACTIVITY_LOG.md`

## Out of scope / frozen

Backend PHP/config, migrations, pricing/order data, payment, auth/SMS,
loyalty, branch isolation, dispatch, POS/caisse/kiosk source, native iOS
packaging/signing and deletion of the existing PNG files.

## Acceptance criteria

1. Les neuf fichiers mobiles sont des copies binaires exactes des neuf
   `public/images/menu/thumbs/*.webp` canoniques.
2. Les mappings catalogue, héros, pain/galette et catégories utilisent les
   noms `.webp`; aucun de ces produits ne référence encore l’ancien PNG.
3. Les tests statiques et E2E prouvent le SHA, le chargement navigateur,
   l’absence de fallback emoji et la navigation catalogue → personnalisation.
4. Aucun invariant métier n’est modifié : backend pricing SSOT, OrderStatus,
   `branch_id`, dispatch post-commit et services de commande inchangés.

## Test strategy

- `node mobile/tests/mobile-catalog-visual.spec.js`
- `npx playwright test tests/mobile-e2e/catalog-visual-critical.spec.js --config=tests/mobile-e2e/playwright.config.js --reporter=line --retries=0`
- `git diff --check`
- contrôle ciblé navigateur sur l’accueil, Menu, Sandwichs, Tacos et Galette.

## Execution routing

`EXECUTION_TIER: complex` — changement cross-surface d’assets et de contrats
de tests, exécuté par `codex-extension` ou son fallback
`foodking-complex-implementer`.

`PLAN_REVIEW_CHANNEL: foodking-complex-implementer (codex-extension-fallback)`
`PLAN_REVIEW_MODEL: gpt-5.5-pro`
`PLAN_REVIEW_REASONING_EFFORT: xhigh`
`PLAN_REVIEW_VERDICT: PASS`

## Invariants considered

- backend pricing remains the only price authority;
- no OrderStatus, `branch_id`, dispatch or order service changes;
- no auth/payment/loyalty/native packaging changes;
- existing unrelated dirty worktree changes remain untouched.
