# PROPOSAL — PaymentComponent.vue:411-415 — `cashChange` uses float math while split-payment path uses integer cents

**ID** : PROP-PAY-007
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The single-tender CASH change-due computation at lines 411-415 uses float arithmetic :

```js
cashChange: function () {
    const received = parseFloat(this.cashReceivedRaw) || 0;
    const total = parseFloat(this.props?.form?.total) || 0;
    return received > total ? Math.round((received - total) * 100) / 100 : 0;
},
```

`parseFloat()` × subtraction × `Math.round * 100 / 100` is the classic IEEE-754 hazard. For typical fast-food amounts (< 100€) the rounding step *usually* lands on the right cent — but edge cases like `0.1 + 0.2 = 0.30000000000000004` can flip a cent under certain `total` values.

Meanwhile, the split-payment path (lines 419-444) uses integer-cents throughout via the `splitToCents` / `splitFromCents` / `splitRemainingCents` / `totalChangeCents` helpers imported from `resources/js/helpers/posSplitPayment`. The split path is robust against float drift by design.

**The inconsistency is the proposal target** — the cash path should mirror the split path's cents-based approach for arithmetic consistency. Owner audited iter12+ to harden the split path; the single-tender cash path was left in legacy float form.

**Concrete drift scenario** : `total = 4.10`, `received = 5.00`. `5.00 - 4.10 = 0.8999999999999999` (IEEE-754). `* 100 = 89.99999999999999`. `Math.round = 90`. `/ 100 = 0.9`. **Final** : `0.9`. The numerator landed correctly because of `Math.round`. But this is a *safety margin* — not all input pairs round correctly. Worse, the displayed value passes through `currencyFormat` (line 535-537 → appService) which then applies `parseFloat().toFixed(2)`. Two float coercions stacked.

The fiscal audit chain hash binds the order's `total`, not `cashChange`. So this drift never reaches the audit chain. But the **receipt printed amount** for change-due is the visible artifact, and a single mis-displayed cent triggers customer complaint.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
If the cash drawer dispenses €0.90 but the receipt says €0.91 or €0.89, customer notices. Trust hit. Rare but possible per IEEE-754 hazard chart.

### Cashier perspective
Reconciliation rare-case : receipt says 0.91€, drawer counted 0.90€. End-of-day discrepancy. Cashier has to investigate.

### Owner perspective
Float drift is the most-documented foot-gun in commerce software. The codebase already has the cents-based helper (`posSplitPayment.js`). Owner's iter12 hardened the split path. The single-tender path is the lone holdout.

### Multi-tenant-future
Multi-tenant compounds the risk : more transactions, more chances to hit the hazard.

### Adversarial dispute (challenge yourself)
- **Hazard real or theoretical ?** Real per IEEE-754 spec but `Math.round * 100 / 100` is *usually* defensive enough. Production frequency unknown — owner would need to enable a log on cashChange drift to measure.
- **Cost-of-fix vs cost-of-bug ?** Fix is 5-10 LOC method body using `splitToCents` / `splitFromCents`. Bug rate likely <0.1% of transactions. Modest cost, modest benefit.
- **Scope ?** Refactor `cashChange` to use cents-based arithmetic. 8-10 LOC body change. Borderline LOCK.
- **Risk of fix ?** LOW — same input/output type (number), same semantic, just safer arithmetic.

## Proposed change

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 411-415 @@
-        cashChange: function () {
-            const received = parseFloat(this.cashReceivedRaw) || 0;
-            const total = parseFloat(this.props?.form?.total) || 0;
-            return received > total ? Math.round((received - total) * 100) / 100 : 0;
-        },
+        // [LOCK-PAY-XXX 2026-05-23] Cents-based arithmetic for change-due,
+        // mirroring the split-payment helper pattern (posSplitPayment.js).
+        // Was: parseFloat() × subtraction × Math.round*100/100 (IEEE-754 hazard).
+        // Now: splitToCents() × integer subtraction × splitFromCents() (zero drift).
+        cashChange: function () {
+            const receivedCents = splitToCents(this.cashReceivedRaw || 0);
+            const totalCents = splitToCents(this.props?.form?.total || 0);
+            const diffCents = receivedCents - totalCents;
+            return diffCents > 0 ? splitFromCents(diffCents) : 0;
+        },
```

`splitToCents` + `splitFromCents` already imported at line 351-352 of the script. No new import.

Net : -5 LOC, +9 LOC = +4 LOC. Within LOCK threshold.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Typical fast-food amount (total < 100€) | Zero — same result | Edge-case IEEE drift |
| Reconciliation discrepancy | Eliminated | Rare but possible |
| Architecture consistency | Improved (cash + split both use cents) | Inconsistent |
| Test coverage | Need to verify `cashChange` test (if any) still passes — semantically identical for typical values | None |
| Bundle rebuild | Yes | None |

## LOCK feasibility

- +4 LOC : **YES**, LOCK-feasible.
- Pure arithmetic refactor, same I/O contract.

## Owner recommendation

[ ] APPLY-WITH-LOCK
[ ] DEFER-V1.0.2 (rare hazard, current Math.round is defensive)
[ ] DEFER-V2
[ ] KEEP-AS-IS

**Signed-off-by-owner** : ___________  **Date** : ___________
