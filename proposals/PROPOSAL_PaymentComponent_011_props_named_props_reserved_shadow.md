# PROPOSAL — PaymentComponent.vue:379-381 — Prop named `props` shadows Vue reserved name and lacks validator

**ID** : PROP-PAY-011
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The component declaration at lines 379-381 :

```js
props: {
    props: Object,
},
```

The single prop is named **`props`** — same identifier as Vue's `this.$props` and Composition API `defineProps` return value. This causes :

1. **Confusing semantics** — every reference to `props.form.total` (lines 28, 117, 130, ...) reads like `this.$props.form.total` is being accessed but it's actually a nested prop named `props`. Inside the component template, `props.form` resolves correctly because Vue's Options-API exposes data/props/computed on `this`. But the inner `props` prop SHADOWS the conceptual idea of "the component's props bag".
2. **No `type` validation** — `Object` is loose. No required flag, no default, no validator function. If parent passes undefined, optional chaining (`this.props?.form`) catches it but the empty fallback `|| {}` (line 417) silently masks the issue.
3. **No documentation** — what shape is `props`? It's `{form: {total, pos_payment_method, items, ...}, ...}` based on usage but no JSDoc/TS hints.
4. **JSDoc emits authority** is declared (line 366-378) but no corresponding "props authority" JSDoc — half-done discipline.

**Likely origin** : the parent component (`AdminPosV4Component.vue` or similar) wraps its own state in a `props` payload to forward to PaymentComponent. The naming was lazy / copy-paste from a generic wrapper pattern. Krishna POS heritage.

**Refactor cost** : rename `props` → `parentForm` (or similar) requires :
- The parent template `<PaymentComponent :props="..." />` becomes `<PaymentComponent :parent-form="..." />`.
- Inside PaymentComponent, all `this.props?.form.X` references become `this.parentForm?.X`.
- ~30 LOC inside PaymentComponent + 1 LOC in parent.
- **Architectural-ish.** DEFER-V1.0.2 or KEEP-AS-IS for V1.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
N/A.

### Cashier perspective
N/A — invisible to user.

### Owner perspective
Code quality / architectural debt. Strong code-reviewer would flag immediately. Doesn't break V1 but doesn't read well.

### Multi-tenant-future
V2 SaaS — same.

### Adversarial dispute (challenge yourself)
- **Real Vue error ?** No — Vue does NOT reserve `props` as a prop name. It's allowed. But style guides (Vue Style Guide, Vue Linter) flag it as confusing.
- **Hidden bug ?** No active bug from the naming alone. `this.props` works.
- **Refactor risk ?** MEDIUM — touches both PaymentComponent.vue AND its parent. Cross-file frozen-zone risk if parent is in any frozen zone. **Verification needed** : `grep -r "<PaymentComponent" resources/js` to find parents.
- **Scope ?** ~30 LOC in PaymentComponent + N LOC in parent. **>>5 LOC LOCK threshold.**

## Proposed change

### Optional rename (not LOCK-feasible per scope)

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 379-381 @@
     props: {
-        props: Object,
+        parentForm: {
+            type: Object,
+            required: true,
+            default: () => ({}),
+            validator(v) {
+                return v && typeof v === 'object';
+            },
+        },
     },
```

Then every `this.props?.form.X` → `this.parentForm?.X` (~10 sites inside template, ~6 sites inside script).

Then parent `<PaymentComponent :props="paymentFormBag" />` → `<PaymentComponent :parent-form="paymentFormBag" />`.

### Minimal hardening (add type/required, don't rename) — LOCK-feasible

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 379-381 @@
     props: {
-        props: Object,
+        props: {
+            type: Object,
+            required: true,
+            default: () => ({}),
+        },
     },
```

+4 LOC. Adds runtime validation without rename. **LOCK-feasible.**

## Risk analysis

| Scenario | Risk if minimal applied | Risk if NOT applied |
|----------|------------------------|---------------------|
| Parent passes undefined | Vue warns in dev console (good) | Silent until ?. catches |
| Parent passes non-object | Validator caught in dev | Silent runtime errors |
| Existing tests | None | None |
| Bundle rebuild | Yes | None |
| Frozen-zone diff | +4 LOC script | None |

## LOCK feasibility

- Minimal hardening (+4 LOC type validation) : **YES**, LOCK-feasible.
- Full rename refactor : **NO — touches multiple files, architectural.** DEFER-V1.0.2 or KEEP-AS-IS V1.

## Owner recommendation

[ ] APPLY-WITH-LOCK (minimal hardening only — add required/type/default validator)
[ ] DEFER-V1.0.2 (full rename `props` → `parentForm`)
[ ] DEFER-V2
[ ] KEEP-AS-IS (style smell only, no functional bug)

**Signed-off-by-owner** : ___________  **Date** : ___________
