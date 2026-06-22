# RUN — P11_POS_CART_PRUNE_TEST_SCOPED

**Date:** 2026-04-20  
**Status:** SUCCESS  
**Spec:** `tests/js/posCartPruneScoped.spec.js` (4 tests)

---

## Vitest — `tests/js/posCartPruneScoped.spec.js` (verbose)

```
 RUN  v1.6.1 /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt

 ✓ tests/js/posCartPruneScoped.spec.js > posCart/pruneUnavailable scoped persistence [P12_POS_CART_PRUNE / POS-9.1.9 interaction] > scope unset → pruneUnavailable does not persist
 ✓ tests/js/posCartPruneScoped.spec.js > posCart/pruneUnavailable scoped persistence [P12_POS_CART_PRUNE / POS-9.1.9 interaction] > scope set → pruneUnavailable persists under the scoped key only
 ✓ tests/js/posCartPruneScoped.spec.js > posCart/pruneUnavailable scoped persistence [P12_POS_CART_PRUNE / POS-9.1.9 interaction] > scope set + no-op (unknown item) → no localStorage write
 ✓ tests/js/posCartPruneScoped.spec.js > posCart/pruneUnavailable scoped persistence [P12_POS_CART_PRUNE / POS-9.1.9 interaction] > isolation: two branch keys coexist without cross-write (b7 vs b8)

 Test Files  1 passed (1)
      Tests  4 passed (4)
   Start at  21:41:57
   Duration  376ms (transform 39ms, setup 0ms, collect 49ms, tests 4ms, environment 156ms, prepare 48ms)
```

---

## Vitest — suite globale (tail, `reporter=basic`)

```
 ✓ tests/js/KioskLogin.spec.js  (1 test) 23ms
 ✓ tests/js/kioskMedia.spec.js  (3 tests) 1ms
 ✓ tests/js/kioskDrinkAddons.spec.js  (2 tests) 3ms
 ✓ tests/js/kioskDisplayText.spec.js  (2 tests) 2ms

 Test Files  56 passed (56)
      Tests  423 passed (423)
   Start at  21:42:01
   Duration  3.57s (transform 3.08s, setup 0ms, collect 8.31s, tests 1.68s, environment 11.42s, prepare 3.40s)
```

---

## Fichier complet — `tests/js/posCartPruneScoped.spec.js`

```js
import { describe, it, expect, beforeEach } from 'vitest';
import { posCart, _applyPosCartScope } from '../../resources/js/store/modules/posCart';

/**
 * [P12 / POS-9.1.9] pruneUnavailable must persist only under getScopedKey()
 * and must not cross-contaminate branch-scoped keys.
 */

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
        lists: itemIds.map((id) => ({
            item_id: id,
            name: `Item ${id}`,
            quantity: 1,
            convert_price: 5.0,
            item_variation_total: 0,
            item_extra_total: 0,
            item_variations: { variations: {}, names: {} },
            item_extras: { extras: [], names: [] },
            image: null,
            instruction: '',
            discount: 0,
            currency_price: '5.00€',
            pos_line_addons: [],
            cart_display: '',
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

    it('scope unset → pruneUnavailable does not persist', () => {
        const state = makeStateWithLines([1]);
        posCart.mutations.pruneUnavailable(state, 1);
        expect(state.lists).toHaveLength(0);
        expect(localStorageMock._dump()).toEqual([]);
    });

    it('scope set → pruneUnavailable persists under the scoped key only', () => {
        _applyPosCartScope(7, 42);
        const state = makeStateWithLines([1, 2]);
        posCart.mutations.pruneUnavailable(state, 1);
        expect(state.lists).toHaveLength(1);
        expect(state.lists[0].item_id).toBe(2);
        const keys = localStorageMock._dump();
        expect(keys).toEqual(['pos_cart_v3:b7:u42']);
        const raw = localStorageMock.getItem('pos_cart_v3:b7:u42');
        const parsed = JSON.parse(raw);
        expect(parsed.lists).toHaveLength(1);
        expect(parsed.lists[0].item_id).toBe(2);
        expect(parsed.branchId).toBe(7);
        expect(parsed.userId).toBe(42);
    });

    it('scope set + no-op (unknown item) → no localStorage write', () => {
        _applyPosCartScope(7, 42);
        const state = makeStateWithLines([1]);
        posCart.mutations.pruneUnavailable(state, 999);
        expect(state.lists).toHaveLength(1);
        expect(localStorageMock._dump()).toEqual([]);
    });

    it('isolation: two branch keys coexist without cross-write (b7 vs b8)', () => {
        _applyPosCartScope(7, 42);
        const state7 = makeStateWithLines([1, 2]);
        posCart.mutations.pruneUnavailable(state7, 1);
        expect(localStorageMock._dump()).toEqual(['pos_cart_v3:b7:u42']);

        _applyPosCartScope(8, 42);
        const state8 = makeStateWithLines([1]);
        posCart.mutations.pruneUnavailable(state8, 1);

        const keys = localStorageMock._dump().slice().sort();
        expect(keys).toEqual(['pos_cart_v3:b7:u42', 'pos_cart_v3:b8:u42'].sort());

        const b7 = JSON.parse(localStorageMock.getItem('pos_cart_v3:b7:u42'));
        const b8 = JSON.parse(localStorageMock.getItem('pos_cart_v3:b8:u42'));
        expect(b7.lists.map((l) => l.item_id)).toEqual([2]);
        expect(b8.lists).toEqual([]);
        expect(b7.branchId).toBe(7);
        expect(b8.branchId).toBe(8);
        expect(b7.userId).toBe(42);
        expect(b8.userId).toBe(42);
    });
});
```

---

## Note

Le bloc ci-dessus est une copie fidèle du fichier au moment du RUN ; en cas de divergence, le fichier source dans `tests/js/` fait foi.

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Re-run nouveau spec | 4/4 ✓ (613ms) |
| 2 | Suite globale Vitest | 423/423 ✓ (56 fichiers, 0 régression — ajout exact de 4 tests vs 419 avant) |
| 3 | Aucun fichier `resources/`, `app/` modifié par ce cycle | confirmé via `git status` |
| 4 | Test file size | 104 lignes (compact, lisible) |
| 5 | Cas 4 (isolation multi-branche) | validé : les clés `pos_cart_v3:b7:u42` et `pos_cart_v3:b8:u42` coexistent sans cross-write |
| 6 | Pattern conforme | import direct du store + `_applyPosCartScope` (pattern `posCartScoped.spec.js`) |

**Valeur produite** :
- **Confirmation invariant POS-9.1.9 préservé** par `pruneUnavailable` : la mutation respecte le scoping multi-branche, aucune fuite cross-branch
- Couverture du test V6 #1 (logique pure filter) **complétée** par V7 #2 (interaction scoping)
- Pattern de test scoped réutilisable pour de futures mutations posCart
- Ajustement implicite de la "lesson learned" de V6 #1 : le store EST importable directement (le pattern simulation de `posCart.spec.js` était trop conservateur — `posCartScoped.spec.js` avait raison)

**Note** : si V5 #1 (remédiation `dispatch-after-commit`) implique un changement de comportement dans `posCart` (improbable, mais possible si une mutation déclenche un event différé), ce test reste robuste car il valide uniquement les écritures `localStorage`, pas les events.
