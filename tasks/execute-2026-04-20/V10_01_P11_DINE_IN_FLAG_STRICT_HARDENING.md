# EXECUTE V10 #1 — P11_DINE_IN_FLAG_STRICT_HARDENING

TASK_ID: P11_DINE_IN_FLAG_STRICT_HARDENING
WAVE: V10 salve AA (durcir flag dine-in, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: V9 #2 deviation — `String([1]) === '1'` activerait le flag involontairement

---

## Contexte

V9 #2 a découvert que `dineInEnabledFrom({pos_dine_in_enabled: [1]})` retourne `true` car `String([1]) === '1'` (quirk JavaScript native, Array.toString fait join ','). La fonction est définie dans `resources/js/components/admin/pos/PosComponent.vue:858-862`.

Risque réel : très faible (payload backend normal est `0`/`1`, jamais array). Mais durcissement = défense en profondeur trivial qui ferme la brèche.

---

## Goal

Modifier `dineInEnabled` (computed property) dans `PosComponent.vue` pour ajouter un check `typeof` AVANT la coercion `String()`. Accepter uniquement : `boolean`, `number`, `string`. Rejeter : objets, arrays, fonctions, symboles.

Comportement résultant :
- `pos_dine_in_enabled: 1` → true ✓ (inchangé)
- `pos_dine_in_enabled: '1'` → true ✓ (inchangé)
- `pos_dine_in_enabled: true` → true ✓ (inchangé)
- `pos_dine_in_enabled: 0` / `'0'` / `false` → false ✓ (inchangé)
- `pos_dine_in_enabled: [1]` → **false** (auparavant true) ✓ FIX
- `pos_dine_in_enabled: {}` → false ✓ (inchangé mais maintenant explicite)
- `pos_dine_in_enabled: null` → false ✓ (inchangé via `??` fallback)

---

## Scope

| Fichier | Action |
|---|---|
| `resources/js/components/admin/pos/PosComponent.vue` | EDIT — méthode computed `dineInEnabled` lignes 858-862 |
| `tests/js/posDineInFlag.spec.js` | EDIT — copie locale de la fonction `dineInEnabledFrom` lignes 9-13 (synchronisée) + ajustement test array |

**SUBSYSTEMS_TOUCHED**: 1 computed property POS + 1 test spec.
**SUBSYSTEMS_OFF_LIMITS**: TOUT le reste de PosComponent.vue (méthodes, template, store, lifecycle), TOUS les autres composants POS, TOUS les autres tests.
**INVARIANTS_AT_RISK**: aucun. Le flag devient plus strict, jamais plus laxiste.

---

## Spécification

### Étape 1 — Modifier `PosComponent.vue:858-862`

Avant (current):
```javascript
dineInEnabled: function () {
    const s = this.setting || {};
    const raw = s.pos_dine_in_enabled ?? s['pos.dine_in_enabled'] ?? 0;
    return String(raw) === '1' || raw === true;
},
```

Après (target):
```javascript
dineInEnabled: function () {
    const s = this.setting || {};
    const raw = s.pos_dine_in_enabled ?? s['pos.dine_in_enabled'] ?? 0;
    // [V10 #1] Strict typeof guard: reject arrays/objects/functions before
    // coercion (String([1]) === '1' would otherwise activate the flag).
    const t = typeof raw;
    if (t !== 'boolean' && t !== 'number' && t !== 'string') return false;
    return String(raw) === '1' || raw === true;
},
```

**Indentation** : respecter EXACTEMENT l'indentation actuelle (4 espaces dans la méthode).
**Ne PAS toucher** au commentaire JSDoc au-dessus (lignes 853-857).

### Étape 2 — Synchroniser la copie locale dans le spec

`tests/js/posDineInFlag.spec.js` lignes 9-13 contient une copie de la fonction. La synchroniser avec le même typeof guard :

```javascript
function dineInEnabledFrom(setting) {
    const s = setting || {};
    const raw = s.pos_dine_in_enabled ?? s['pos.dine_in_enabled'] ?? 0;
    const t = typeof raw;
    if (t !== 'boolean' && t !== 'number' && t !== 'string') return false;
    return String(raw) === '1' || raw === true;
}
```

### Étape 3 — Renforcer le test array

Dans le `it('rejects non-primitive values...')` du spec (ajouté en V9 #2), **changer** `[1, 2]` (workaround V9 #2) **par** `[1]` (le vrai cas problématique). Maintenant que la fonction est durcie, ce test doit passer.

Avant V10 #1:
```javascript
expect(dineInEnabledFrom({ pos_dine_in_enabled: [1, 2] })).toBe(false);
```

Après V10 #1:
```javascript
expect(dineInEnabledFrom({ pos_dine_in_enabled: [1] })).toBe(false);
```

### Étape 4 — Ajouter 1 nouveau `it()` documentant le fix

Avant le `});` de fin du `describe()`, ajouter :

```javascript
    it('[V10 #1] strict typeof guard prevents String([1]) === "1" leak', () => {
        // Pre-V10 #1: dineInEnabledFrom({pos_dine_in_enabled: [1]}) returned true
        // because String([1]) === '1'. Hardened with typeof check.
        expect(dineInEnabledFrom({ pos_dine_in_enabled: [1] })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: ['1'] })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: Symbol('1') })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 1n })).toBe(false); // BigInt
    });
```

Note BigInt : `typeof 1n === 'bigint'` → guard rejette → false. C'est le comportement souhaité (les settings backend ne renvoient jamais de BigInt).

### Étape 5 — Run tests

```bash
npx vitest run tests/js/posDineInFlag.spec.js
```

Doit afficher **11 tests passed** (10 existants + 1 nouveau).

---

## VALIDATE

1. `npx vitest run tests/js/posDineInFlag.spec.js` → 11/11 passed
2. Diff `PosComponent.vue` : uniquement la méthode `dineInEnabled` modifiée (3 lignes ajoutées)
3. Diff `posDineInFlag.spec.js` : 3 zones modifiées (copie locale + test array `[1, 2]` → `[1]` + nouveau `it()`)
4. Aucun autre fichier modifié
5. `grep -c "typeof raw" resources/js/components/admin/pos/PosComponent.vue` → 1
6. `grep -c "String(raw) === '1'" resources/js/components/admin/pos/PosComponent.vue` → 1 (l'ancien check est conservé en complément)

---

## REPORT_FILE

`reports/execution/RUN_P11_DINE_IN_FLAG_STRICT_HARDENING_2026-04-20.md` — diff complet + sortie vitest.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier d'autres méthodes/computed/data de PosComponent.vue
- ❌ NE PAS modifier le template HTML qui utilise `dineInEnabled`
- ❌ NE PAS modifier les 4 it() originaux ni les 6 it() ajoutés en V9 #2 (sauf le `[1, 2]` → `[1]` listé Étape 3)
- ❌ NE PAS toucher d'autres tests
- ❌ Pas de `git add/commit`
- ⚠️ Si vitest échoue après l'étape 4, vérifier que le typeof guard est bien APRÈS le `?? 0` fallback (sinon `null ?? 0` donne 0, typeof 'number', OK)
