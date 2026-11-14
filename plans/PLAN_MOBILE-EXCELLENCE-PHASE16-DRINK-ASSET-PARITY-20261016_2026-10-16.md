# Plan — MOBILE-EXCELLENCE-PHASE16-DRINK-ASSET-PARITY-20261016

## Objective

Remplacer les versions mobiles divergentes des boissons par les assets
canoniques du site et empêcher les faux remplacements de marque.

## Allowlist

- `mobile/assets/menu/coca.png`
- `mobile/assets/menu/coca-zero.png`
- `mobile/assets/menu/coca-cherry.png`
- `mobile/assets/menu/tropico.png`
- `mobile/assets/menu/lipton-peche.png`
- `mobile/assets/menu/fanta-citron.png`
- `mobile/assets/menu/fanta-orange.png`
- `mobile/assets/menu/oasis.png`
- `mobile/assets/menu/sprite.png`
- `mobile/assets/menu/eau.png`
- `mobile/assets/menu/capri-sun.png`
- `mobile/assets/menu/fuze-tea.png`
- `mobile/data/menu.js`
- `mobile/tests/mobile-catalog-visual.spec.js`
- `tests/mobile-e2e/catalog-visual-critical.spec.js`
- `tasks/MOBILE-EXCELLENCE-PHASE16-DRINK-ASSET-PARITY-20261016.md`
- `plans/PLAN_MOBILE-EXCELLENCE-PHASE16-DRINK-ASSET-PARITY-20261016_2026-10-16.md`
- `reports/execution/MOBILE-EXCELLENCE-PHASE16-DRINK-ASSET-PARITY-20261016/report.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/AGENT_ACTIVITY_LOG.md`

## Acceptance

1. SHA-256 mobile = `public/images/menu` pour les 11 assets du site, plus
   SHA-256 égal au packshot Fuze Tea canonique extrait de la borne.
2. Les mappings mobile restent brand-corrects.
3. Hawaï/Perrier et les références absentes restent explicitement neutres,
   sans faux visuel ; Fuze Tea utilise le packshot réel retrouvé dans la
   borne.
4. Tests statiques catalogue, E2E catalogue et `git diff --check` PASS.

`EXECUTION_TIER: routine` — asset parity only, no business logic.
