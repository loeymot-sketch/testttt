# PROPOSAL — PaymentComponent.vue:545-554,700-702,770-772 — 5× `document.getElementById` direct DOM manipulation (anti-Vue)

**ID** : PROP-PAY-010
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The component reaches into the DOM via `document.getElementById` in 5 distinct places :

```js
// line 545 — numpadInput
const el = document.getElementById(this.inputIdName);
if (el) { el.value += val; el.dispatchEvent(new Event('input')); }

// line 549 — numpadBack
const el = document.getElementById(this.inputIdName);
if (el) { el.value = el.value.slice(0, -1); el.dispatchEvent(new Event('input')); }

// line 553 — numpadClear
const el = document.getElementById(this.inputIdName);
if (el) { el.value = ''; el.dispatchEvent(new Event('input')); }

// line 700-702 — collectPaymentInputPatch
if (form.pos_payment_method === this.posPaymentMethodEnum.CASH) {
    const cashInput = document.getElementById('cashInput');
    patch.pos_received_amount = cashInput && cashInput.value ? parseFloat(cashInput.value) : null;
}

// line 770-772 — alignCashReceivedWithQuotedTotal
const cashInput = document.getElementById('cashInput');
if (cashInput) {
    cashInput.value = String(bumped);
    cashInput.dispatchEvent(new Event('input', { bubbles: true }));
}
```

**Vue anti-pattern** : direct `document.getElementById` bypasses Vue's reactivity. The `cashReceivedRaw` data property exists (line 390) and is already updated by `onCashInput` (line 541-543). The DOM-direct read is redundant — `this.cashReceivedRaw` is the SSOT.

Same for write-back : the alignment method writes to the DOM but ALSO sets `this.cashReceivedRaw = bumped` (line 775). Vue would auto-render the new value if the input were bound to the data via `v-model="cashReceivedRaw"`. Currently the input is uncontrolled (`@input` only), so the DOM write IS necessary to update the displayed value. Refactoring to `v-model` would eliminate the DOM write.

**Risks of the current pattern** :
1. **SSR / unit-test fragility** : `document` is not always defined in test environments (jsdom should provide it, but some Vue test setups stub it differently). The `getElementById` returns null in non-DOM contexts → `if (el)` guards make it silent-fail, but the logic block (e.g. cash received amount collection in `collectPaymentInputPatch`) returns null where the live form has a value.
2. **ID collisions** : the modal renders inside the app shell. If two PaymentComponent instances are ever rendered simultaneously (unlikely V1 but technically possible — RECEIPT triggers from "Encore une commande" → re-mount race), both reach for `getElementById('cashInput')` and only the first match in DOM order wins. Vue `$refs` would be component-scoped.
3. **CSS/template refactor brittleness** : if the input id is renamed for any reason, the JS string `'cashInput'` is not type-checked. Vue `$refs` would surface the issue via missing ref.
4. **Test stubs** : the existing PaymentComponent.spec.js (if any) would need to manually mock `document.getElementById` to test numpad → input flow. With `$refs`, the test just queries `wrapper.find('input')`.

The existing `$refs.cashInput` is declared (line 88) but ONLY used for `resetPaymentInputs` (line 557-561) and not for the numpad/collect/align paths.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
N/A — pure code-quality concern, no visible behavior difference for happy path.

### Cashier perspective
If a regression hits (race condition, ID collision, SSR migration), the cashier sees broken numpad behavior. Cause hard to debug.

### Owner perspective
Code-quality / architecture debt. Doesn't block V1 ship but flagged by any code review.

### Multi-tenant-future
V2 SaaS — same. The pattern propagates as the code is duplicated.

### Adversarial dispute (challenge yourself)
- **False positive ?** Verified all 5 call-sites. **NOT a false positive.**
- **Real bug or code smell ?** Code smell with theoretical risk. Not a P0/P1 bug. **Should be flagged as architectural improvement, not urgent fix.**
- **Scope ?** Refactor to `this.$refs.cashInput.value` / `this.$refs.cardInput.value`. AND ideally migrate `cashInput` to `v-model="cashReceivedRaw"` for true Vue reactivity. ~20 LOC change across 5 sites + template binding. **Architectural-ish, > LOCK threshold.**

## Proposed change

### Minimum-viable patch (refs only, no v-model migration — ~15 LOC)

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 544-555 @@
         numpadInput(val) {
-            const el = document.getElementById(this.inputIdName);
+            const el = this.$refs[this.inputIdName];
             if (el) { el.value += val; el.dispatchEvent(new Event('input')); }
         },
         numpadBack() {
-            const el = document.getElementById(this.inputIdName);
+            const el = this.$refs[this.inputIdName];
             if (el) { el.value = el.value.slice(0, -1); el.dispatchEvent(new Event('input')); }
         },
         numpadClear() {
-            const el = document.getElementById(this.inputIdName);
+            const el = this.$refs[this.inputIdName];
             if (el) { el.value = ''; el.dispatchEvent(new Event('input')); }
         },

@@ line 700-702 @@
             if (form.pos_payment_method === this.posPaymentMethodEnum.CASH) {
-                const cashInput = document.getElementById('cashInput');
+                const cashInput = this.$refs.cashInput;
                 patch.pos_received_amount = cashInput && cashInput.value ? parseFloat(cashInput.value) : null;
             }

@@ line 770-774 @@
-            const cashInput = document.getElementById('cashInput');
+            const cashInput = this.$refs.cashInput;
             if (cashInput) {
                 cashInput.value = String(bumped);
                 cashInput.dispatchEvent(new Event('input', { bubbles: true }));
             }
```

Net : -5 LOC, +5 LOC = 0 net but 5 lines changed. **LOCK-feasible** as it's a mechanical rename.

`this.inputIdName` already toggles between `'cashInput'` / `'cardInput'` matching ref names. Refs are already declared at template lines 88 and 145.

### Full patch with `v-model` migration

Adds `v-model="cashReceivedRaw"` to the input, removes the manual `el.value = ...` writes (Vue handles them), removes the DOM `dispatchEvent('input')` (Vue reactivity propagates automatically). Architectural — DEFER-V1.0.2.

## Risk analysis

| Scenario | Risk if minimum applied | Risk if NOT applied |
|----------|------------------------|---------------------|
| Numpad → input flow | None — refs resolve to same DOM nodes | None |
| Unit tests | Easier to write/mock | Same as today |
| SSR migration (V2) | Refs work; document does not | Migration breaks |
| ID collision edge case | Component-scoped refs immune | Theoretical risk |
| Bundle rebuild | Yes | None |

## LOCK feasibility

- Minimum-viable (5 sites refactor) : **YES**, LOCK-feasible. Mechanical rename. Risk near-zero.
- Full v-model migration : **NO — architectural-ish, DEFER-V1.0.2.**

## Owner recommendation

[ ] APPLY-WITH-LOCK (minimum-viable refs refactor)
[ ] DEFER-V1.0.2 (full v-model migration)
[ ] DEFER-V2
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________
