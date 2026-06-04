# PROPOSAL — KioskAppComponent.vue — `mounted()` chains 5+ async side-effects with no error boundary — partial failure leaves shell in undefined state

**ID** : PROP-KioskAppComponent-010
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`mounted()` at L323–397 performs ≥10 distinct setup operations in sequence:

1. `loadKioskTheme()` — sync local-storage read
2. `startIdleTimer()` — sync timer wiring
3. `loadBranch()` — **async** GET branch list + setBranch + pre-warm menu + Echo subscribe (L511–538)
4. `_loadSettingsIntoGlobalState()` — **async** GET settings (L500–509)
5. `applyKioskA11yFromStore($store)` — sync
6. `_wireA11yWatchers()` — sync
7. `_bootHardware()` — **async** healthcheck + listener register + 90s interval (L978–1005)
8. `_bootAnalyticsGate()` — sync
9. `document.addEventListener('touchstart', ...)` — sync
10. `startAutoSync(...)` — sync
11. `setInterval(syncCb, 15000)` — sync
12. `refreshOfflineConflictEntries()` — **async**
13. Two `window.addEventListener('kiosk-auth-*', ...)` — sync
14. `window._wsService?.on('connected', ...)` — sync (if available)

Items 3, 4, 7, 12 are async, fired-and-forgotten in parallel. If any throws unhandled (e.g. `axios` exception, Vuex action rejection), there is no `try/catch` boundary at the `mounted()` level. The Vue component instance may end up in a half-initialized state:
- Theme loaded but no branch → router-view mounts categories screen → API call fails or returns wrong-branch data.
- Branch loaded but Echo subscription failed → kiosk runs without live sync (cf. PROP-002).
- Hardware healthcheck never completes → 90s interval still scheduled, but `_hardwareUnsub` may be undefined → `beforeUnmount` cleanup throws.

Vue's Options API does *not* automatically swallow promise rejections from `mounted()`. The methods called (`loadBranch`, `_loadSettingsIntoGlobalState`, `_bootHardware`) each have internal try/catch, but **the chain is implicit** — there is no documented contract for "what happens if step 3 fails before step 4 starts".

The fix is not necessarily to wrap mounted in one try/catch, but to formalize the boot sequence and add a single `bootError` flag that the template renders if any *critical* step fails.

### Personas impacted
- **Client (first one of the day after a deploy)** (MEDIUM — borne boots, looks OK, but a silent boot failure could leave Echo unsubscribed, settings unloaded, or branch unset. They might successfully place an order against the wrong branch, or no branch.)
- **Operator** (HIGH — silent boot failures are the worst kind to diagnose; no error overlay, no log signal at this layer.)

## Reasoning fort (multi-perspective)

### Chef perspective
No direct impact, but: if branch is silently unset, orders may go to a default branch → wrong kitchen. Operational severity HIGH.

### Client perspective
They have no signal anything is wrong.

### Cashier perspective
She receives the chaos downstream.

### Owner perspective
"No useless complexity V1" — but the proposal here is mostly **observability + an explicit boot contract**, not new behavior. The current implicit ordering is more fragile than an explicit `_bootSequence()` async method that gates the shell with a clear pass/fail.

### Multi-tenant-future
At V2 SaaS, each new tenant onboarding will hit some flavor of this — Echo creds wrong, settings endpoint slow, kioskHardware bridge missing. Explicit boot contract amortizes.

### Adversarial dispute (challenge yourself)
- **False positive?** Each individual method already self-handles errors. The aggregate "implicit ordering" critique is more architectural than empirical.
- **Probability of real-world boot failure?** LOW per single boot, MEDIUM cumulative across deploys / kiosk restarts.
- **Goal cares?** V1 production-readiness mandate says yes. `feedback_insights_full_2026-05-18` highlights "buggy first-pass" as a top friction — boot-time silent failures are exactly that class.
- **Scope-minimal possible?** Yes — refactor `mounted()` into `_bootSequence()` async method with explicit await + try/catch + `bootError` state.

