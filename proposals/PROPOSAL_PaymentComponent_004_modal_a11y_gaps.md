# PROPOSAL — PaymentComponent.vue:12 — Modal lacks `role="dialog"`, `aria-modal`, focus trap, Escape handler

**ID** : PROP-PAY-004
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The modal root at `resources/js/components/admin/pos/PaymentComponent.vue:12-13` :

```html
<div id="orderpayment" class="modal pos-v4-payment-modal pos-v5-payment-modal">
    <div class="modal-dialog pos-v4-payment-dialog pos-v5-payment-dialog max-w-[480px] w-full">
```

Missing accessibility attributes :
- `role="dialog"` — assistive tech does not announce the element as a modal dialog.
- `aria-modal="true"` — screen readers do not know background content is inert.
- `aria-labelledby="…heading id…"` — no programmatic association between dialog and its h3 (line 15).
- No focus-trap logic — tabbing inside the dialog can move focus to the page body behind the modal.
- No `Escape` key handler — owner mandate POS speed expects keyboard close.
- No focus restoration on close — when `reset()` (line 573) closes the modal via `appService.modalHide`, the previously-focused element is not restored.

WCAG 2.1 compliance hits :
- 4.1.2 Name, Role, Value (Level A) — modal role missing.
- 2.4.3 Focus Order (Level A) — focus is not constrained to the modal.
- 2.1.1 Keyboard (Level A) — no Escape close.
- 2.4.7 Focus Visible (Level AA) — present in style (focus-visible blocks lines 1296-1300, 1403-1407, 1427-1430), so this one is OK.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
For a client-facing POS context (very rare in this V1 — POS is cashier-only), the modal a11y matters less. Indirect risk only.

### Cashier perspective
A cashier using keyboard-only navigation (some accessibility setups, some restaurants serve cashiers with impairments) cannot escape the modal by Esc — they must Tab to the ✕ button. Under rush, this slows down. Owner Q11 (cashier shortcuts) has been mentioned (per memory) — Escape is a fundamental shortcut.

### Owner perspective
French law (Loi Handicap 2005 + RGAA 4.1) requires accessibility on commerce-facing tools at certain thresholds. POS is commerce-facing. V1 single-resto might not legally cross those thresholds, but V2 SaaS at scale will. Better to fix early.

### Multi-tenant-future
V2 SaaS deploys to dozens of restaurants. Some may have legal/grant requirements for RGAA compliance. The modal pattern propagates to every tenant. Fixing the pattern once = automatic compliance everywhere.

### Adversarial dispute (challenge yourself)
- **False positive ?** Verified : line 12 has no `role`, `aria-modal`, `aria-labelledby`. No `@keydown.esc` listener on the modal root. No `focus-trap-vue` import. **NOT a false positive.**
- **`appService.modalHide` handles a11y ?** Investigated : `appService.modalShow/Hide` are simple display:none toggles via vanilla JS. They do NOT manage focus or aria-hidden of background.
- **Does `aria-label="..."` on h3 (line 18, the close button) substitute ?** No — close button aria-label only names the button, not the dialog.
- **Scope of fix ?** 8-15 LOC change : add 3 attributes to root div + add `@keydown.esc="reset"` to root + add `:tabindex="-1"` for focus + mount/unmount focus-trap logic. ALSO requires JS lifecycle hooks to restore focus on close. Total ~30-40 LOC including the focus-trap helper. **Borderline LOCK feasibility.**
- **Could be deferred ?** YES — V1 single-resto in commerce environment, not a public-facing kiosk-front a11y emergency. The kiosk (KioskWizardComponent) is the more critical a11y surface and is separately frozen + audited.

## Proposed change

### Minimum-viable patch (≤8 LOC, LOCK-feasible)

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 12-21 @@
-    <div id="orderpayment" class="modal pos-v4-payment-modal pos-v5-payment-modal">
+    <div
+        id="orderpayment"
+        class="modal pos-v4-payment-modal pos-v5-payment-modal"
+        role="dialog"
+        aria-modal="true"
+        aria-labelledby="orderpayment-title"
+        tabindex="-1"
+        @keydown.esc="reset"
+    >
         <div class="modal-dialog pos-v4-payment-dialog pos-v5-payment-dialog max-w-[480px] w-full">
             <div class="modal-header pos-v4-payment-header pos-v5-payment-header pb-3 border-b">
-                <h3 class="capitalize font-extrabold text-[var(--pos-v5-text-h5)] text-[var(--pos-v5-ink)] m-0">
+                <h3 id="orderpayment-title" class="capitalize font-extrabold text-[var(--pos-v5-text-h5)] text-[var(--pos-v5-ink)] m-0">
                     💳 {{ $t('label.order_payment') }}
                 </h3>
```

Net : +6 LOC, -1 LOC = +5 LOC. **Borderline LOCK.**

### Full-fledged patch (focus trap + restore — 30+ LOC, DEFER-V1.0.2)

Adds :
- Focus-trap (custom or via `focus-trap-vue` package).
- `previouslyFocused` tracking on modal open.
- Focus restoration on `reset()`.
- `aria-hidden="true"` on background app shell while modal open.

Requires `<script>` lifecycle additions in `mounted` / `beforeUnmount` AND likely a new dependency or hand-rolled trap. **Architectural-ish change** — flag as V1.0.2.

## Risk analysis

| Scenario | Risk if minimum-viable applied | Risk if NOT applied |
|----------|--------------------------------|---------------------|
| Sighted cashier flow | Zero — attributes are inert for visual users; Escape close is additive | Slight friction — no Esc close, no focus trap |
| Screen-reader cashier flow | POSITIVE — dialog now announced; Esc closes; aria-labelledby tells AT what the dialog is | Modal not announced as such; user navigates to background by tab |
| Existing PaymentComponent.spec.js | Possibly — if a spec queries by absence of `role="dialog"` (unlikely). Likely no impact. | None |
| Bundle rebuild | Yes, mandatory. | None |
| Frozen-zone diff | +6 LOC in template only. Reversible. | None |
| RGAA / WCAG compliance | Improves toward AA. Does NOT fully meet 2.4.3 (still no focus trap). | Remains non-compliant. |

## LOCK feasibility

- Minimum-viable patch (≤5 LOC) : **YES, LOCK-feasible.**
- Full focus-trap patch (30+ LOC, new lifecycle hooks) : **NO — architectural-ish, DEFER-V1.0.2.**

## Owner recommendation

[ ] APPLY-WITH-LOCK (minimum-viable: role+aria-modal+labelledby+@keydown.esc, +5 LOC)
[ ] DEFER-V1.0.2 (full focus-trap + focus restoration)
[ ] DEFER-V2 (await SaaS scale RGAA mandate)
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________
