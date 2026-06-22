# PROPOSAL — PaymentComponent.vue:307-319 — Disabled confirm button has no aria-live explanation of WHY

**ID** : PROP-PAY-012
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The confirm CTA at lines 307-319 :

```html
<button
    @click="confirmOrder"
    type="button"
    :disabled="loading.isActive || (paymentMode === 'multi' && !canConfirmMulti) || (paymentMode === 'card' && !canConfirmCard)"
    :aria-busy="loading.isActive"
    :aria-disabled="loading.isActive || (paymentMode === 'multi' && !canConfirmMulti) || (paymentMode === 'card' && !canConfirmCard)"
    class="pos-v4-confirm-button pos-v5-payment-confirm w-full"
    data-testid="pos-payment-confirm"
>
    <span aria-hidden="true">✓</span>
    {{ $t('button.confirm_and_print') }}
</button>
```

The button toggles `disabled`/`aria-disabled` correctly. But there is **no explanation in the DOM** of *why* the button is disabled when the cashier sees it greyed-out :
- Multi-mode unbalanced ? The summary row "Reste dû" (line 195-207) shows the remaining amount — visually informative, but not programmatically associated with the button (`aria-describedby`).
- Card mode without selected terminal ? The dropdown placeholder shows "Sélectionnez un TPE" — but again, not linked to the button via `aria-describedby`.

Screen-reader cashier hears "button confirm and print, dimmed" — no idea what to fix. They must navigate to find the summary or dropdown to discover why.

The `pos-v5-payment-input-hint` paragraph at line 137-139 surfaces only when `paymentTerminals.length === 0` ; it has `role="alert"`. Different state (empty list vs. unselected). Good for that specific case but not for the broader "why is the button dimmed".

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
N/A.

### Cashier perspective
Sighted cashier : the visual cue (button greyed) + adjacent summary row "Reste dû 17,70 €" is enough — they know to add another tranche. AT cashier : misses the visual link. Stuck.

### Owner perspective
WCAG 4.1.3 Status Messages — disabled state with a reason should be announced. Not full A but recommended.

### Multi-tenant-future
V2 SaaS multi-locale — same.

### Adversarial dispute (challenge yourself)
- **False positive ?** No — explicit lack of `aria-describedby`. Verified.
- **Existing role=status on summary row** (line 195) — `aria-live="polite"` exists on the remaining-due row. So when the value updates, AT speaks "Reste dû 17.70 €". Good. But it's NOT triggered by trying to click the disabled confirm button.
- **Could a tooltip suffice ?** A `title` attribute on the button would surface on hover for sighted users but not for AT.
- **Scope ?** Add `:aria-describedby` to the confirm button that toggles between "remaining-due-row-id" and "no-terminal-hint-id" depending on which gate is blocking. ~4-6 LOC.

## Proposed change

### Minimum-viable (≤6 LOC)

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 308-319 @@
                 <button
                     @click="confirmOrder"
                     type="button"
                     :disabled="loading.isActive || (paymentMode === 'multi' && !canConfirmMulti) || (paymentMode === 'card' && !canConfirmCard)"
                     :aria-busy="loading.isActive"
                     :aria-disabled="loading.isActive || (paymentMode === 'multi' && !canConfirmMulti) || (paymentMode === 'card' && !canConfirmCard)"
+                    :aria-describedby="confirmDescribedById"
                     class="pos-v4-confirm-button pos-v5-payment-confirm w-full"
                     data-testid="pos-payment-confirm"
                 >
```

Plus a computed property :

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue inside computed: { ... } @@
+        confirmDescribedById() {
+            if (this.paymentMode === 'multi' && !this.canConfirmMulti) return 'pos-payment-remaining-due-row';
+            if (this.paymentMode === 'card' && !this.canConfirmCard) return this.paymentTerminals.length === 0 ? null : null;
+            return null;
+        },
```

Need to give the remaining-due row an id matching :

```diff
@@ line 192-197 @@
                         <div
+                            id="pos-payment-remaining-due-row"
                             class="pos-v5-split-summary__row pos-v5-split-summary__row--remaining"
                             :class="{ 'pos-v5-split-summary__row--ok': remainingDueEur <= 0.01 }"
                             role="status"
                             aria-live="polite"
                             data-testid="pos-payment-remaining-due-row"
                         >
```

Net ≈ 10 LOC (1 prop + 5-line computed + 1 id attribute). **Borderline LOCK.**

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Sighted cashier | Zero | Zero |
| AT cashier | Announces blocking reason on disabled button | Stuck |
| Existing tests | None | None |
| Bundle rebuild | Yes | None |

## LOCK feasibility

- ~10 LOC : **borderline LOCK.** Could be bundled with PROP-004/005 a11y LOCK.

## Owner recommendation

[ ] APPLY-WITH-LOCK (bundle with PROP-004 / PROP-005 a11y LOCK)
[ ] DEFER-V1.0.2
[ ] DEFER-V2
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________
