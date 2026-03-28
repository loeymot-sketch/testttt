# Execution — Wizard Kiosk Bug Fixes

**Date:** 2026-03-28  
**Type:** Kimi-test  
**Scope:** Vue kiosk wizard steps + categories (no backend / `buildCartItem` unchanged)

## Summary

- **Bug 7 (`showFritesSauce`)** : déjà correct dans `KioskStepMenuComponent.vue` (`localChoice === 'frites'`).
- **Sauces** : `KioskStepSauceComponent.vue` — accès `variations[String(id)]`, `getSauceOrder` / `toggleSauce` avec `findIndex` + comparaison `String`, `sauceKey` normalise les IDs numériques en `number`, images `lazy` + `@error` + état `brokenSauceThumbs`.
- **Menu** : `menuPrice()` ratios 1 / 0.6 / 0.4 ; `isDrinkAddon` exclut noms contenant `menu`, `frite`, `frites`.
- **Catégories** : `KioskCategoriesComponent.vue` — `<transition name="fade-fast" mode="out-in">` + clé `selectedCategoryId` + CSS.
- **Suppléments** : `KioskStepSupplementsComponent.vue` — même pattern fallback image.
- **Tests** : `tests/js/KioskWizard.spec.js` — montage réel des steps menu/sauce ; **Vitest** : `vitest.config.mjs` + `@vitejs/plugin-vue@5.2.1` (Node 18).

## Tests

```bash
npx vitest run tests/js/KioskWizard.spec.js
```

**Résultat:** 33 passed.

## Risques

- `buildInstruction` / `buildCartItem` côté `KioskWizardComponent` utilisent encore `v.id === id` pour les sauces supplémentaires ; les clés émises restent des **nombres** pour les IDs DB grâce à `sauceKey` — cohérent avec l’existant.

## Fichiers touchés

- `resources/js/components/frontend/kiosk/steps/KioskStepMenuComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSauceComponent.vue`
- `resources/js/components/frontend/kiosk/steps/KioskStepSupplementsComponent.vue`
- `resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `tests/js/KioskWizard.spec.js`
- `vitest.config.mjs` (remplace `vitest.config.js`)
- `package.json` / `package-lock.json` (`@vitejs/plugin-vue`)
