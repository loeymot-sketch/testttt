# RUN — V14 T10 POS search + barcode + F-keys

**TASK_ID:** `V14_08_T10_POS_SEARCH_BARCODE`  
**Date:** 2026-04-20  
**Executor:** foodking-routine-implementer (Composer)

## Résumé

- Migration idempotente `items.barcode` (nullable, index `items_barcode_idx`) via `Schema::hasColumn` guard.
- Endpoint **nouveau** : `GET admin/item/lookup-barcode/{code}` (pas d’équivalent barcode trouvé dans le codebase ; la recherche liste existante reste `admin/item` avec filtres nom/catégorie).
- Helper pur `resources/js/helpers/posBarcode.js` : `createBarcodeDetector` (capture) + `createFKeyShortcuts` (bubble), cleanup par fonction retournée.
- `PosComponent.vue` : debounce **150 ms** sur `itemList()` uniquement ; `props.search.name` mis à jour **immédiatement** sur `@input` ; listeners barcode/F-keys branchés en tête de `mounted()` ; `beforeUnmount()` annule debounce + retire les listeners (sans toucher `_onItemAvailabilityChanged`, ni zone Park/T08).
- Store `item/lookupByBarcode` : `encodeURIComponent`, `console.warn` si `meta.duplicate_barcode`, 404 → `null`.
- i18n `pos.barcode_not_found` : **fr**, **en**, **ar**.
- Vitest `tests/js/posBarcode.spec.js` : **6/6** cas demandés.

## Fichiers modifiés / créés

| Fichier | Action |
|---------|--------|
| `database/migrations/2026_04_20_160000_add_barcode_index_to_items.php` | CREATE |
| `app/Models/Item.php` | `barcode` fillable + cast |
| `app/Http/Controllers/Admin/ItemController.php` | `lookupBarcode()` + log si doublons |
| `routes/api.php` | route `lookup-barcode/{code}` **avant** `/{item}` |
| `resources/js/helpers/posBarcode.js` | CREATE |
| `resources/js/store/modules/item.js` | action `lookupByBarcode` |
| `resources/js/components/admin/pos/PosComponent.vue` | debounce recherche + barcode + F-keys |
| `resources/js/languages/fr.json`, `en.json`, `ar.json` | clé `pos.barcode_not_found` |
| `tests/js/posBarcode.spec.js` | CREATE |

## Preuve cleanup listeners

- `createBarcodeDetector` / `createFKeyShortcuts` retournent `() => removeEventListener(...)`.
- `PosComponent.beforeUnmount` : `cancel()` sur le debounce lodash + invocation des deux teardowns.
- Specs Vitest : chaque test appelle `stop()` dans un `finally` (sentinel d’usage du helper).

## Tests exécutés

```bash
npx vitest run tests/js/PosComponent.spec.js tests/js/posCart.spec.js tests/js/posBarcode.spec.js
```

→ **10/10 passed** (dont 6 `posBarcode.spec.js`).

```bash
php artisan migrate --pretend
```

→ sortie attendue pour `2026_04_20_160000_add_barcode_index_to_items` (check `information_schema` + `ALTER` conditionnels selon état DB).

## Hook post-exécute

`.cursor/hooks/post-execute.sh` a lancé `php artisan test --stop-on-failure` : **1 échec** sur `Tests\Feature\DispatchAfterCommitTest` (sentinelle / invariant dispatch documenté dans le cycle V4 #8 — **hors périmètre T10**).

## TODO / risques résiduels

- Renseigner `barcode` côté BO / import pour les articles scannables.
- Heuristique HID : scanners très lents peuvent être ignorés (voir commentaire dans `posBarcode.js`).
- Validator : confirmer que le gate PHPUnit sur dispatch reste une attente produit, pas une régression T10.

## EXECUTE_DELEGATION

`foodking-routine-implementer` — trace ajoutée dans `reports/post_execute_latest.log`.
