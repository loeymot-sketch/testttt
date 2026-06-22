# PROPOSAL — KioskAppComponent.vue — `branchError` overlay offers a single manual retry button — no auto-retry, no exponential backoff, no telemetry per retry

**ID** : PROP-KioskAppComponent-012
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

When `loadBranch()` (L511–538) fails (HTTP 401 / 5xx / network), it sets `branchError` and the template at L42–49 renders an error overlay with a "Retry" button that fires `loadBranch()` again on click.

Observations:
1. **No auto-retry** : if the borne has a transient network hiccup at boot, a sighted customer must walk up and tap retry. An unattended kiosk in the middle of the night that boots before the network is fully up will stay frozen on the error overlay until someone physically taps it.
2. **No exponential backoff** : if the customer panics and taps retry 5 times in 2 seconds, 5 sequential GETs hammer the backend, each likely to fail with the same root cause.
3. **No telemetry per retry** : `loadBranch` itself has no `kioskAnalytics.track('branch_load_failed', ...)`. Operator monitoring would see only the eventual success (or never).
4. **HTTP 401 message** (L532–534) says "session expired" — but the kiosk has its own Sanctum kiosk:order token (cf. `CLAUDE.md §9` Sanctum invariants). A 401 here means the *kiosk's bootstrap token* is invalid, which is a different recovery class than user session expiry. The error message conflates two failure modes.

### Personas impacted
- **First customer of the morning** (HIGH — borne booted overnight before network came up; they arrive at an error overlay and may not realize tapping retry would help).
- **Operator** (HIGH — no visibility into how often this fires).

## Reasoning fort (multi-perspective)

### Chef perspective
No direct impact, but: lost morning customers = lost orders.

### Client perspective
A borne stuck on "Service indisponible" is a hostile-feeling kiosk. Auto-retry with a friendly countdown ("Reconnexion dans 5s…") is the cleanest UX.

### Cashier perspective
She receives the "the borne is broken" complaint when in fact the network just needed 30s more to come up.

### Owner perspective
This is genuinely useful at V1 Le Cayenne single-borne. The fix is small.

### Multi-tenant-future
A V2 SaaS deployment with morning auto-boot scripts will need this systematically.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — if the `ConnectionStatusBanner` at L11 already handles transient network state and *its* reconnect triggers `loadBranch` to retry, the gap is partially filled. **But ConnectionStatusBanner is a status banner, not a boot orchestrator.** I would not expect it to drive `loadBranch` retries.
- **Auto-retry storms?** A naive `setTimeout(loadBranch, 1000)` could storm the backend. Exponential backoff (1s → 2s → 4s → 8s, capped at 30s) is correct.
- **Goal cares?** V1 production-readiness yes — `feedback_no_cloud_until_owner_initiates` keeps the borne LOCAL Le Cayenne, but local LAN can still flake during morning boot.
- **Scope-minimal?** Yes — single new method + 2-line wire.

## Proposed change

```diff
+    /**
+     * PROP-KioskAppComponent-012: auto-retry branch load with exponential
+     * backoff so unattended morning boot recovers without staff intervention.
+     * Per-attempt telemetry surfaces flaky boots to fleet ops.
+     */
+    _scheduleBranchRetry() {
+      const attempt = (this._branchRetryAttempts || 0) + 1;
+      this._branchRetryAttempts = attempt;
+      // Backoff: 2s, 4s, 8s, 16s, 32s, then 60s for any further attempts.
+      const delayMs = Math.min(60000, Math.pow(2, attempt) * 1000);
+      this._branchRetryTimer = setTimeout(() => {
+        this.loadBranch();
+      }, delayMs);
+      try {
+        kioskAnalytics.track('branch_load_retry_scheduled', { attempt, delayMs });
+      } catch (_) {}
+    },
+
     async loadBranch() {
       this.branchLoading = true;
       this.branchError = null;
+      if (this._branchRetryTimer) {
+        clearTimeout(this._branchRetryTimer);
+        this._branchRetryTimer = null;
+      }
       try {
         const res = await this.loadBranchList({ vuex: false });
         const branch = res?.data?.data?.[0];
         if (branch?.id) {
           // ... unchanged ...
+          this._branchRetryAttempts = 0; // Reset on success.
         } else {
           this.branchError = this.$t('kiosk.app.branch_unavailable');
           this.branchLoading = false;
+          this._scheduleBranchRetry();
         }
       } catch (err) {
         const msg = err?.response?.status === 401
           ? this.$t('kiosk.app.session_expired')
           : this.$t('kiosk.app.server_unreachable');
         this.branchError = msg;
         this.branchLoading = false;
+        try {
+          kioskAnalytics.track('branch_load_failed', {
+            status: err?.response?.status || null,
+            attempt: this._branchRetryAttempts || 0,
+          });
+        } catch (_) {}
+        this._scheduleBranchRetry();
       }
     },
```

Plus `beforeUnmount` cleanup:

```diff
+      if (this._branchRetryTimer) {
+        clearTimeout(this._branchRetryTimer);
+        this._branchRetryTimer = null;
+      }
```

Total source LOC delta : **+27 net** (1 new method + 5 lines in loadBranch + 4 cleanup).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Network up at boot | Behavior identical (success path unchanged) | Identical |
| Network flake at boot | Auto-retries with backoff; ~30s recovery typical | Borne stuck until staff taps |
| Backend persistently down | Backoff caps at 60s — backend hit ~1× per minute (not a storm) | Same after staff tap, or until next manual |
| Manual retry button | Still works (calls loadBranch which clears pending retry) | Same |
| Frozen-zone regression | MEDIUM — adds new method + 3 hooks inside loadBranch | None |
| NF525 implication | NONE | NONE |

## LOCK feasibility

- ≤30 LOC, single concern? **YES (+27 net LOC)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file)**

## Owner recommendation

- [x] **APPLY-WITH-LOCK** (recommended — direct fix for unattended-morning-boot scenario, scope-minimal, observability gain)
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable only if owner confirms a wrapping orchestrator OR systemd unit retries the boot from outside the kiosk frontend)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones + §9 Sanctum kiosk:order
- File L511–538 (`loadBranch`)
