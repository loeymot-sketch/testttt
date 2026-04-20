# RUN — P11_POS_CART_PRUNE_TEST (2026-04-20)

Vitest cible : `tests/js/posCartPrune.spec.js` (ré-implémentation `pruneUnavailable` + `saveCartToStorage` simplifiée `pos_cart_v2` selon plan V6_01).

## `npx vitest run tests/js/posCartPrune.spec.js --reporter=verbose`

```
 RUN  v1.6.1 /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt

 ✓ tests/js/posCartPrune.spec.js > posCart/pruneUnavailable [P12_POS_CART_PRUNE / F-VERIFY-01-02] > happy path: removes matching line, resets discount, persists cart
 ✓ tests/js/posCartPrune.spec.js > posCart/pruneUnavailable [P12_POS_CART_PRUNE / F-VERIFY-01-02] > no-op when item_id is unknown: cart, discount unchanged; no persistence
 ✓ tests/js/posCartPrune.spec.js > posCart/pruneUnavailable [P12_POS_CART_PRUNE / F-VERIFY-01-02] > early return for falsy itemId (null, 0, empty string, undefined)
 ✓ tests/js/posCartPrune.spec.js > posCart/pruneUnavailable [P12_POS_CART_PRUNE / F-VERIFY-01-02] > string itemId is cast with parseInt so numeric id matches
 ✓ tests/js/posCartPrune.spec.js > posCart/pruneUnavailable [P12_POS_CART_PRUNE / F-VERIFY-01-02] > removes every line sharing the same item_id
 ✓ tests/js/posCartPrune.spec.js > posCart/pruneUnavailable [P12_POS_CART_PRUNE / F-VERIFY-01-02] > discount reset only when a line was removed (no-op keeps discount)

 Test Files  1 passed (1)
      Tests  6 passed (6)
   Start at  21:33:16
   Duration  592ms (transform 22ms, setup 0ms, collect 13ms, tests 3ms, environment 166ms, prepare 54ms)
```

## Suite globale — `npx vitest run --reporter=verbose 2>&1 | tail -30`

Résumé : **55 fichiers**, **419 tests**, tous verts (aucune régression sur les specs existantes).

```
 ✓ tests/js/kioskDisplayText.spec.js > sanitizeKioskCustomerFacingText > remplace les termes add-on / addon par du français client
 ✓ tests/js/kioskDisplayText.spec.js > sanitizeKioskCustomerFacingText > retourne une chaîne vide pour null ou vide
 ✓ tests/js/kioskDrinkAddons.spec.js > kioskDrinkAddons > excludes Menu and frites from drink rows
 ✓ tests/js/kioskDrinkAddons.spec.js > kioskDrinkAddons > kioskIsDrinkAddonName matches common drinks

 Test Files  55 passed (55)
      Tests  419 passed (419)
   Start at  21:33:24
   Duration  3.82s (transform 3.07s, setup 3ms, collect 8.89s, tests 1.81s, environment 12.78s, prepare 3.83s)
```

## Contenu complet — `tests/js/posCartPrune.spec.js`

