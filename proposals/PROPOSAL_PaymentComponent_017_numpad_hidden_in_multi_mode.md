# PROPOSAL — PaymentComponent.vue:158-168 — Numpad hidden in multi mode; cashier must use OS keyboard for tranche amounts

**ID** : PROP-PAY-017
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The numpad component renders only when `paymentMode === 'cash' || paymentMode === 'card'` (lines 158-168) :

```html
<div
    class="pos-v4-numpad pos-v5-payment-numpad-wrap mb-4"
    v-if="paymentMode === 'cash' || paymentMode === 'card'"
>
    <PosV5Numpad ... />
</div>
```

In `multi` mode, the numpad is hidden. The cashier must use the OS keyboard or tap the per-tranche `<input type="number">` inside `PosV5TrancheRow` (line 42-52). On tablet, this triggers the OS keypad (numeric per `inputmode="decimal"` at PosV5TrancheRow:46, good).

**Question** : is the numpad omission deliberate ? Yes, technically — the numpad writes to `this.inputIdName` (cashInput/cardInput) which are non-existent in multi mode. The per-tranche row inputs have their own ids (`tranche-amount-N`) and emit `update` events. The numpad would need new wiring to target the active tranche.

**Operational impact** : in multi-tender, cashiers use either keyboard (desktop) or OS keypad (tablet). The numpad's value (large tactile buttons, no OS keypad latency) is lost in multi mode.

This is a **design choice / scope** concern, not a bug. The decision to hide the numpad in multi mode is justified (numpad target is undefined), but it's a UX regression vs. single-tender mode.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
N/A.

### Cashier perspective
Multi-tender is the slowest cash flow already (multiple tranches, each requires amount + maybe tendered). Losing the numpad makes it slower.

### Owner perspective
Multi-tender is the highest-friction transaction type. Adding numpad support there would noticeably improve speed.

### Multi-tenant-future
V2 SaaS — same.

### Adversarial dispute (challenge yourself)
- **False positive ?** No — verified `v-if="paymentMode === 'cash' || paymentMode === 'card'"` on line 160.
- **Refactor scope ?** Need to (a) keep numpad visible in multi mode + (b) route numpad clicks to the currently-focused tranche input. Requires tracking "active tranche" or "active input" state. ~30+ LOC plus PosV5Numpad wiring. **Architectural — DEFER-V1.0.2.**

## Proposed change

### Option A : keep numpad hidden in multi (status quo, EXPLAIN with comment) — 0 LOC

Just acknowledge the design choice with a code comment. No change.

### Option B : show numpad in multi, route to "active tranche" — DEFER-V1.0.2

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 158-168 @@
                 <div
                     class="pos-v4-numpad pos-v5-payment-numpad-wrap mb-4"
-                    v-if="paymentMode === 'cash' || paymentMode === 'card'"
+                    v-if="paymentMode === 'cash' || paymentMode === 'card' || (paymentMode === 'multi' && activeTrancheIdx !== null)"
                 >
                     <PosV5Numpad
                         aria-label="Pavé numérique"
                         @input="numpadInput"
                         @back="numpadBack"
                         @clear="numpadClear"
                     />
                 </div>
```

Plus data: `activeTrancheIdx: null` ; plus a `@focus`/`@blur` handler on each tranche input (in PosV5TrancheRow — separately frozen, requires its own LOCK).

**Cross-frozen-file scope** : touches PosV5TrancheRow.vue (also §7 frozen). Two LOCKs needed.

## Risk analysis

| Scenario | Risk if Option A | Risk if Option B applied | Risk if NOT applied |
|----------|------------------|-------------------------|---------------------|
| Multi-tender speed | Unchanged (slow) | Faster | Slow |
| Refactor cost | Zero | High (cross-frozen-file) | None |
| Bug introduction | Zero | Possible | None |
| Bundle rebuild | None | Yes | None |

## LOCK feasibility

- Option A : **No fix needed.**
- Option B : **NO — architectural, cross-frozen-file. DEFER-V1.0.2.**

## Owner recommendation

[ ] APPLY-WITH-LOCK (Option B — too invasive, NOT recommended)
[ ] DEFER-V1.0.2 (Option B)
[ ] DEFER-V2
[ ] KEEP-AS-IS (Option A — accept slower multi-tender)

**Signed-off-by-owner** : ___________  **Date** : ___________
