# PROPOSAL — PaymentComponent.vue:232-242 — `splitCountInput` missing `inputmode="numeric"` for tablet UX

**ID** : PROP-PAY-013
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The split-count input at lines 232-242 :

```html
<input
    id="splitCountInput"
    type="number"
    min="2"
    max="20"
    step="1"
    v-model.number="splitCount"
    class="pos-v5-split-divider__input pos-v5-tabular"
    :aria-label="$t('label.split_among_n') || 'Diviser entre N personnes'"
    data-testid="pos-payment-split-count"
/>
```

`type="number"` with `step="1"` is mostly OK (no decimals expected), but lacks `inputmode="numeric"` and `pattern="[0-9]*"`. On older iPad Safari (< iOS 13) and some Android keyboards, `type="number"` alone does not consistently surface the numeric keypad — `inputmode="numeric"` is the canonical hint.

Also, the same leading-zero strip concern as PROP-002 applies to `type="number"`, but here the values are 2-20 (no leading zeros expected). Less risk.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
N/A.

### Cashier perspective
On tablet, may see alphanumeric keyboard pop up. Minor friction.

### Owner perspective
Polish item. Low priority.

### Multi-tenant-future
Same.

### Adversarial dispute
- **False positive ?** Verified — no `inputmode`. Confirmed.
- **Severity ?** Trivial UX-grade.

## Proposed change

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 232-242 @@
                             <input
                                 id="splitCountInput"
                                 type="number"
+                                inputmode="numeric"
                                 min="2"
                                 max="20"
                                 step="1"
                                 v-model.number="splitCount"
                                 ...
```

+1 LOC.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Tablet | Numeric keypad shown | Sometimes generic |
| Desktop | No effect | None |

## LOCK feasibility

- +1 LOC : **YES.** Trivially LOCK-feasible.
- Bundle with PROP-006 (cashInput inputmode) as a single "input ergonomics" LOCK.

## Owner recommendation

[ ] APPLY-WITH-LOCK (bundle with PROP-006)
[ ] DEFER-V1.0.2
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________
