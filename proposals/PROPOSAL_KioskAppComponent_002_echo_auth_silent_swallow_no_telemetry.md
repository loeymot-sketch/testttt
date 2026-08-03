# PROPOSAL — KioskAppComponent.vue — Echo subscription auth-failure swallowed silently with no telemetry — kiosk runs blind on TTL cache

**ID** : PROP-KioskAppComponent-002
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`_subscribeEchoChannel(branchId)` at L542–580 wraps `onEvents(branchId, [...handlers])` in a bare `try { ... } catch (_) { /* Echo auth may fail if token not ready — silent fallback to TTL */ }`. The catch comment acknowledges that auth failure is silent **by design**, and the kiosk falls back to TTL cache (60s server-side).

The problem: there is **no telemetry path** to surface that the Echo subscription failed. No metric, no `kioskAnalytics.track`, no `axios.post('frontend/kiosk-event')`, no toast, no console.warn. In production, an operator inspecting a borne that "feels slow to react to admin toggles" has no signal that the live channel is dead and only TTL is keeping it warm.

Commit `a68acb20f` (Q9-S1 fix 2026-05-21) wired `InvalidateKioskMenuCacheOnCatalogChange` for extras + variations, reducing the worst-case staleness from up to 60s to ~1s **assuming the kiosk's Echo channel is healthy**. If Echo auth silently fails on the kiosk, the live-sync gain is lost and the kiosk reverts to 0–60s staleness — the very gap the Q9 audit was meant to close.

### Personas impacted
- **Cashier-multitask** (HIGH — the cashier can no longer trust "the admin toggle propagates in ~1s"; if Echo is dead on a borne, she sees no propagation, gets phantom rejections at confirm, and has no way to diagnose).
- **Operator / fleet-manager** (HIGH — at V2 SaaS scale, no fleet observability for Echo health per kiosk = silent multi-tenant degradation).
- **Client-impatient** (MEDIUM — they hit the "item unavailable" reject at confirm screen instead of seeing the item disappear from the menu page).

## Reasoning fort (multi-perspective)

### Chef perspective
No direct impact, but: chef toggles out-of-stock → admin propagates → kiosk takes up to 60s instead of ~1s → customers continue adding the item for 60s → batch of phantom rejections lands at confirm screen → kitchen sees no new orders for that window. Mild operational friction.

### Client perspective
Frustration moment: customer adds Algerienne sauce, walks through wizard, taps "Confirm" → "Algerienne indisponible, désolé". They blame the kiosk, not the sync gap. Direct UX hit.

### Cashier perspective
The cashier-multitask persona is now responsible for diagnosing why the kiosk is "out of date" without any signal. She cannot do this; she will assume "the system is broken" and the support ticket lands on the developer.

### Owner perspective
"No useless complexity V1" — owner's mandate. But the proposal here is **observability, not feature**. A 3-LOC `kioskAnalytics.track('echo_subscription_failed', {...})` + a 3-LOC `axios.post('frontend/kiosk-event')` heartbeat gives the operator a single fleet-wide dashboard signal. This is *not* added complexity — it is *deferred-debt removal*.

### Multi-tenant-future
At V2 SaaS, fleet operators need per-tenant per-borne Echo health. Without it, a tenant complaining "our kiosks are slow" has no diagnostic. The cheapest tracker is added once now.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — if the upstream `onEvents` already emits a telemetry side-effect on auth failure (the eventContract service), the catch here is redundant. **I did not verify `onEvents` internals.** A 5-min check of `resources/js/services/eventContract.js` would confirm.
- **TTL fallback is acceptable?** For V1 single-borne Le Cayenne, yes (the cashier can manually refresh the kiosk). For V2 SaaS multi-borne, no.
- **`kiosk-auth-failed` event already covers this?** Partial — L360–368 handles HTTP 401 reauth, but the Echo (websocket) channel uses its own auth (broadcasting auth route, typically `/broadcasting/auth`). HTTP 401 retry does NOT re-subscribe Echo. So Echo failure is genuinely silent today.
- **Goal cares?** Yes — `project_wave_polish_final_2026-05-21` empirically validated Q9-S1 sync 0-60s→~1s. That measurement is meaningful **only if the live channel is healthy** on the measured borne. A silent-fail mode is a hidden regression risk.

## Proposed change

```diff
-      try {
-        this._eventSub = onEvents(branchId, [
-          {
-            broadcastAs: 'ItemAvailabilityChanged',
-            handler: (event) => {
-              this._handleItemAvailabilityChanged(event, branchId);
-            },
-          },
-          // ... (4 handlers total)
-        ]);
-      } catch (_) {
-        // Echo auth may fail if token not ready — silent fallback to TTL
-      }
+      try {
+        this._eventSub = onEvents(branchId, [
+          // ... unchanged handlers ...
+        ]);
+        // PROP-KioskAppComponent-002: heartbeat success so fleet observability
+        // can distinguish "subscribed-and-healthy" from "subscribed-but-stale".
+        try {
+          kioskAnalytics.track('echo_subscription_ok', { branchId });
+        } catch (_) {}
+      } catch (e) {
+        // PROP-KioskAppComponent-002: was silent-swallow; now emits a single
+        // analytics event + non-blocking kiosk-event POST so the fleet
+        // dashboard surfaces silently-degraded bornes. TTL fallback unchanged.
+        try {
+          kioskAnalytics.track('echo_subscription_failed', {
+            branchId,
+            reason: e?.message || 'unknown',
+          });
+        } catch (_) {}
+        try {
+          axios.post('frontend/kiosk-event', {
+            type: 'echo_subscription_failed',
+            details: e?.message || 'subscribe_failed',
+            payload: { branchId },
+          }).catch(() => {});
+        } catch (_) {}
+      }
```

Total source LOC delta : **+18 / -2 = +16 net** (single method, no new imports — `kioskAnalytics` + `axios` already in scope).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Echo subscribed successfully | One analytics event sent per branch-load (~1/session) | No observability into success |
| Echo auth fails | One analytics + one POST sent; user-visible UX unchanged | Silent — no observability, no diagnostic, Q9-S1 sync gain lost |
| Analytics service down | try/catch protects — no kiosk crash | N/A |
| `frontend/kiosk-event` endpoint down | `.catch(() => {})` protects — no kiosk crash | N/A |
| Frozen-zone regression | LOW — additive observability, no behavior change in subscribe / handlers | None |
| NF525 implication | NONE — no fiscal touch | NONE |

## LOCK feasibility

- ≤20 LOC, single concern? **YES (+16 net LOC)**
- Architectural redesign needed? **NO — additive observability**
- Owner gate required? **YES — frozen file**

## Owner recommendation

- [x] **APPLY-WITH-LOCK** (recommended — Q9-S1 fix was empirically validated, but its production value depends on Echo being healthy; one-time observability cost protects that investment)
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable only if owner confirms an upstream `eventContract.onEvents` already emits failure telemetry — in which case this proposal is redundant; quick grep on `eventContract.js` settles this)

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones
- Commit `a68acb20f` (Q9-S1 sync fix)
- `project_wave_polish_final_2026-05-21.md` (Q9-S1 0-60s→~1s)
- File L542–580 (`_subscribeEchoChannel`)
