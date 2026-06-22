# PROPOSAL — KioskAppComponent.vue — `localStorage` access in theme load/toggle wrapped in silent try/catch — no telemetry on private-mode borne

**ID** : PROP-KioskAppComponent-011
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`loadKioskTheme()` at L443–453 and `toggleTheme()` at L461–466 wrap `window.localStorage?.getItem` / `setItem` in bare `try { ... } catch (_) {}`:

```js
loadKioskTheme() {
  let stored = null;
  try { stored = window.localStorage?.getItem('foodking:kiosk-theme'); } catch (_) {}
  // ...
},

toggleTheme() {
  const next = this.themeMode === 'dark' ? 'light' : 'dark';
  this.themeMode = next;
  try { window.localStorage?.setItem('foodking:kiosk-theme', next); } catch (_) {}
  this.applyKioskTheme(next);
},
```

Two silent-failure modes:
1. **Private browsing / incognito** (rare on industrial kiosk Chrome, but possible if the borne accidentally launches Chrome in incognito): `localStorage.getItem` throws `SecurityError`. The catch swallows; theme falls back to default. Operator has no way to know preference persistence is broken.
2. **Storage quota exhausted** (unlikely for a 1-char value, but possible if other features on the borne fill quota): `setItem` throws `QuotaExceededError`. Theme appears to change but doesn't persist across reboot.

Same pattern as PROP-002 (Echo silent swallow) — silent fallbacks are correct UX behavior, but with no telemetry signal an operator cannot diagnose why a borne keeps reverting to default theme on reboot.

Severity is low because the theme is a *preference* (the brand mandate at L237–242 already prefers light); even a complete loss of localStorage doesn't break functional flow.

### Personas impacted
- **Operator** (LOW — would notice "theme reverts on reboot" eventually, but not user-blocking).
- **Owner / fleet manager (V2)** (LOW — same).
- **Customer** (NONE — they don't reboot the borne mid-session).

## Reasoning fort (multi-perspective)

### Chef perspective
No impact.

### Client perspective
None.

### Cashier perspective
None.

### Owner perspective
Bottom of the priority list. The proposal is one-line telemetry, but if owner says "no useless complexity V1", this is borderline.

### Multi-tenant-future
At V2 SaaS, fleet operators MAY want to detect bornes whose localStorage is broken (signals deeper Chrome / OS misconfiguration). One canary metric covers this.

### Adversarial dispute (challenge yourself)
- **False positive?** Largely yes — this is the *correct* defensive pattern. The proposal is incremental observability, not a bug fix.
- **Scope-minimal?** Yes — 2 LOC delta if applied.
- **Goal cares?** No, not at V1. This is best left as KEEP-AS-IS.

## Proposed change

(Only if owner wants observability.)

```diff
   loadKioskTheme() {
     let stored = null;
-    try { stored = window.localStorage?.getItem('foodking:kiosk-theme'); } catch (_) {}
+    try { stored = window.localStorage?.getItem('foodking:kiosk-theme'); } catch (e) {
+      // PROP-KioskAppComponent-011: surface localStorage unavailability once
+      // per session so fleet ops can spot borne misconfiguration.
+      try { kioskAnalytics.track('localstorage_unavailable', { op: 'get', reason: e?.name || 'unknown' }); } catch (_) {}
+    }
     const next = ['dark', 'light'].includes(stored) ? stored : 'light';
     ...
   },

   toggleTheme() {
     const next = this.themeMode === 'dark' ? 'light' : 'dark';
     this.themeMode = next;
-    try { window.localStorage?.setItem('foodking:kiosk-theme', next); } catch (_) {}
+    try { window.localStorage?.setItem('foodking:kiosk-theme', next); } catch (e) {
+      try { kioskAnalytics.track('localstorage_unavailable', { op: 'set', reason: e?.name || 'unknown' }); } catch (_) {}
+    }
     this.applyKioskTheme(next);
   },
```

Total source LOC delta : **+4 net** (2 telemetry blocks).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| `localStorage` works | Identical UX | Identical |
| `localStorage` fails (rare) | One analytics event fires | Silent (current behavior) |
| Frozen-zone regression | NEGLIGIBLE | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤5 LOC, single concern? **YES (+4 net LOC)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file)** — but borderline minimal.

## Owner recommendation

- [ ] APPLY-WITH-LOCK
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [x] **KEEP-AS-IS** (recommended — the defensive silent fallback is correct; observability gain at V1 single-borne is marginal. Re-evaluate at V2 SaaS fleet scale.)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- File L443–466 (`loadKioskTheme`, `toggleTheme`)
- Related pattern : PROP-KioskAppComponent-002 (Echo silent swallow telemetry)
