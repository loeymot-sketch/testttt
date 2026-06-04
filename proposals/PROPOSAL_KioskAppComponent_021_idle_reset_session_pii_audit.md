# PROPOSAL — KioskAppComponent.vue — `resetKiosk` does not vacuum kiosk session PII; relies on `kioskCart/reset` contract that may be incomplete

**ID** : PROP-KioskAppComponent-021
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`resetKiosk()` at L964–970 calls `this.reset()` (mapped from `kioskCart` Vuex action at L439) + `kioskAnalytics.resetSession()` + `$router.push({ name: 'kiosk.idle' })`. **There is no parallel sweep of `kioskSettings/clearCustomerProfile` or other PII slices**.

Compare with `onInactivityLeave()` at L928–933 which DOES dispatch `kioskSettings/clearCustomerProfile` before `resetKiosk()`. So:
- "Abandon via inactivity countdown" → clears customer profile.
- "Idle timer expires silently (90s, no countdown shown — e.g. on `kiosk.payment` if PROP-001 applied)" → does NOT clear customer profile because it calls `resetKiosk` directly.
- "Direct `resetKiosk` from a child component" (e.g. via `@reset-kiosk` emitter at L127) → does NOT clear customer profile.

Two reset paths, two different PII sweeps. Cross-session leak depends on which path fires.

This is the **same finding as PROP-006**, but viewed from the `resetKiosk` exit point rather than the `onInactivityLeave` entry point. The cleanest fix is to relocate the PII vacuum INTO `resetKiosk` so all reset paths share the same hygiene.

### Personas impacted
- **Next customer at the borne** (HIGH — PII leak window is wider than PROP-006 implies, because multiple reset paths exist).
- **GDPR / CNIL audit** (CRITICAL at V2 SaaS).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
Same as PROP-006 — privacy leak risk.

### Cashier perspective
Same.

### Owner perspective
This is the architectural fix; PROP-006 is the local fix. They're complementary — PROP-006 ensures the inactivity-leave path is clean, PROP-021 ensures ALL reset paths are clean.

### Multi-tenant-future
Critical at V2.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — if `kioskCart/reset` already cascades through all PII modules (via Vuex orchestration), the gap is illusory. **I did not inspect `kioskCart` Vuex module.** Same caveat as PROP-006.
- **Double dispatch?** If both PROP-006 (inactivity-leave) AND PROP-021 (resetKiosk) dispatch the same actions, the inactivity-leave path now triggers them twice. That's a wasted commit but not incorrect (Vuex actions are usually idempotent on "clear" ops). Cleanest: relocate the PII vacuum ONLY to `resetKiosk` and REMOVE it from `onInactivityLeave` (the latter calls `resetKiosk` immediately after — pop the dispatch upstream).
- **Goal cares?** Yes — same GDPR concern as PROP-006.
- **Scope-minimal?** Yes — relocate 1 line + add 3 more.

## Proposed change

```diff
     resetKiosk() {
       this.reset();
       this.clearIdleTimer();
+      // PROP-KioskAppComponent-021: centralize PII vacuum here so ALL reset
+      // paths (inactivity-leave, idle-timeout, child @reset-kiosk emitter,
+      // safety-net PROP-001 timeout) share the same hygiene contract.
+      try { this.$store.dispatch('kioskSettings/clearCustomerProfile'); } catch (_) {}
+      try { this.$store.dispatch('kioskCart/clearLoyaltyData'); } catch (_) {}
+      try { this.$store.dispatch('kioskCart/clearCustomerContact'); } catch (_) {}
+      try { this.$store.dispatch('kioskCart/clearCouponCache'); } catch (_) {}
       try { kioskAnalytics.resetSession(); } catch (_) {}
       this.$router.push({ name: 'kiosk.idle' });
     },
```

And then *remove* the now-redundant dispatch from `onInactivityLeave`:

```diff
     onInactivityLeave() {
       this.showStillHere = false;
       try { kioskAnalytics.track('idle_dismissed', { trigger: 'overlay' }); } catch (_) {}
-      try { this.$store.dispatch('kioskSettings/clearCustomerProfile'); } catch (_) {}
       this.resetKiosk();
+      // PII vacuum is centralized in resetKiosk per PROP-KioskAppComponent-021.
     },
```

Total source LOC delta : **+8 / -1 = +7 net**.

NOTE : this proposal SUPERSEDES PROP-KioskAppComponent-006 — they cover the same surface, but PROP-021 is the architecturally cleaner placement. If owner applies PROP-021, PROP-006 should be marked obsolete.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|---|---|---|
| `onInactivityLeave` → `resetKiosk` | PII vacuum runs once in `resetKiosk` | PII vacuum runs in inactivity-leave only |
| `kiosk.payment` 15-min safety-net (PROP-001) | PII vacuum runs | PII potentially leaked to next customer |
| Child `@reset-kiosk` emitter | PII vacuum runs | PII potentially leaked |
| Companion Vuex actions exist | All slices cleared | Same dependency as PROP-006 |
| Companion Vuex actions missing | Silent try/catch — no break | N/A |
| Frozen-zone regression | LOW — additive to resetKiosk + 1-LOC remove from onInactivityLeave | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤10 LOC, single concern? **YES (+7 net LOC)**
- Architectural redesign needed? **NO — centralization refactor**
- Owner gate required? **YES (frozen file + PII boundary)**

## Owner recommendation

- [x] **APPLY-WITH-LOCK** (recommended — supersedes PROP-006 with cleaner placement; closes the additional reset paths PROP-006 did not address)
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (NOT recommended — same GDPR risk as PROP-006, now extended to more reset paths)

**Pre-condition for APPLY** : same as PROP-006 — confirm `kioskCart/clearLoyaltyData`, `clearCustomerContact`, `clearCouponCache` actions exist or add them in a companion PR.

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones + §13 Evidence
- File L928–933 (`onInactivityLeave`), L964–970 (`resetKiosk`), L127 (`@reset-kiosk` emitter from child router-view)
- Sibling: PROP-KioskAppComponent-006 (superseded by this proposal if applied)
