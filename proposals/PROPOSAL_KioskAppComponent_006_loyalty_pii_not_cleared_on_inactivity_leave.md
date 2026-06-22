# PROPOSAL — KioskAppComponent.vue — `onInactivityLeave` only clears customerProfile — loyalty PII in cart may persist across sessions

**ID** : PROP-KioskAppComponent-006
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component. Additionally, this touches a **PII boundary** — same caution as §12 DATA_CONTRACT invariant (referenced in the inline comment at L925).
**Existing LOCK** : none.

## Finding (read-only audit)

`onInactivityLeave()` at L928–933 handles the "Abandonner" CTA (or countdown timeout) by:

1. `this.showStillHere = false`
2. `kioskAnalytics.track('idle_dismissed', { trigger: 'overlay' })`
3. `this.$store.dispatch('kioskSettings/clearCustomerProfile')`
4. `this.resetKiosk()`

`resetKiosk()` at L964–970 calls `this.reset()` (mapped from `kioskCart` Vuex module at L439) which presumably resets the cart, plus `kioskAnalytics.resetSession()`, plus router push to idle.

The inline comment at L925 references "§12 DATA_CONTRACT (pas de PII persistée après un abandon)" — but this comment makes a *claim* the code may not fully honor:

- `clearCustomerProfile` clears the `kioskSettings.customerProfile` slice — but what about loyalty data, phone number, email address, name attached to the cart line in `kioskCart` itself? If a customer typed their phone number on the `kiosk.loyalty` screen and the value was committed to `kioskCart.loyaltyPhone` (or similar), `this.reset()` should clear it, **but the contract is implicit and untested at this layer**.
- Stripe / payment intent IDs may also be retained in store state.
- `localStorage` keys other than `foodking:kiosk-theme` (L445, L464) are not enumerated — there could be ad-hoc keys (e.g. `kiosk-last-loyalty-phone` for autocomplete) that survive the reset.

There is no defensive sweep, no explicit list of "PII-bearing store paths to clear on abandon", and no test that asserts post-abandon state is PII-free.

### Personas impacted
- **Client (next customer)** (HIGH — privacy violation if the next customer at the borne sees the previous customer's phone number autocompleted in the loyalty field).
- **Privacy / DPO / GDPR audit** (CRITICAL at V2 SaaS — single-customer-borne leakage is a documented GDPR finding).
- **Owner (legal exposure)** (CRITICAL — France CNIL is increasingly active on kiosk PII).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
Privacy-conscious customer who sees their phone number lingering after abandon will not trust the borne. Privacy-conscious *next* customer who sees the previous customer's data is a worse failure.

### Cashier perspective
She receives the GDPR complaint.

### Owner perspective
At V1 single-customer-at-a-time, the practical leak probability is bounded by abandoned-then-next-customer-within-T sequence. At Le Cayenne with sub-minute idle reset, the window is small. But the *audit risk* (CNIL inspection, screenshot of borne with leftover phone field) is large and disproportionate to fix cost.

### Multi-tenant-future
V2 SaaS multi-borne high-volume = much higher exposure. The defensive sweep should ship in V1 so it's not retrofitted under deadline pressure.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — if `kioskCart.reset()` already exhaustively clears all PII-bearing fields (loyaltyPhone, customerEmail, etc.), the gap is illusory. **I did not inspect the `kioskCart` Vuex module.** This is the single most important check before applying.
- **Scope-minimal possible?** Yes — add a single `clearAllSensitiveState` action that vacuums known PII paths.
- **`localStorage` audit?** Separately needed — beyond this file's scope, but worth a quick `grep localStorage` on the codebase. Within *this* file, only `foodking:kiosk-theme` is touched (line 445, 464); the theme key is non-PII.
- **Goal cares?** Yes — `feedback_v1_dine_in_disabled_2026-05-06` and the broader V1 production-readiness mandate (`project_goal_production_readiness_2026-05-18`) implicitly demand GDPR compliance for V1 Le Cayenne French market.

## Proposed change

```diff
     onInactivityLeave() {
       this.showStillHere = false;
       try { kioskAnalytics.track('idle_dismissed', { trigger: 'overlay' }); } catch (_) {}
-      try { this.$store.dispatch('kioskSettings/clearCustomerProfile'); } catch (_) {}
+      // PROP-KioskAppComponent-006: vacuum all PII-bearing store slices on
+      // abandon to honor the §12 DATA_CONTRACT claim made in the comment
+      // above this method. Each dispatch is independently try/catch'd so a
+      // missing action does not block the others or the resetKiosk() call.
+      try { this.$store.dispatch('kioskSettings/clearCustomerProfile'); } catch (_) {}
+      try { this.$store.dispatch('kioskCart/clearLoyaltyData'); } catch (_) {}
+      try { this.$store.dispatch('kioskCart/clearCustomerContact'); } catch (_) {}
+      try { this.$store.dispatch('kioskCart/clearCouponCache'); } catch (_) {}
       this.resetKiosk();
     },
```

Plus (out-of-scope of this file, but referenced for completeness) — the corresponding Vuex actions `clearLoyaltyData` / `clearCustomerContact` need to exist in `kioskCart` module. If they don't, this proposal is a no-op (each dispatch silently fails inside its try/catch). **A companion proposal at the Vuex layer is required.**

Total source LOC delta in *this* file : **+5 net** (4 dispatch lines + 1 comment block).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| All PII actions exist in `kioskCart` | All PII slices cleared on abandon | Possible PII leak across sessions |
| Actions don't exist | Silent try/catch — no behavior change (no-op) | None |
| Frozen-zone regression | NEGLIGIBLE — additive dispatch calls inside existing try/catch shell | None |
| Performance | Each dispatch is a cheap Vuex commit; total <1ms | N/A |
| NF525 implication | NONE — no fiscal touch | NONE |

## LOCK feasibility

- ≤10 LOC, single concern? **YES (+5 net LOC)**
- Architectural redesign needed? **NO**
- Companion Vuex changes needed? **POSSIBLY — pending audit of `kioskCart` module**
- Owner gate required? **YES (frozen file + PII surface)**

## Owner recommendation

- [x] **APPLY-WITH-LOCK** (recommended IF companion Vuex actions exist or can be added in same PR — GDPR exposure is real even at V1)
- [ ] DEFER-V1.0.2 (acceptable IF owner accepts a small but non-zero CNIL audit risk window until then)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (NOT recommended — the comment at L925 explicitly claims "pas de PII persistée après un abandon" while the code may not enforce that claim)

**Pre-condition for APPLY** : grep `kioskCart` Vuex module for `phone`, `email`, `loyalty`, `customer` paths; ensure either (a) `reset()` action already clears them or (b) the proposed companion actions are added.

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones + §13 Evidence Rules
- File L922–933 (`onInactivityLeave` + §12 DATA_CONTRACT inline comment)
- `project_goal_production_readiness_2026-05-18.md` (V1 production-readiness mandate)
- France CNIL guidance on kiosk PII (background)
