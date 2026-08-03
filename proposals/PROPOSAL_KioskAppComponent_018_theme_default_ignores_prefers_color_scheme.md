# PROPOSAL — KioskAppComponent.vue — Theme default hardcoded `light` — ignores `prefers-color-scheme: dark` for evening / fatigued customers

**ID** : PROP-KioskAppComponent-018
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`data()` returns `themeMode: 'light'` at L242. `loadKioskTheme()` at L443–453 has the same fallback: `['dark', 'light'].includes(stored) ? stored : 'light'`. The inline comment (L237–241) explicitly cites the owner's mandate (mobile-app brand palette: noir/rouge/jaune/blanc, light is the canonical default).

This is owner-intent, not a bug. However, two observations:

1. The `light` default ignores `window.matchMedia('(prefers-color-scheme: dark)')` — typically the OS-level signal that the user prefers dark UI (often for evening / low-light conditions). For a Le Cayenne kiosk used outdoors in evening, or by light-sensitive customers, the dark mode is *more* comfortable.
2. The toggle is a per-borne preference saved in localStorage, **shared across all customers** of that borne. Customer A sets dark → customer B inherits dark — that may not be customer B's preference. Reset on idle is appropriate; **the current code does NOT reset theme on `resetKiosk`**.

The cleaner pattern: respect `prefers-color-scheme` as an initial fallback (if no localStorage key); reset to the OS preference on `resetKiosk` (so each session starts fresh). The toggle persists only WITHIN a session.

### Personas impacted
- **Customer (evening / low-light)** (LOW — sighted, can read either theme; the borne's screen is bright enough either way).
- **Customer (light-sensitive / migraine-prone)** (LOW-MEDIUM — bright white kiosk panel can be uncomfortable for some).
- **Owner brand identity** (HIGH if light is the BRAND MANDATE — overriding it on `prefers-color-scheme: dark` would violate the mandate).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
Owner brand mandate dominates — customer comfort is secondary to brand consistency.

### Cashier perspective
No impact.

### Owner perspective
**The owner has explicitly said: light is the canonical default (L237–242 comment).** This proposal challenges that mandate. The cheapest non-conflicting variant: keep `light` as fallback BUT respect `prefers-color-scheme` only when *the OS strongly signals dark* AND the user has not yet toggled (i.e., no localStorage key). Owner discretion.

### Multi-tenant-future
A SaaS tenant might want either default. Current code is fine; tenant-config can override.

### Adversarial dispute (challenge yourself)
- **False positive?** Likely yes — the owner brand mandate is explicit. This proposal is borderline second-guessing.
- **Cross-session theme leak?** Real concern — the toggle persists in localStorage across customer sessions on a public borne. Customer A → dark → Customer B inherits dark. This IS a UX gap even if minor.
- **Scope-minimal?** Yes — reset theme to default in `resetKiosk`.

## Proposed change

Recommended (single concern — reset theme on session reset, ignoring prefers-color-scheme to honor brand):

```diff
     resetKiosk() {
       this.reset();
       this.clearIdleTimer();
+      // PROP-KioskAppComponent-018: reset theme to owner-mandated light on
+      // each new session so a previous customer's toggle does not bleed
+      // into the next customer's experience. localStorage key is left
+      // intact (the toggle is a per-operator preference, not a per-customer
+      // session state — operator must explicitly re-toggle if they want
+      // dark mode to persist across customers).
+      if (this.themeMode !== 'light') {
+        this.themeMode = 'light';
+        this.applyKioskTheme('light');
+      }
       try { kioskAnalytics.resetSession(); } catch (_) {}
       this.$router.push({ name: 'kiosk.idle' });
     },
```

Total source LOC delta : **+8 net**.

NOTE : the proposal does NOT add `prefers-color-scheme` support. Owner brand mandate is preserved.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|---|---|---|
| Operator sets dark via toggle | Theme is light on next customer (session reset); operator must re-toggle if needed | Operator's dark stays for all future customers until they re-toggle |
| Customer-to-customer theme leak | Eliminated | Real |
| LocalStorage persistence | Untouched (theme key preserved across boot, but not across in-session reset) | Same |
| Frozen-zone regression | LOW — additive logic, no contract change | None |
| NF525 implication | NONE | NONE |
| Owner brand mandate | Preserved — default is still light | Preserved |

## LOCK feasibility

- ≤10 LOC, single concern? **YES (+8 net LOC)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file + this contradicts a per-operator UX assumption — operator must explicitly re-toggle each customer if dark is desired)**

## Owner recommendation

- [ ] APPLY-WITH-LOCK (recommended only if owner agrees that per-customer theme reset > per-operator persistence)
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [x] **KEEP-AS-IS** (recommended — owner brand mandate is explicit; the customer-to-customer theme leak is a borderline minor UX concern not worth challenging the mandate over. Re-evaluate if customer complaints arrive.)

**Pre-condition for APPLY** : owner explicitly confirms "theme should reset on each customer session" semantics.

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L237–242 (owner brand mandate comment), L443–453 (`loadKioskTheme`), L964–970 (`resetKiosk`)
- iter15-mega-fix C-011 round-7 (2026-05-10 brand palette commit)
