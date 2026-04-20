# EXECUTE V6 #1 — P11_POS_CART_PRUNE_TEST

TASK_ID: P11_POS_CART_PRUNE_TEST
WAVE: V6 salve N (couverture frontend manquante, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: V4 #2 (`P12_POS_CART_PRUNE`) — feature livrée sans test Vitest associé

---

## Goal

Créer un test Vitest pour la mutation+action `posCart/pruneUnavailable` ajoutée en V4 #2. Aujourd'hui la feature est implémentée dans `resources/js/store/modules/posCart.js` (lignes 220-223 action, 365-374 mutation) mais aucun test ne la couvre. Une régression silencieuse (par ex. quelqu'un qui retire `state.discount = 0` ou casse le `parseInt`) passerait inaperçue.

---

## Scope

| Fichier | Action |
|---|---|
| `tests/js/posCartPrune.spec.js` | CREATE — nouveau test Vitest |

**SUBSYSTEMS_TOUCHED**: tests Vitest uniquement.
**SUBSYSTEMS_OFF_LIMITS**: code applicatif (`resources/`, `app/`, `routes/`).
**INVARIANTS_AT_RISK**: aucun (test only).

---

## Spécification

### Pattern à suivre

`tests/js/posCart.spec.js` utilise le pattern **simulation** (recréer la mutation en JS pur) car le store ne peut pas être importé directement sans webpack. Suivre ce même pattern.

Alternative : pattern **re-implémentation** comme `tests/js/posItemAvailabilityHandler.spec.js` qui recopie la fonction handler dans le test.

**Choix recommandé** : pattern **re-implémentation** — recopier la mutation `pruneUnavailable` exactement telle qu'elle est dans `posCart.js` ligne 365-374, l'isoler dans une fonction de test, puis valider tous les cas.

### Cas de test à couvrir (au moins 5)

1. **happy path** : cart contient 3 lignes (item_id 1, 2, 3), `pruneUnavailable(2)` → cart contient 2 lignes (1, 3), discount remis à 0, localStorage mis à jour.
2. **no-op si item_id inconnu** : cart contient (1, 2, 3), `pruneUnavailable(99)` → cart inchangé, discount inchangé, localStorage NON mis à jour (pas d'appel `saveCartToStorage`).
3. **no-op si itemId falsy** : `pruneUnavailable(null)`, `pruneUnavailable(0)`, `pruneUnavailable('')`, `pruneUnavailable(undefined)` → tous early return, cart inchangé.
4. **string itemId casted to int** : cart contient `item_id: 5`, `pruneUnavailable('5')` → ligne supprimée (parseInt fonctionne).
5. **multiple lignes même item_id** : cart contient 2 lignes avec `item_id: 7` (différentes variations), `pruneUnavailable(7)` → toutes les 2 lignes supprimées.
6. **discount reset uniquement si suppression effective** : cart `[item 1 + discount 5]`, `pruneUnavailable(99)` (no-op) → discount reste à 5.

### Setup test

Mocker `localStorage` comme dans `posCart.spec.js` lignes 4-13 (déjà testé OK).

Pour la mutation re-implémentée, simuler aussi la fonction `saveCartToStorage(state)` (mock simple qui appelle `localStorage.setItem('pos_cart_v2', ...)`).

```js
import { describe, it, expect, beforeEach, vi } from 'vitest';

// Mock localStorage (cf. posCart.spec.js)
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

// Re-implementation: identical copy from resources/js/store/modules/posCart.js
function saveCartToStorage(state) {
    localStorage.setItem('pos_cart_v2', JSON.stringify({
        lists: state.lists,
        subtotal: state.subtotal,
        discount: state.discount,
        savedAt: Date.now(),
    }));
}

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
    // 6 it() blocks ci-dessus
});
```

---

## VALIDATE

1. `npx vitest run tests/js/posCartPrune.spec.js --reporter=verbose` → **6/6 ✓**
2. `npx vitest run --reporter=verbose 2>&1 | tail -20` → suite complète Vitest reste verte (pas de régression sur les 54 autres specs)
3. `git status --short tests/js/posCartPrune.spec.js` → fichier créé, untracked
4. Aucun fichier `resources/`, `app/` modifié

---

## REPORT_FILE

`reports/execution/RUN_P11_POS_CART_PRUNE_TEST_2026-04-20.md` — sortie vitest du nouveau spec + diff/contenu du fichier créé + confirmation suite globale verte.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier `resources/js/store/modules/posCart.js` (ce serait modifier l'app pour faire passer le test, pattern toxique)
- ❌ NE PAS modifier d'autres tests existants
- ❌ NE PAS importer le store réel via webpack (pattern `posCart.spec.js` montre que ce n'est pas faisable)
- ❌ Pas de `git add/commit`
- ⚠️ Si la re-implémentation diverge de l'original posCart.js (par ex. on ajoute une optimisation dans le test), c'est un BUG dans le test — copier-coller exact, point.
