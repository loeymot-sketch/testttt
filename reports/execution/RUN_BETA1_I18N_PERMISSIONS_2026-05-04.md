# RUN_BETA1_I18N_PERMISSIONS — CV1-V2-CATALOG-BETA1-FRONTEND-001-L — 2026-05-04

## Clés ajoutées (frontend `studio.*` Phase β1)

- **20 chemins feuille** par locale (`composer.diff` × 9 + `composer.conflict` × 3 + `image` × 8), identiques dans **fr, en, de, bn, ar** → **100 entrées de traduction** au total (20 × 5 fichiers).

## Sentinel

- **Fichier** : `tests/js/studioFrontendI18nParity.spec.js` (Vitest : parité des clés `studio.*` entre les 5 locales + sous-ensemble β1 requis).

## Permissions wiring (`CatalogStudioComponent.vue`)

- **RAS, déjà conforme** : `v-if` sur `canCreateCategory`, `canCreateItem`, `canEditCategory`, `canDeleteCategory`, `canViewItem`, `canEditItem`, `canDeleteItem` (via `appService.permissionChecker` : `settings`, `items_create`, `items_show`, `items_edit`, `items_delete`).

## Tests

- `npx vitest run tests/js/studioFrontendI18nParity.spec.js` — PASS (6 tests).
- `npx vitest run` — 165 fichiers, 1068 tests PASS, 2 skipped (`--reporter=line` non supporté par la version Vitest locale : erreur chargement reporter).

## Statut

**PASS** (sous réserve du dernier `vitest run` global ci-dessus sur la machine validate).
