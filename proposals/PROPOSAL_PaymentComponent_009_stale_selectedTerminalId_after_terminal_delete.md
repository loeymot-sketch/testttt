# PROPOSAL — PaymentComponent.vue:525-527 — `selectedTerminalId` never re-validated against fresh terminal list; deleted-terminal stays selected and 422s at confirm

**ID** : PROP-PAY-009
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The `fetchPaymentTerminals` method at lines 509-534, specifically the default-pick block at 525-527 :

```js
if (this.selectedTerminalId === null && this.paymentTerminals.length > 0) {
    this.selectedTerminalId = this.paymentTerminals[0].id;
}
```

The condition `this.selectedTerminalId === null` only auto-picks if currently null. **Once set, it is never invalidated**, even if a subsequent fetch returns a list that no longer contains the selected terminal (admin deleted it, terminal moved to inactive, branch changed, etc.).

Flow that breaks :
1. Cashier opens payment modal → fetchPaymentTerminals → sets `selectedTerminalId = 7`.
2. Admin deletes terminal id=7 in another tab (or marks it `status=0`).
3. Cashier closes modal and re-opens → fetchPaymentTerminals → list now `[{id:8, ...}]`. `selectedTerminalId` is still `7`. The `===null` guard does NOT fire (id 7 is set), so id 8 is NOT auto-picked.
4. The dropdown (line 121-136) has `v-model="selectedTerminalId"` bound to `7`, but no `<option>` exists with value `7`. The native `<select>` shows the placeholder disabled option as selected (Vue + native select behavior). Visually : "Sélectionnez un TPE" (disabled).
5. **BUT** : `canConfirmCard` (line 449-452) only checks `Number(selectedTerminalId) > 0` — `7 > 0` is true. Confirm button is enabled.
6. Cashier clicks confirm → backend `PosOrderRequest` rejects with 422 ("terminal id invalid" / "terminal_id required_if pos_payment_method=CARD").

Cashier sees a generic 422 error toast. Confused about cause.

Additional concern: `this.selectedTerminalId` is **never reset to null on `reset()` (line 573-590)**. So between two transactions on the same modal, a stale ID can carry over. The reset method clears tranches, splitCount, paymentMode — but leaves selectedTerminalId AND paymentTerminals.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
Cashier struggles with the confirm; customer waits; transaction stalls.

### Cashier perspective
Direct friction. Cashier sees the dropdown showing "Sélectionnez un TPE" but the confirm button is green/enabled. They click → 422 error toast. They have to manually re-pick from dropdown. Confused.

### Owner perspective
Operational bug. Edge case (admin deleting terminals mid-session), but Le Cayenne has 1 TPE and the rate of this scenario is low. Still — when it happens, it's a clear "WAT" moment.

### Multi-tenant-future
V2 SaaS will have more terminals per branch, more admin churn, more occurrences. Risk scales with tenant count.

### Adversarial dispute (challenge yourself)
- **False positive ?** Verified : line 525-527 `=== null` guard. Verified `reset()` line 573-590 does not clear `selectedTerminalId`. **NOT a false positive.**
- **Real-world probability ?** LOW for Le Cayenne single-resto single-TPE. Higher for V2 SaaS.
- **Scope ?** Two surgical fixes :
  1. Re-validate `selectedTerminalId` after fetch — if not in returned list, reset to null then auto-pick first.
  2. Reset `selectedTerminalId` and `paymentTerminals` in `reset()`.
- **LOC ?** ~5-8 LOC.

## Proposed change

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 518-534 @@
                 this.paymentTerminals = list
                     .filter((t) => t && (t.status === 1 || t.status === '1'))
                     .map((t) => ({
                         id: Number(t.id),
                         name: String(t.name || ''),
                         gateway_type: String(t.gateway_type || ''),
                     }));
-                if (this.selectedTerminalId === null && this.paymentTerminals.length > 0) {
+                // [LOCK-PAY-XXX] Re-validate previous selection against the
+                // refreshed list. If the previously-selected terminal was
+                // deleted/deactivated, reset to null then auto-pick first.
+                const stillValid = this.selectedTerminalId !== null
+                    && this.paymentTerminals.some((t) => t.id === this.selectedTerminalId);
+                if (!stillValid) {
+                    this.selectedTerminalId = null;
+                }
+                if (this.selectedTerminalId === null && this.paymentTerminals.length > 0) {
                     this.selectedTerminalId = this.paymentTerminals[0].id;
                 }
```

And reset on close :

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 573-590 (reset method) @@
         reset: function () {
             this.resetPaymentInputs();
             // [CV1-POS-SPLIT-PAYMENT-001] Reset multi-tender local state on modal close.
             this.tranches = [];
             this.splitCount = 2;
             this.paymentMode = 'cash';
+            // [LOCK-PAY-XXX] Reset terminal selection so next open re-fetches fresh.
+            this.selectedTerminalId = null;
+            this.paymentTerminals = [];
             this.emitPaymentFormPatch({ pos_payment_note: "" });
```

Total net : ~10 LOC (8 added in `fetchPaymentTerminals`, 2 added in `reset`). **Borderline LOCK** (>5 but well under any reasonable threshold for a defensive fix).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Single-resto Le Cayenne, 1 TPE never deleted | None — `stillValid` is true, behavior unchanged | Same |
| Admin deletes selected terminal mid-session | Cashier auto-routed to first remaining or empty (null) → hint banner surfaces | 422 at confirm, friction |
| Cross-modal-open carry-over | None — reset wipes state | Stale ID + stale list lingers |
| Existing test on terminal selector | No semantic change for the happy path | None |
| Bundle rebuild | Yes | None |

## LOCK feasibility

- ~10 LOC across two methods : **borderline-YES** if owner accepts. Defensive cleanup only, no behavioral change to the happy path.

## Owner recommendation

[ ] APPLY-WITH-LOCK
[ ] DEFER-V1.0.2 (single-TPE Le Cayenne low-risk)
[ ] DEFER-V2
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________
