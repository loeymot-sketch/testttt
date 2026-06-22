# PROPOSAL — PaymentComponent.vue:308-319 — No keyboard shortcuts (Enter to confirm, Escape to close) — POS speed expectation

**ID** : PROP-PAY-016
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The component has no keyboard event listeners for common POS shortcuts :
- `Enter` to confirm (current behavior : Enter focused on the cash input does nothing distinct from a click on the cash input).
- `Escape` to close (also flagged in PROP-004 modal a11y — duplicate concern, deduped here).

Cashier in rush expects keyboard speed equivalent to a Square / Toast POS — Enter commits, Escape cancels. Currently they must mouse-click or tap the green confirm button.

Note : the input on cash/card mode is `type="text"` (line 89) — pressing Enter inside `<input type="text">` does NOT submit a parent form by default in this template (there's no `<form>` element wrapping the modal body). So Enter currently does nothing.

Per memory bootstrap §X (Wave X — "X2 POS shortcuts" from Wave X test-e2e convergence 2026-05-21), some shortcuts have been ADDED elsewhere (PosV5 shortcuts). PaymentComponent did NOT receive any during that wave per the commit log review (commits 9186a02d2..adad9161f scope did not touch PaymentComponent.vue per the file's frozen status).

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
N/A — invisible to client.

### Cashier perspective
POS speed mandate. 5-10% throughput hit per missing shortcut over 100 transactions/day.

### Owner perspective
Q11 / Wave X "POS shortcuts" awareness. Owner has invested in shortcuts elsewhere. PaymentComponent is the lone holdout.

### Multi-tenant-future
V2 SaaS — same.

### Adversarial dispute (challenge yourself)
- **False positive ?** No — no `@keydown.enter` or `@keydown.esc` on any element except the proposed PROP-004 fix.
- **Real demand ?** YES per memory.
- **Scope ?** Add 2 listeners on modal root (Enter → confirm if canConfirm, Esc → reset). ~4 LOC.
- **Conflict with PROP-004 ?** PROP-004 adds `@keydown.esc="reset"` ; this would add `@keydown.enter="confirmOrder"`. They complement, not conflict. **Bundle them.**

## Proposed change

Either standalone (`+1` LOC adding `@keydown.enter`) or bundled with PROP-004 (modal root attributes).

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 12-21 (assuming PROP-004 already applied) @@
     <div
         id="orderpayment"
         class="modal pos-v4-payment-modal pos-v5-payment-modal"
         role="dialog"
         aria-modal="true"
         aria-labelledby="orderpayment-title"
         tabindex="-1"
         @keydown.esc="reset"
+        @keydown.enter.prevent="onEnterKeyConfirm"
     >
```

Plus a method :

```diff
@@ inside methods: { ... } @@
+        // [LOCK-PAY-XXX] Enter-to-confirm POS shortcut. Respects the same
+        // gates as the confirm button: refuse if loading, or if multi/card
+        // gates block. Mirrors button :disabled logic.
+        onEnterKeyConfirm(e) {
+            if (this.loading.isActive) return;
+            if (this.paymentMode === 'multi' && !this.canConfirmMulti) return;
+            if (this.paymentMode === 'card' && !this.canConfirmCard) return;
+            // Prevent Enter triggering native submit on the number input.
+            e.preventDefault();
+            this.confirmOrder();
+        },
```

Net ≈ +10 LOC (1 template attr + 9 method LOC including doc). **Borderline LOCK.**

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Cashier presses Enter on cash input | Confirm fires if gates pass | No-op |
| Cashier presses Enter on terminal dropdown | Native dropdown swallows Enter; confirm doesn't fire | No-op |
| Cashier presses Enter during loading | Guarded — no-op | No-op |
| Existing test | None unless they assert no Enter handler | None |
| Bundle rebuild | Yes | None |

## LOCK feasibility

- ~10 LOC : **borderline LOCK**, bundle with PROP-004 modal a11y LOCK for synergy.

## Owner recommendation

[ ] APPLY-WITH-LOCK (bundle with PROP-004)
[ ] DEFER-V1.0.2
[ ] DEFER-V2
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________
