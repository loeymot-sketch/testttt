# PROPOSAL — KioskAppComponent.vue — `kiosk-init-overlay` uses `position: fixed; inset: 0; z-index: 9999` — covers the connection status banner during retry, masking network diagnostics

**ID** : PROP-KioskAppComponent-019
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`.kiosk-init-overlay` CSS at L1548–1554:

```css
.kiosk-init-overlay {
  position: fixed; inset: 0; z-index: 9999;
  background: white;
  display: flex; flex-direction: column;
  align-items: center; justify-content: center;
  gap: 1.25rem; color: #1A1A1A;
}
```

z-index 9999 means it sits above almost everything — including `ConnectionStatusBanner` (declared at L11, with its own positioning at L1201–1218, no explicit z-index visible in that excerpt). When `branchError` is true, the operator sees the error overlay with the "Retry" button BUT the connection-status banner — which is the most useful diagnostic during a retry (is the network back?) — is hidden behind it.

A staff member trying to debug a stuck borne would benefit from seeing both at once: the error and the live network state.

Cleaner pattern: lower the overlay z-index so the connection banner overlays the error (`ConnectionStatusBanner` typically has `top: 14px` and is a banner-pill at the very top — it's visually small and won't dominate the screen). OR keep z-index but render a slim status strip above the error.

### Personas impacted
- **Customer** (LOW — they cannot diagnose anyway).
- **Operator / staff** (MEDIUM — diagnostic visibility loss).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
No direct impact.

### Cashier perspective
Slight diagnostic friction when retry isn't working.

### Owner perspective
Bottom of priority list. Defensible KEEP-AS-IS — the connection-status banner could leak through any way (e.g. as a portal teleported to body), but with current CSS it's covered.

### Multi-tenant-future
Same as today.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — operators don't typically stand at a stuck borne reading two overlays at once; they tap retry, then look at the cashier station for network logs.
- **Goal cares?** Marginal. V1 KEEP-AS-IS.

## Proposed change

(Recommended only if owner wants tighter operator diagnostics.)

```diff
 .kiosk-init-overlay {
-  position: fixed; inset: 0; z-index: 9999;
+  position: fixed; inset: 0; z-index: 8000;
+  /* PROP-KioskAppComponent-019: z-index lowered from 9999 to 8000 so the
+      connection-status banner (z-index ~9000 in its own style) can still
+      render above the init-error overlay during a network-flake retry.
+      Toasts and inactivity overlay keep their 9999+ ranks. */
   background: white;
   ...
 }
```

Pre-requisite: confirm `ConnectionStatusBanner` actual z-index in its own SCSS / style block (out of scope of this file). If it's lower than 8000, this proposal alone won't work — its z-index must be raised in tandem (separate proposal for that component).

Total source LOC delta : **+1 actual + 3 comment**.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|---|---|---|
| Happy boot | Identical | Identical |
| Branch loading | Identical (only error overlay benefits) | Identical |
| Branch error during retry | Connection banner visible above the error | Connection banner hidden |
| ConnectionStatusBanner z-index < 8000 | Banner still hidden — proposal ineffective alone | Same |
| Frozen-zone regression | NEGLIGIBLE — single value | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤5 LOC, single concern? **YES (+1 actual LOC)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file)**
- Companion change required? **POSSIBLY — confirm `ConnectionStatusBanner` z-index**

## Owner recommendation

- [ ] APPLY-WITH-LOCK
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [x] **KEEP-AS-IS** (recommended — diagnostic gain is marginal, the change is borderline cosmetic, and the companion z-index audit of ConnectionStatusBanner is out of scope here)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L1548–1554 (`.kiosk-init-overlay` CSS), L1201–1218 (banner CSS)