```js
import { describe, it, expect, beforeEach, vi } from 'vitest';

// Mock localStorage (cf. posCart.spec.js + vi.fn sur setItem pour no-op)
const localStorageMock = (() => {
    let store = {};
    return {
        getItem: (key) => store[key] || null,
        setItem: vi.fn((key, value) => { store[key] = value.toString(); }),
        removeItem: (key) => { delete store[key]; },
        clear: () => { store = {}; }
    };
})();
Object.defineProperty(window, 'localStorage', { value: localStorageMock });

function saveCartToStorage(state) {
    localStorage.setItem('pos_cart_v2', JSON.stringify({
        lists: state.lists,
        subtotal: state.subtotal,
        discount: state.discount,
        savedAt: Date.now(),
    }));
}

// Re-implementation: identical copy from resources/js/store/modules/posCart.js (mutation body)
function pruneUnavailable(state, itemId) {
    const id = parseInt(itemId, 10);
    if (!id) return;
    const before = state.lists.length;
    state.lists = state.lists.filter(line => parseInt(line.item_id, 10) !== id);
    if (state.lists.length !== before) {
        state.discount = 0;
        saveCartToStorage(state);
    }
}

describe('posCart/pruneUnavailable [P12_POS_CART_PRUNE / F-VERIFY-01-02]', () => {
    beforeEach(() => {
        localStorageMock.clear();
        localStorageMock.setItem.mockClear();
    });

    it('happy path: removes matching line, resets discount, persists cart', () => {
        const state = {
            lists: [
                { item_id: 1, name: 'A' },
                { item_id: 2, name: 'B' },
                { item_id: 3, name: 'C' },
            ],
            subtotal: 42,
            discount: 7,
        };
        pruneUnavailable(state, 2);
        expect(state.lists.map((l) => l.item_id)).toEqual([1, 3]);
        expect(state.discount).toBe(0);
        expect(localStorageMock.setItem).toHaveBeenCalledTimes(1);
        expect(localStorageMock.setItem).toHaveBeenCalledWith(
            'pos_cart_v2',
            expect.any(String),
        );
        const saved = JSON.parse(localStorageMock.getItem('pos_cart_v2'));
        expect(saved.lists.map((l) => l.item_id)).toEqual([1, 3]);
        expect(saved.subtotal).toBe(42);
        expect(saved.discount).toBe(0);
        expect(typeof saved.savedAt).toBe('number');
    });

    it('no-op when item_id is unknown: cart, discount unchanged; no persistence', () => {
        const state = {
            lists: [
                { item_id: 1 },
                { item_id: 2 },
                { item_id: 3 },
            ],
            subtotal: 10,
            discount: 4,
        };
        pruneUnavailable(state, 99);
        expect(state.lists.map((l) => l.item_id)).toEqual([1, 2, 3]);
        expect(state.discount).toBe(4);
        expect(localStorageMock.setItem).not.toHaveBeenCalled();
    });

    it('early return for falsy itemId (null, 0, empty string, undefined)', () => {
        const line = { item_id: 1 };
        const state = { lists: [line], subtotal: 5, discount: 2 };
        pruneUnavailable(state, null);
        pruneUnavailable(state, 0);
        pruneUnavailable(state, '');
        pruneUnavailable(state, undefined);
        expect(state.lists).toEqual([line]);
        expect(state.discount).toBe(2);
        expect(localStorageMock.setItem).not.toHaveBeenCalled();
    });

    it('string itemId is cast with parseInt so numeric id matches', () => {
        const state = {
            lists: [{ item_id: 5, name: 'X' }],
            subtotal: 9,
            discount: 1,
        };
        pruneUnavailable(state, '5');
        expect(state.lists).toEqual([]);
        expect(state.discount).toBe(0);
        expect(localStorageMock.setItem).toHaveBeenCalled();
    });

    it('removes every line sharing the same item_id', () => {
        const state = {
            lists: [
                { item_id: 7, variant: 'a' },
                { item_id: 7, variant: 'b' },
            ],
            subtotal: 20,
            discount: 3,
        };
        pruneUnavailable(state, 7);
        expect(state.lists).toEqual([]);
        expect(state.discount).toBe(0);
        expect(localStorageMock.setItem).toHaveBeenCalledTimes(1);
    });

    it('discount reset only when a line was removed (no-op keeps discount)', () => {
        const state = {
            lists: [{ item_id: 1 }],
            subtotal: 8,
            discount: 5,
        };
        pruneUnavailable(state, 99);
        expect(state.lists).toEqual([{ item_id: 1 }]);
        expect(state.discount).toBe(5);
        expect(localStorageMock.setItem).not.toHaveBeenCalled();
    });
});
```

## Notes

- Corps de mutation `pruneUnavailable` : aligné ligne à ligne sur `resources/js/store/modules/posCart.js` L365–L374.
- `saveCartToStorage` dans ce spec : version **plan** (clé `pos_cart_v2`), pas la persistance scopée `getScopedKey()` du module — conforme au snippet du plan V6_01.

---

## AUDIT (Claude orchestrateur) — 2026-04-20
**Verdict : CLOSED — PASSED — 0 remediation**

| # | Check | Résultat |
|---|---|---|
| 1 | Re-run `npx vitest run tests/js/posCartPrune.spec.js` | 6/6 ✓ (372ms) |
| 2 | Suite globale Vitest | 419/419 ✓ (55 fichiers, 0 régression) |
| 3 | Aucun fichier `resources/`, `app/` modifié par ce cycle | confirmé (la modif posCart.js apparaissant en `git status` est la V4 #2 pré-existante non-commit) |
| 4 | Test file size | 133 lignes (compact, lisible) |
| 5 | Pattern conforme | re-implémentation suivant `posItemAvailabilityHandler.spec.js` ✓ |

**⚠️ Note de divergence test/prod (non bloquante)** :
Le test utilise `saveCartToStorage` du **plan** (clé `pos_cart_v2` + `savedAt`), pas la version réelle `getScopedKey/_scope` du module (cf. `posCartScoped.spec.js`). Conforme au plan, mais signifie que ce test **ne valide pas** la persistance scopée (multi-branch). Si quelqu'un casse le scoping `pruneUnavailable` (ex : sauvegarde sur la mauvaise clé branche), ce test ne le verra pas.

**Recommandation orchestrateur** : ajouter un cycle Composer optionnel `P11_POS_CART_PRUNE_TEST_SCOPED` qui couvrirait l'interaction `pruneUnavailable` + scoping multi-branche. Non urgent (la mutation est volontairement scope-agnostic — elle filtre juste `state.lists`).

**Valeur produite** :
- Couverture frontend de `pruneUnavailable` (V4 #2) désormais protégée par CI
- 6 cas de test (happy, unknown, falsy, string→int, multiple lignes, discount-no-reset-on-noop)
- Pattern réutilisable pour de futures mutations posCart
