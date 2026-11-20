# Plan — MOBILE-EXCELLENCE-PHASE19-CATEGORY-ASSET-PARITY-20261016

## Objective

Synchroniser les six images de catégories encore en PNG dans l’app mobile avec
les fichiers exacts servis par le site Le Cayenne, afin que les neuf raccourcis
de catégories utilisent les mêmes assets que la borne/site.

## Scope / allowlist

- `mobile/assets/menu/cat-burgers.png`
- `mobile/assets/menu/cat-bols-gourmands.png`
- `mobile/assets/menu/cat-frites.png`
- `mobile/assets/menu/cat-desserts.png`
- `mobile/assets/menu/cat-boissons.png`
- `mobile/assets/menu/cat-menu-enfant.png`
- `mobile/tests/mobile-catalog-visual.spec.js`
- `tasks/MOBILE-EXCELLENCE-PHASE19-CATEGORY-ASSET-PARITY-20261016.md`
- `reports/execution/MOBILE-EXCELLENCE-PHASE19-CATEGORY-ASSET-PARITY-20261016/report.md`
- `.cursor/ACTIVE_CYCLE.md`
- `reports/AGENT_ACTIVITY_LOG.md`

## Out of scope / frozen

`mobile/data/menu.js`, prix, catalogue métier, backend PHP/config, migrations,
payment/Mollie, auth/SMS, fidélité, branch isolation, dispatch, POS/caisse/kiosk
source et packaging iOS.

## Acceptance criteria

1. Les six fichiers mobiles sont byte-identiques aux six sources
   `public/images/menu/*.png`.
2. Les mappings existants restent inchangés et les neuf catégories continuent
   à charger dans l’accueil/menu sans fallback ni débordement.
3. Les invariants métier et le payload de commande restent inchangés.

## Test strategy

- `node mobile/tests/mobile-catalog-visual.spec.js`
- La suite E2E existante `tests/mobile-e2e/catalog-visual-critical.spec.js`
  reste exécutée en validation, sans modification de son fichier réservé par
  un cycle historique.
- `git diff --check`

## Invariants considered

- backend pricing remains the only price authority;
- no OrderStatus, `branch_id`, dispatch or order service changes;
- existing unrelated dirty worktree changes remain untouched.
