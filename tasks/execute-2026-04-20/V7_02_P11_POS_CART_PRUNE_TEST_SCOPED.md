# EXECUTE V7 #2 — P11_POS_CART_PRUNE_TEST_SCOPED

TASK_ID: P11_POS_CART_PRUNE_TEST_SCOPED
WAVE: V7 salve P (couverture multi-branch isolation, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: V6 #1 (`P11_POS_CART_PRUNE_TEST`) — note d'audit : test ne couvrait pas le scoping multi-branche

---

## Goal

Compléter la couverture Vitest de `posCart/pruneUnavailable` (V4 #2) en testant son **interaction avec le scoping multi-branche** (`getScopedKey` / `_applyPosCartScope`). Le test V6 #1 valide la logique de filtre + discount reset, mais utilise une simulation `saveCartToStorage` simpliste (clé `pos_cart_v2`) qui ne reflète pas la vraie persistance scopée v3 (`pos_cart_v3:b<branch>:u<user>`).

**Cas spécifique à valider** : la mutation `pruneUnavailable` doit écrire dans la clé scopée correcte si le scope est set, et ne rien écrire si scope unset (cf. `posCartScoped.spec.js` pour le pattern).

---

## Scope

| Fichier | Action |
|---|---|
| `tests/js/posCartPruneScoped.spec.js` | CREATE — nouveau spec Vitest |

**SUBSYSTEMS_TOUCHED**: tests Vitest uniquement.
**SUBSYSTEMS_OFF_LIMITS**: code applicatif.
**INVARIANTS_AT_RISK**: aucun.

---

## Spécification

### Pattern à suivre

`tests/js/posCartScoped.spec.js` (déjà existant) — pattern **import direct** du store via :
```js
import { posCart, _applyPosCartScope } from '../../resources/js/store/modules/posCart';
```

C'est **différent** du pattern simulation utilisé en V6 #1. Le store EST importable directement (V6 #1 avait fait l'hypothèse erronée du contraire car suivi `posCart.spec.js` qui est un ancien spec). Utiliser le pattern import direct ici.

### Cas de test à couvrir (au moins 4)

1. **scope unset → pruneUnavailable ne persiste pas** :
   - `_applyPosCartScope(null, null)`
   - state contient 1 ligne `item_id: 1`
   - `posCart.mutations.pruneUnavailable(state, 1)` → state.lists vide, mais `localStorage._dump()` reste `[]`
2. **scope set → pruneUnavailable persiste sous la clé scopée correcte** :
   - `_applyPosCartScope(7, 42)`
   - state contient 2 lignes (`item_id: 1`, `item_id: 2`)
   - `posCart.mutations.pruneUnavailable(state, 1)` → state.lists = [item 2], `localStorage` contient EXACTEMENT `pos_cart_v3:b7:u42` avec `lists: [{item_id: 2, ...}]`
3. **scope set + no-op (item inconnu) → pas d'écriture localStorage** :
   - `_applyPosCartScope(7, 42)`
   - state contient `item_id: 1`
   - `posCart.mutations.pruneUnavailable(state, 999)` → state.lists inchangé, `localStorage._dump()` reste `[]` (pas de write inutile)
4. **isolation entre branches** :
   - `_applyPosCartScope(7, 42)` + state avec `item_id: 1, 2` + `pruneUnavailable(1)` → écrit `pos_cart_v3:b7:u42`
   - `_applyPosCartScope(8, 42)` (changement de branche, même user) + state avec `item_id: 1` + `pruneUnavailable(1)` → écrit `pos_cart_v3:b8:u42`
   - vérifier que les 2 clés coexistent dans `localStorage._dump()` (l'une ne pollue pas l'autre)

### Setup

Réutiliser EXACTEMENT le mock `localStorageMock` de `posCartScoped.spec.js` lignes 12-22 (avec `_dump()`).

Réutiliser EXACTEMENT `makeStateWithLine()` de `posCartScoped.spec.js` lignes 24-38 ou créer une variante `makeStateWithLines(itemIds)` qui produit N lignes avec les IDs donnés.

### Squelette

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { posCart, _applyPosCartScope } from '../../resources/js/store/modules/posCart';

const localStorageMock = (() => {
    let store = {};
    return {
        getItem: (k) => Object.prototype.hasOwnProperty.call(store, k) ? store[k] : null,
        setItem: (k, v) => { store[k] = String(v); },
        removeItem: (k) => { delete store[k]; },
        clear: () => { store = {}; },
        _dump: () => Object.keys(store),
    };
})();
Object.defineProperty(globalThis, 'localStorage', { value: localStorageMock, writable: true });

function makeStateWithLines(itemIds) {
    return {
        lists: itemIds.map(id => ({
            item_id: id, name: `Item ${id}`, quantity: 1,
            convert_price: 5.0, item_variation_total: 0, item_extra_total: 0,
            item_variations: { variations: {}, names: {} },
            item_extras: { extras: [], names: [] },
            image: null, instruction: '', discount: 0,
            currency_price: '5.00€', pos_line_addons: [], cart_display: '',
        })),
        subtotal: itemIds.length * 5.0,
        discount: 0,
        restoredFromStorage: false,
    };
}

describe('posCart/pruneUnavailable scoped persistence [P12_POS_CART_PRUNE / POS-9.1.9 interaction]', () => {
    beforeEach(() => {
        localStorageMock.clear();
        _applyPosCartScope(null, null);
    });
    // 4 it() blocks ci-dessus
});
```

---

## VALIDATE

1. `npx vitest run tests/js/posCartPruneScoped.spec.js --reporter=verbose` → 4/4 ✓
2. `npx vitest run --reporter=verbose 2>&1 | tail -10` → suite globale verte (420/420 ✓ environ après ajout des 4 tests à 419/419)
3. Aucun fichier `resources/`, `app/` modifié

---

## REPORT_FILE

`reports/execution/RUN_P11_POS_CART_PRUNE_TEST_SCOPED_2026-04-20.md` — sortie vitest + diff/contenu fichier + tail suite globale.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier `resources/js/store/modules/posCart.js`
- ❌ NE PAS modifier `tests/js/posCartScoped.spec.js` ni `tests/js/posCartPrune.spec.js` (ce nouveau spec est COMPLÉMENTAIRE, pas un remplacement)
- ❌ NE PAS dupliquer les cas déjà couverts par V6 #1 (logique pure de filter) — focus uniquement sur l'interaction avec le scoping
- ❌ Pas de `git add/commit`
- ⚠️ Si l'import `_applyPosCartScope` ne marche pas → vérifier que le module l'exporte bien (lecture rapide de `posCart.js` haut du fichier). Si non exporté, c'est un BLOCKED, ne pas modifier le module pour exposer.
