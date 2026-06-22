# RUN — CV1-V1.5B-DRILLDOWN-FRONTEND-001 — 2026-05-04

EXECUTE_DELEGATION: foodking-routine-implementer

## Fichiers modifiés

| Fichier | Rôle |
|---------|------|
| `resources/js/services/ingredientService.js` | Ajout `getIngredientUsage(globalId)` → `GET /admin/ingredients/{globalId}/usage` (même convention `encodeURIComponent` que `showIngredient`). |
| `resources/js/components/admin/ingredients/IngredientUsageDrawer.vue` | Charge l’usage détaillé, en-tête nom / badge rupture / count, liste `used_by` avec liens `admin_url`, `data-testid` pour tests E2E. |
| `resources/js/languages/fr.json` | +4 clés `label.ingredient.*` (voir tableau ci-dessous). |
| `resources/js/languages/en.json` | Idem. |
| `resources/js/languages/de.json` | Idem. |
| `resources/js/languages/bn.json` | Idem. |
| `resources/js/languages/ar.json` | Idem. |
| `tests/js/ingredientUsageDrawer.spec.js` | Vitest : 5 scénarios demandés + 3 tests existants (titre, Escape, backdrop). |

Aucune modification backend (E1) ni `IngredientListComponent.vue`.

## Nouvelles clés i18n (× 5 langues)

| Clé | fr | en | de | bn | ar |
|-----|----|----|----|----|-----|
| `label.ingredient.status_unavailable` | En rupture | Out of stock | Nicht verfügbar | স্টক নেই | غير متوفر |
| `label.ingredient.usage_empty` | Aucun produit ni catégorie n'utilise cet ingrédient. | No product or category uses this ingredient. | Kein Produkt und keine Kategorie verwendet diese Zutat. | কোনো পণ্য বা বিভাগ এই উপকরণটি ব্যবহার করে না। | لا يستخدم أي منتج أو فئة هذه المادة. |
| `label.ingredient.owner_category` | Catégorie | Category | Kategorie | বিভাগ | فئة |
| `label.ingredient.owner_item` | Article (legacy) | Item (legacy) | Artikel (Legacy) | আইটেম (লিগাসি) | صنف (قديم) |

`label.ingredient.usage_count` conservée sans changement.

## Validation

### Vitest ciblé — `tests/js/ingredientUsageDrawer.spec.js`

```text
✓ tests/js/ingredientUsageDrawer.spec.js  (8 tests) 38ms
Test Files  1 passed (1)
```

### Sentinelle parity — `tests/js/labelKeyParityFrontend.spec.js`

```text
✓ tests/js/labelKeyParityFrontend.spec.js  (1 test) 25ms
Test Files  1 passed (1)
```

### Vitest global (`npx vitest run`)

Échecs **ENOSPC** (disque saturé dans `/var/folders/…`, fichiers temporaires Vitest) sur 4 fichiers non liés au drawer ; **189 fichiers de test passés**, **1142 tests** exécutés avec succès dans cette run. Rejouer `npx vitest run` après libération d’espace pour un vert complet.

```text
Test Files  4 failed | 189 passed (193)
Tests  1142 passed | 2 skipped (1144)
```

### Build — `npm run dev`

```text
✔ Mix: Compiled successfully in 30.55s
webpack compiled successfully
```

## A11y check

- Liens **natifs** `<a :href="entry.admin_url">` : navigation clavier et lecteur d’écran standard.
- **Focus visible** : `focus:outline-none focus-visible:ring-2 focus-visible:ring-blue-500 focus-visible:ring-offset-2` sur les liens.
- **Focus trap** existant : sélecteur `button, [href], …` inclut les nouveaux liens ; `role="dialog"`, `aria-modal="true"`, handler Escape inchangés.
- **Liste** : `role="list"` sur `<ul>`.
- **Tests / E2E** : `data-testid` sur loading, error, name, count, empty, list, et par entrée `ingredient-usage-entry-{type}-{id}`.
