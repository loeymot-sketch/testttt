# Execution — Pont wizard → panier (menu regroupé)

**Date:** 2026-03-10  
**Scope:** POS wizard + `ItemComponent` — éviter 2e ligne panier quand le clic `.addon` ne remplit pas `this.addons`.

## Changements

1. **`public/js/pos-wizard.js`**
   - Avant soumission : `buildWizardPosLineAddonsPayload()` à partir de `selections.menuChoice` + `lastItemData.addons`.
   - Attribut sur la modale : `data-wizard-pos-line-addons` (JSON) en parallèle de `data-wizard-total`.
   - Nettoyage : `openWizard`, `closeWizard` — `removeAttribute('data-wizard-pos-line-addons')`.

2. **`resources/js/components/admin/pos/ItemComponent.vue`**
   - `readWizardBundledAddons()` lit `data-wizard-pos-line-addons`.
   - `buildPosCartMainPayload` : si `this.addons` vide mais JSON wizard présent → remplit `pos_line_addons` et **soustrait** le total menu pour `adjustedBaseConvertPrice` (cohérent avec `bridgedWizardTotal`).

## Risques

- Faible : doublon si à la fois `this.addons` et le JSON wizard étaient remplis — priorité explicite à `this.addons`.
- IDs addon : dépendance sur `ad.id` numérique cohérent avec `addon_N`.

## Validation suggérée

- Vider panier / `pos_cart_v2`, rebuild (`npm run production`), hard refresh.
- Produit avec wizard + formule menu : **une** ligne, libellé vert menu sous le sandwich, total = sandwich + menu.

## Build

- `npm run production` : OK.
