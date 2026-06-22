# PROPOSAL — PaymentComponent.vue:887-891 — `openDrawer()` hardware call silently swallowed; no telemetry on hardware fault

**ID** : PROP-PAY-015
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

After a successful CASH order at lines 882-891 :

```js
handleOrderSuccess: async function (orderResponse, submittedForm) {
    if (submittedForm.pos_payment_method === this.posPaymentMethodEnum.CASH) {
        try {
            Promise.resolve(openDrawer()).catch(() => {});
        } catch (e) { /* defensive: never block the receipt path */ }
    }
    ...
```

The cash-drawer hardware bridge is called. Errors are double-swallowed (sync try/catch + async .catch). The comment explains intent : never block the receipt path. **Correct discipline** (drawer fault must not prevent receipt rendering, which is the NF525-mandatory artifact).

BUT : neither the sync catch nor the async catch logs anything. If the hardware bridge ALWAYS fails (e.g. POS deployed without drawer bridge but production-mode mandate POS_SIMULATION_HARDWARE=false), the drawer never opens for any CASH order — and there is NO signal to the owner / no observability.

NF525 compliance angle (per CLAUDE.md §8) :
- NF525 mandates a cash-trail (the audit_log chain).
- The drawer is HARDWARE — its operation is operational, not fiscal. NF525 does NOT require drawer to open programmatically; the receipt is the SSOT.
- **So silent fail of drawer ≠ NF525 violation.** Operational only.

Production boot guard `POS_SIMULATION_HARDWARE=false` (per `AppServiceProvider.php:78-145`) protects against a production deploy without drawer hardware. So if this is false in prod, the drawer SHOULD work. But hardware faults (drawer jammed, USB unplugged, kioskHardware.js bridge crashed) can still surface — and the cashier should know.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
N/A — drawer is for the cashier.

### Cashier perspective
Cashier expects the drawer to open on every CASH order. If it doesn't, they manually pull it open. If it doesn't open for 10 consecutive orders, they call admin. Admin sees no error logs. Hours wasted.

### Owner perspective
Same as cashier. Owner wants signal when hardware fails.

### Multi-tenant-future
V2 SaaS — multiplied by tenant count, makes telemetry even more important.

### Adversarial dispute (challenge yourself)
- **False positive ?** Verified : lines 887-891. Two layers of silent catch.
- **Should it block ?** NO — the comment is correct ("never block the receipt path"). NF525 receipt SSOT must render.
- **Add telemetry without blocking ?** Yes, just `console.warn` or push to a telemetry sink. ~2 LOC.

## Proposed change

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 882-891 @@
         handleOrderSuccess: async function (orderResponse, submittedForm) {
             if (submittedForm.pos_payment_method === this.posPaymentMethodEnum.CASH) {
                 try {
-                    Promise.resolve(openDrawer()).catch(() => {});
+                    Promise.resolve(openDrawer()).catch((err) => {
+                        if (typeof console !== 'undefined' && console.warn) {
+                            console.warn('[PaymentComponent] openDrawer async failure:', err?.message || err);
+                        }
+                    });
-                } catch (e) { /* defensive: never block the receipt path */ }
+                } catch (e) {
+                    if (typeof console !== 'undefined' && console.warn) {
+                        console.warn('[PaymentComponent] openDrawer sync failure:', e?.message || e);
+                    }
+                }
             }
```

Net ≈ +8 LOC. **Borderline LOCK** (>5).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Drawer works | Zero — catch only fires on error | Same |
| Drawer hardware fault | console.warn surfaces in ops, owner can investigate | Silent failure, no signal |
| NF525 audit | None — receipt path still runs unblocked | None |
| Existing test | None | None |
| Bundle rebuild | Yes | None |

## LOCK feasibility

- +8 LOC : **borderline.** Could be bundled with PROP-014 as a "telemetry LOCK" cluster.

## Owner recommendation

[ ] APPLY-WITH-LOCK (bundle with PROP-014 "telemetry LOCK")
[ ] DEFER-V1.0.2
[ ] DEFER-V2
[ ] KEEP-AS-IS (silent fail is intentional)

**Signed-off-by-owner** : ___________  **Date** : ___________
