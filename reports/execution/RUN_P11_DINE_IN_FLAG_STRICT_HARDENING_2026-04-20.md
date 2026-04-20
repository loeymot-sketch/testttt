# RUN — P11_DINE_IN_FLAG_STRICT_HARDENING (V10 #1)

**TASK_ID:** P11_DINE_IN_FLAG_STRICT_HARDENING  
**Date:** 2026-04-20  
**Statut:** SUCCESS

## Résumé

- Ajout d’un garde `typeof` après le fallback `?? 0` et avant `String(raw)` dans la computed `dineInEnabled` de `PosComponent.vue`, et synchronisation de `dineInEnabledFrom` dans `tests/js/posDineInFlag.spec.js`.
- Test array `[1, 2]` → `[1]` dans `it('rejects non-primitive values...')`.
- Nouveau `it('[V10 #1] strict typeof guard prevents String([1]) === "1" leak')`.

---

## Diff — `resources/js/components/admin/pos/PosComponent.vue` (uniquement `dineInEnabled`)

```diff
--- a/resources/js/components/admin/pos/PosComponent.vue
+++ b/resources/js/components/admin/pos/PosComponent.vue
         dineInEnabled: function () {
             const s = this.setting || {};
             const raw = s.pos_dine_in_enabled ?? s['pos.dine_in_enabled'] ?? 0;
+            // [V10 #1] Strict typeof guard: reject arrays/objects/functions before
+            // coercion (String([1]) === '1' would otherwise activate the flag).
+            const t = typeof raw;
+            if (t !== 'boolean' && t !== 'number' && t !== 'string') return false;
             return String(raw) === '1' || raw === true;
         },
```

---

## Diff complet — `tests/js/posDineInFlag.spec.js`

*(par rapport à l’index git au moment du run — inclut les tests V9 #2 si non commités)*

```diff
diff --git a/tests/js/posDineInFlag.spec.js b/tests/js/posDineInFlag.spec.js
index fedad3bdb..84a918a17 100644
--- a/tests/js/posDineInFlag.spec.js
+++ b/tests/js/posDineInFlag.spec.js
@@ -9,6 +9,8 @@ import { describe, it, expect } from 'vitest';
 function dineInEnabledFrom(setting) {
     const s = setting || {};
     const raw = s.pos_dine_in_enabled ?? s['pos.dine_in_enabled'] ?? 0;
+    const t = typeof raw;
+    if (t !== 'boolean' && t !== 'number' && t !== 'string') return false;
     return String(raw) === '1' || raw === true;
 }
 
@@ -35,4 +37,63 @@ describe('POS dine-in feature flag', () => {
     it('accepts the dotted-key variant pos.dine_in_enabled', () => {
         expect(dineInEnabledFrom({ 'pos.dine_in_enabled': '1' })).toBe(true);
     });
+
+    it('snake_case key wins over dotted-key (preserves intentional 0)', () => {
+        // ?? short-circuits on 0 → does NOT fallback to dotted-key
+        expect(dineInEnabledFrom({
+            pos_dine_in_enabled: 0,
+            'pos.dine_in_enabled': 1,
+        })).toBe(false);
+        // snake_case = 1 wins regardless of dotted-key
+        expect(dineInEnabledFrom({
+            pos_dine_in_enabled: 1,
+            'pos.dine_in_enabled': 0,
+        })).toBe(true);
+    });
+
+    it('rejects non-strict boolean strings (true/TRUE/yes)', () => {
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'true' })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'TRUE' })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'yes' })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: 'on' })).toBe(false);
+    });
+
+    it('rejects numeric values other than 1 or "1"', () => {
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: 2 })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: -1 })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: 0.5 })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: '2' })).toBe(false);
+    });
+
+    it('rejects explicit null / NaN on the value', () => {
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: null })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: NaN })).toBe(false);
+        // undefined on the value triggers ?? fallback to next key, then default 0
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: undefined })).toBe(false);
+    });
+
+    it('rejects non-primitive values (object, array, function)', () => {
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: {} })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: [] })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: [1] })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: () => 1 })).toBe(false);
+    });
+
+    it('coerces numeric 1 to "1" via String() comparison (intentional)', () => {
+        // Documents the design choice: String(raw) === '1' is intentional
+        // to handle backend payload variants (Eloquent cast 0/1 vs '0'/'1').
+        expect(String(1)).toBe('1');
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: 1 })).toBe(true);
+        expect(String('1')).toBe('1');
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: '1' })).toBe(true);
+    });
+
+    it('[V10 #1] strict typeof guard prevents String([1]) === "1" leak', () => {
+        // Pre-V10 #1: dineInEnabledFrom({pos_dine_in_enabled: [1]}) returned true
+        // because String([1]) === '1'. Hardened with typeof check.
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: [1] })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: ['1'] })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: Symbol('1') })).toBe(false);
+        expect(dineInEnabledFrom({ pos_dine_in_enabled: 1n })).toBe(false); // BigInt
+    });
 });
```

---

## Vitest — `npx vitest run tests/js/posDineInFlag.spec.js`

```
 RUN  v1.6.1 /Users/1millnonstop/Downloads/projet/foodking-web/web/testttt

 ✓ tests/js/posDineInFlag.spec.js  (11 tests) 2ms

 Test Files  1 passed (1)
      Tests  11 passed (11)
   Start at  22:13:41
   Duration  333ms (transform 17ms, setup 0ms, collect 11ms, tests 2ms, environment 158ms, prepare 49ms)
```

**Compte:** **11 / 11** tests passed.

---

## VALIDATE (plan)

| Check | Résultat |
|-------|----------|
| `grep -c "typeof raw" PosComponent.vue` | 1 |
| `grep -c "String(raw) === '1'" PosComponent.vue` | 1 |

---

## Risque résiduel / suivi

- Aucun pour le flag : comportement plus strict uniquement pour types non primitifs ; payloads backend `0`/`1`/string inchangés.
- Pour le validateur : si le dépôt montre d’autres hunks non commités sur `PosComponent.vue` (hors `dineInEnabled`), ils ne font pas partie de ce RUN.