## Proposed change

Minimal version (single concern: surface a critical-failure flag):

```diff
   async mounted() {
+    // PROP-KioskAppComponent-010: formalize the boot sequence so critical
+    // failures surface as a single shell-level signal instead of silently
+    // leaving the component half-initialized. Branch + settings are CRITICAL;
+    // hardware / analytics are NON-CRITICAL (best-effort).
+    try {
+      await this._criticalBoot();
+    } catch (e) {
+      this.branchError = this.$t('kiosk.app.boot_failed_generic');
+      this.branchLoading = false;
+      try {
+        kioskAnalytics.track('shell_boot_failed', { reason: e?.message || 'unknown' });
+      } catch (_) {}
+      try {
+        axios.post('frontend/kiosk-event', {
+          type: 'shell_boot_failed',
+          details: e?.message || 'unknown',
+        }).catch(() => {});
+      } catch (_) {}
+      return; // Skip non-critical setup if critical failed.
+    }
+    this._nonCriticalBoot();
+  },
+
+  // ... in methods: ...
+
+  async _criticalBoot() {
     this.loadKioskTheme();
     this.startIdleTimer();
-    this.loadBranch();
-    this._loadSettingsIntoGlobalState();
+    await this.loadBranch();
+    if (this.branchError) throw new Error('loadBranch_failed:' + this.branchError);
+    await this._loadSettingsIntoGlobalState();
     applyKioskA11yFromStore(this.$store);
     this._wireA11yWatchers();
-    this._bootHardware();
+  },
+
+  _nonCriticalBoot() {
+    this._bootHardware();
     this._bootAnalyticsGate();
-    document.addEventListener('touchstart', this.handleTouch, { passive: true });
-    ... (rest of original mounted body unchanged)
+    document.addEventListener('touchstart', this.handleTouch, { passive: true });
+    // ... (rest of original mounted body)
   },
```

(The diff above is a sketch — full implementation would faithfully relocate the entire body of `mounted()` into `_nonCriticalBoot()`, preserving the offline sync wiring, auth listeners, etc.)

Total source LOC delta : **+30 / -20 = +10 net** (refactor existing 60-line method into 3 methods).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Happy path boot | Identical UX, slightly more deterministic ordering | Identical UX |
| `loadBranch` fails | Existing error overlay shows; non-critical setup skipped (cleaner) | Existing overlay shows but other async ops continue, may leak listeners |
| `_loadSettingsIntoGlobalState` fails | Boot fails cleanly with telemetry | Current: settings silently absent; downstream fallbacks |
| `_bootHardware` fails | Non-critical — boot proceeds | Same — already non-critical |
| Frozen-zone regression | MEDIUM — `mounted()` body is restructured; review carefully | None |
| Refactor introduces ordering bug | YES — full regression suite required | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤30 LOC, single concern? **BORDERLINE** — refactor touches the largest method in the file.
- Architectural redesign needed? **PARTIALLY — introduces an explicit boot contract.**
- Owner gate required? **YES — non-trivial change to a frozen shell file.**
- Test mandatory? **YES — full Vitest of KioskAppComponent + Playwright kiosk boot sequence.**

## Owner recommendation

- [ ] APPLY-WITH-LOCK (acceptable but heaviest of this proposal batch — defer if owner does not prioritize)
- [x] **DEFER-V1.0.2** (recommended — V1 boot has been stable in Le Cayenne production cycles; this is hardening, not a current bug fix. Defer until a broader shell refactor cycle.)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable — the implicit chain has not produced documented incidents at V1)

**Pre-condition** : full Vitest coverage for KioskAppComponent boot path before applying.

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones + §13 Evidence Rules
- File L323–397 (`mounted()`), L398–437 (`beforeUnmount()` — paired cleanup)
- `feedback_insights_full_2026-05-18.md` (buggy first-pass friction)
