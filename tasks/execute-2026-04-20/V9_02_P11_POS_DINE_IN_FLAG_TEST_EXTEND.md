# EXECUTE V9 #2 — P11_POS_DINE_IN_FLAG_TEST_EXTEND

TASK_ID: P11_POS_DINE_IN_FLAG_TEST_EXTEND
WAVE: V9 salve U (couverture POS dine-in flag, no gate)
PRIMARY_MODEL: composer (foodking-routine-implementer)
SOURCE: PLAN_POST_VERIFY V4 — option U recommandée

---

## Contexte / pourquoi ce cycle

`tests/js/posDineInFlag.spec.js` couvre le résolveur du flag `pos.dine_in_enabled` mais reste à 4 it() basiques :
- defaults empty
- explicit 0/false
- 1/true
- dotted-key variant

Manque :
- **Précédence** entre clé snake_case (`pos_dine_in_enabled`) et clé dotted (`pos.dine_in_enabled`) — la fonction utilise `??` donc snake_case gagne mais le comportement est subtil quand snake_case = 0 (court-circuit `??` ne déclenche pas le fallback)
- **Edge cases boolean strings** : `'true'`, `'TRUE'`, `'yes'` doivent être strict-false (la fonction n'accepte que `'1'` ou `true`)
- **Edge cases numeric weird** : `2`, `-1`, `0.5` (uniquement `1` et `'1'` doivent être true)
- **Edge cases nullish** : `null` direct sur la clé, `NaN`
- **Edge cases types** : objet, array, fonction (tous false)

Risque actuel : si quelqu'un ajoute du laxisme (`raw === 'true' || raw === 'yes'`), pas de test pour le détecter → régression silencieuse de la sémantique stricte du flag.

---

## Goal

Étendre `tests/js/posDineInFlag.spec.js` avec **6 nouveaux `it()`** couvrant :
1. Précédence snake_case vs dotted-key (snake_case gagne, court-circuit `??` sur 0)
2. Boolean strings non strictes (`'true'`, `'TRUE'`, `'yes'` → false)
3. Numeric weird (`2`, `-1`, `0.5` → false)
4. Nullish explicite (`null`, `NaN` → false)
5. Types non-primitifs (`{}`, `[]`, `() => {}` → false)
6. Coercion `String(raw) === '1'` (vérifier qu'un nombre 1 string-ifié `String(1) === '1'` passe → déjà couvert mais ajouter test explicite que la coercion est bien intentionnelle)

---

## Scope

| Fichier | Action |
|---|---|
| `tests/js/posDineInFlag.spec.js` | EDIT — ajout de 6 `it()`, conservation des 4 existants |

**SUBSYSTEMS_TOUCHED**: 1 fichier de test JS.
**SUBSYSTEMS_OFF_LIMITS**: TOUT le code applicatif. Pas de modif de la logique du flag (la fonction `dineInEnabledFrom` est dupliquée dans le test, on ne touche QUE le test).
**INVARIANTS_AT_RISK**: aucun.

---

## Spécification

### Étape 1 — Lire l'existant

Lire `tests/js/posDineInFlag.spec.js` (38 lignes, déjà fourni dans le contexte recon orchestrateur).

### Étape 2 — Ajouter les 6 nouveaux `it()`

Insérer **APRÈS** les 4 `it()` existants, **AVANT** le `});` de fin du `describe()`.

```javascript
    it('snake_case key wins over dotted-key (preserves intentional 0)', () => {
        // ?? short-circuits on 0 → does NOT fallback to dotted-key
        expect(dineInEnabledFrom({
            pos_dine_in_enabled: 0,
            'pos.dine_in_enabled': 1,
        })).toBe(false);
        // snake_case = 1 wins regardless of dotted-key
        expect(dineInEnabledFrom({
            pos_dine_in_enabled: 1,
            'pos.dine_in_enabled': 0,
        })).toBe(true);
    });

    it('rejects non-strict boolean strings (true/TRUE/yes)', () => {
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'true' })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'TRUE' })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'yes' })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'on' })).toBe(false);
    });

    it('rejects numeric values other than 1 or "1"', () => {
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 2 })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: -1 })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 0.5 })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: '2' })).toBe(false);
    });

    it('rejects explicit null / NaN on the value', () => {
        expect(dineInEnabledFrom({ pos_dine_in_enabled: null })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: NaN })).toBe(false);
        // undefined on the value triggers ?? fallback to next key, then default 0
        expect(dineInEnabledFrom({ pos_dine_in_enabled: undefined })).toBe(false);
    });

    it('rejects non-primitive values (object, array, function)', () => {
        expect(dineInEnabledFrom({ pos_dine_in_enabled: {} })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: [] })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: [1] })).toBe(false);
        expect(dineInEnabledFrom({ pos_dine_in_enabled: () => 1 })).toBe(false);
    });

    it('coerces numeric 1 to "1" via String() comparison (intentional)', () => {
        // Documents the design choice: String(raw) === '1' is intentional
        // to handle backend payload variants (Eloquent cast 0/1 vs '0'/'1').
        expect(String(1)).toBe('1');
        expect(dineInEnabledFrom({ pos_dine_in_enabled: 1 })).toBe(true);
        expect(String('1')).toBe('1');
        expect(dineInEnabledFrom({ pos_dine_in_enabled: '1' })).toBe(true);
    });
```

### Étape 3 — Run

```bash
npx vitest run tests/js/posDineInFlag.spec.js
```

Doit afficher **10 tests passed** (4 existants + 6 nouveaux).

Si un nouveau test échoue → analyser la cause. Si la fonction `dineInEnabledFrom` réelle (dans le test, c'est une copie locale) a un comportement non documenté → ajuster le test pour refléter la réalité, NE PAS modifier la fonction du codebase production.

---

## VALIDATE

1. `npx vitest run tests/js/posDineInFlag.spec.js` → 10/10 passed
2. Diff `tests/js/posDineInFlag.spec.js` : +~50/-0 lignes (uniquement ajout, jamais suppression)
3. Aucun autre fichier modifié (pas de touche au code production de la fonction)

---

## REPORT_FILE

`reports/execution/RUN_P11_POS_DINE_IN_FLAG_TEST_EXTEND_2026-04-20.md` — diff complet + sortie vitest.

---

## SCOPE_PRESSURE

- ❌ NE PAS modifier la fonction `dineInEnabledFrom` réelle du codebase (chercher où elle est définie en prod : probablement dans `PosComponent.vue` ou un helper, mais hors scope ici)
- ❌ NE PAS modifier les 4 `it()` existants
- ❌ NE PAS toucher à la copie de `dineInEnabledFrom` au top du spec (ligne 9-13)
- ❌ Pas de `git add/commit`
- ⚠️ Si un nouveau test révèle un comportement de la fonction qui semble buggé (ex: `String([]) === '1'` accidentellement true) → DOCUMENTER dans le rapport mais NE PAS fixer le code production dans ce cycle (créer un nouveau cycle)
