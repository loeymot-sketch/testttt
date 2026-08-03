# PROPOSAL — PaymentComponent.vue:87-93 — `cashInput` is `type="text"` without `inputmode="decimal"` — tablet UX

**ID** : PROP-PAY-006
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The cash-received input at lines 86-93 :

```html
<input
    id="cashInput"
    ref="cashInput"
    type="text"
    v-on:keypress="floatNumber($event)"
    @input="onCashInput"
    class="pos-v5-payment-input pos-v5-tabular"
/>
```

`type="text"` is correct (it preserves decimal entries verbatim, `floatNumber` validates input on `keypress`). However, it lacks :
- `inputmode="decimal"` — on mobile/tablet, this attribute tells the OS to show a decimal-pad keyboard (numbers + decimal separator) instead of the generic text keyboard.
- `autocomplete="off"` — browsers may suggest history values for "cash received" which is meaningless and confusing.
- `name="cash_received"` — currently nameless; some screen readers and form auto-handlers benefit from `name`.
- The `v-on:keypress="floatNumber($event)"` is legacy syntax — modern `@keypress="floatNumber"` is equivalent but cleaner.

This is a UX-grade concern, not a functional bug. The cashier always uses the on-screen `PosV5Numpad` (line 162-167) since the V5 design refactor — so the OS keyboard rarely appears for cash input in production. **Lower priority** than the `cardInput type=number` (PROP-002) which is a real data-loss bug.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
N/A — cashier-only input.

### Cashier perspective
On a tablet POS (iPad/Android), if the cashier taps the cash input directly (bypasses the numpad), the OS keyboard is the generic alphanumeric keyboard. They have to switch to the numeric layout manually. Friction.

Once they tap the numpad component (line 162-167) instead, the OS keyboard hides and the on-screen numpad is used — no problem.

### Owner perspective
Minor polish item. Mostly invisible because numpad is the primary input method.

### Multi-tenant-future
Same pattern V2 — fix once, benefits all tenants.

### Adversarial dispute (challenge yourself)
- **False positive ?** Verified line 89 : `type="text"` no `inputmode`. NOT a false positive.
- **Could be irrelevant ?** YES if cashiers ALWAYS use numpad. Production observation needed to gauge real impact.
- **Scope ?** 1 attribute. ≤2 LOC.

## Proposed change

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 86-93 @@
                         <input
                             id="cashInput"
                             ref="cashInput"
                             type="text"
+                            inputmode="decimal"
+                            autocomplete="off"
                             v-on:keypress="floatNumber($event)"
                             @input="onCashInput"
                             class="pos-v5-payment-input pos-v5-tabular"
                         />
```

+2 LOC.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Tablet cashier tapping input directly | Better keypad surfaced | Generic keyboard, manual switch |
| Numpad-only usage | Zero change | Zero |
| Existing `floatNumber` validation | Unchanged — keypress validation still fires | None |
| Bundle rebuild | Required | None |

## LOCK feasibility

- ≤2 LOC : **YES**, easily LOCK-feasible.

## Owner recommendation

[ ] APPLY-WITH-LOCK
[ ] DEFER-V1.0.2
[ ] DEFER-V2
[ ] KEEP-AS-IS (numpad is primary, OS keypad rarely seen)

**Signed-off-by-owner** : ___________  **Date** : ___________
