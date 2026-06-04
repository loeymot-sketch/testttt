# PROPOSAL — PaymentComponent.vue:528-531 — `fetchPaymentTerminals` catch swallows all errors silently (no console.warn, no telemetry)

**ID** : PROP-PAY-014
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/admin/pos/PaymentComponent.vue`
**Frozen reason** : CLAUDE.md §7 "POS payment component, frozen per BRAIN §2 (V1 untouched protected file)"
**Existing LOCK** : plans/LOCK_PAY_PaymentComponent_currency_2026-05-23.md (D3 pending countersign — currency format)

## Finding (read-only audit)

The catch block in `fetchPaymentTerminals` at lines 528-531 :

```js
} catch (_e) {
    // Soft-fail: leave list empty so the hint banner surfaces.
    this.paymentTerminals = [];
} finally {
    this.terminalsLoading = false;
}
```

The comment explains intent — leave the list empty so the hint banner ("Aucun TPE actif sur cette filiale") surfaces. But this **conflates three distinct error conditions** :

1. **Network error** (no connectivity, server down) — should retry / surface a real error.
2. **401 unauthorized** (cashier session expired) — should trigger auth refresh, not show "no TPE" hint.
3. **403/410 backend permission gap** (cashier role lacks `payment-terminals.read`) — should escalate to admin.
4. **Genuine empty list** (200 OK, empty data) — correct path, hint applies.

All four collapse into "show 'no TPE' banner". A 401 in particular is a problem — the global axios interceptor (per CLAUDE.md §line 581-587 reference + the comment at lines 909-910 about cashier-only roles) may already trigger auto-logout on 401, but the catch here is `_e` (underscore) — error is intentionally ignored. So if the 401 fires AFTER auto-logout starts, the cashier might see the "no TPE" banner BEFORE the redirect happens, briefly.

**Telemetry gap** : production owner has no signal when this fetch fails. The comment says "soft-fail" but soft-fail without observability = silent corruption of cashier mental model.

The downstream consequence (cashier types `0007` card, presses confirm, gets 422 with no TPE attached, OR — worse — sees "no TPE configured" hint when the real error is a network blip and the admin gets a confused support call) is operational pain.

## Reasoning fort (multi-perspective)

### Chef perspective
N/A.

### Client perspective
Indirect — if cashier struggles, customer waits.

### Cashier perspective
Sees "Aucun TPE configuré — contactez l'admin" even if the real error is a transient network blip. Calls admin. Admin sees terminals configured. Confusion loop. Restart-the-modal-and-try-again resolves it after the network recovers, but cashier doesn't know to retry.

### Owner perspective
Telemetry blind spot. Cannot measure rate of network-blip false positives vs. genuine config gaps.

### Multi-tenant-future
V2 SaaS multi-network conditions — worse.

### Adversarial dispute (challenge yourself)
- **False positive ?** No — catch is verbatim `(_e)` (underscore = intentionally unused). Verified.
- **Soft-fail is intentional ?** Yes per code comment. But the comment justifies the FALLBACK behavior, not the LACK of telemetry.
- **Scope ?** Add `console.warn` for debug + emit an event to a hypothetical telemetry sink. ~3-5 LOC.

## Proposed change

### Minimum-viable (+2 LOC console.warn only)

```diff
@@ resources/js/components/admin/pos/PaymentComponent.vue line 528-531 @@
-            } catch (_e) {
+            } catch (err) {
                 // Soft-fail: leave list empty so the hint banner surfaces.
+                // [LOCK-PAY-XXX] But warn in console for ops debug — production
+                // owner needs signal vs. silent corruption.
+                if (typeof console !== 'undefined' && console.warn) {
+                    console.warn('[PaymentComponent] fetchPaymentTerminals failed:', err?.response?.status, err?.message);
+                }
                 this.paymentTerminals = [];
             } finally {
```

Net : +4 LOC. **LOCK-feasible.**

### Differentiate 401 from genuine empty list (8-10 LOC)

```diff
@@ within catch block @@
+                if (err?.response?.status === 401) {
+                    // 401 → global axios interceptor handles auth refresh; do not show no-TPE banner.
+                    return;
+                }
+                if (err?.response?.status === 403) {
+                    alertService.error(this.$t('pos.terminal_read_forbidden') || "Vous n'avez pas accès à la liste des TPE.");
+                    return;
+                }
+                if (!err?.response) {
+                    alertService.error(this.$t('error.network') || 'Erreur réseau. Réessayez.');
+                    return;
+                }
                 this.paymentTerminals = [];
```

~12 LOC. Above LOCK threshold. DEFER-V1.0.2 candidate.

## Risk analysis

| Scenario | Risk if minimum applied | Risk if NOT applied |
|----------|------------------------|---------------------|
| Happy path | Zero — only adds console.warn for non-OK case | None |
| Network blip | Cashier still sees "no TPE" but console has signal for debug | Same UX, no debug signal |
| 401 case | console.warn + axios interceptor logs out anyway | Same |
| Bundle rebuild | Yes | None |

## LOCK feasibility

- Minimum +4 LOC : **YES**, LOCK-feasible.
- Differentiated handling +12 LOC : **NO — DEFER-V1.0.2.**

## Owner recommendation

[ ] APPLY-WITH-LOCK (minimum — console.warn only)
[ ] DEFER-V1.0.2 (full differentiated 401/403/network handling)
[ ] DEFER-V2
[ ] KEEP-AS-IS (V1 single-resto, owner accepts blind spot)

**Signed-off-by-owner** : ___________  **Date** : ___________
