# PROPOSAL — KioskAppComponent.vue — `_bootHardware` / `_reportHealthcheck` swallow all axios errors — hardware-degradation telemetry can vanish silently

**ID** : PROP-KioskAppComponent-013
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

`_bootHardware()` at L978–1005 wires hardware events and schedules a 90s healthcheck. Each healthcheck reports via `_reportHealthcheck(hc)` (L1007–1020), which calls:

```js
axios.post('frontend/kiosk-event', {
  type: 'hardware_health',
  details: 'state=' + ...
  payload: { state, degradation, components },
}).catch(() => {});
```

The `.catch(() => {})` swallows ALL POST failures silently. If the `frontend/kiosk-event` endpoint is unreachable (because the same network gap that affects `loadBranch` also affects this) OR if it returns 5xx, the healthcheck data is lost forever (no client-side retry, no offline queue).

Critical paths affected:
1. **Hardware degradation alert** : if a printer runs out of paper at 19:30 and the healthcheck POST fails because the LAN is overloaded, the operator dashboard never sees the alert. Customer's receipt fails to print, customer walks away, operator has no signal.
2. **Hardware event reports** (`reportHardwareEvent` from `kioskHardware.onHardwareEvent` at L988–997) — also lost if the underlying `reportHardwareEvent` axios is similarly silent (need to verify the helper).
3. **Healthcheck periodic** runs every 90s. If 5 consecutive POSTs fail (≈7.5 min), the gap in operator dashboard timeline could be misinterpreted as "borne idle" rather than "borne disconnected".

This is essentially the same anti-pattern as PROP-002 (Echo silent swallow), generalized to the entire hardware observability subsystem.

### Personas impacted
- **Operator / kitchen** (HIGH — printer-out-of-paper at dinner rush is exactly the scenario this telemetry is designed to surface; silent loss defeats the purpose).
- **Cashier-multitask** (HIGH — receives the angry customer with no receipt and has to manually intervene without operator-side context).
- **Customer** (HIGH downstream — no receipt = no NF525 ticket; that *might* itself be an NF525 issue, depending on whether the order was kiosk-paid).

## Reasoning fort (multi-perspective)

### Chef perspective
Indirect — if a printer fails, the kitchen ticket may still fire (KDS rather than receipt printer), but if BOTH share the same paper-out condition, kitchen blind.

### Client perspective
No receipt = mistrust.

### Cashier perspective
Manual intervention.

### Owner perspective
"No useless complexity V1" — but this is observability, not feature. NF525 audit chain depends on receipts; defending the telemetry path that surfaces receipt-printer failure is genuinely V1-priority.

### Multi-tenant-future
At V2 SaaS, hardware failure dashboards are a standard ops surface. The fix scales.

### Adversarial dispute (challenge yourself)
- **False positive?** Possible — if `kioskHardware.reportHardwareEvent` (the helper called at L990) already has its own retry / queue / offline buffer, this gap is filled. **I did not inspect `kioskHardware.js`.** Quick check would settle this.
- **Offline queue piggyback?** The kiosk already has `kioskOfflineQueue` for orders (L162–169). Healthcheck telemetry could ride the same queue (or a sibling one) — extra effort but architecturally clean.
- **Goal cares?** Yes — receipt-printer failure ↔ NF525 ticket integrity is in scope.
- **Scope-minimal?** Yes — minimal version: add per-failure `console.warn` + local counter for visibility. Heavier: offline queue.

## Proposed change

Minimal version (visibility only, no offline queue):

```diff
     _reportHealthcheck(hc) {
       if (!hc) return;
       try {
         axios.post('frontend/kiosk-event', {
           type: 'hardware_health',
           details: 'state=' + (hc.state || 'unknown') + ' | degradation=' + (hc.degradation || 'unknown'),
           payload: {
             state: hc.state || null,
             degradation: hc.degradation || null,
             components: hc.components || {},
           },
-        }).catch(() => {});
+        }).catch((e) => {
+          // PROP-KioskAppComponent-013: track healthcheck POST failures so
+          // operators can detect a borne whose hardware-degradation pipeline
+          // is also itself degraded (typically a network gap).
+          this._healthcheckPostFailures = (this._healthcheckPostFailures || 0) + 1;
+          if (this._healthcheckPostFailures === 1 || this._healthcheckPostFailures % 10 === 0) {
+            try { kioskAnalytics.track('hardware_healthcheck_post_failed', {
+              consecutive: this._healthcheckPostFailures,
+              status: e?.response?.status || null,
+            }); } catch (_) {}
+          }
+        });
       } catch (_) {}
     },
```

Heavier version (offline queue piggyback) — out of scope for a single proposal; flagged for a companion proposal at the `kioskOfflineQueue` layer.

Total source LOC delta : **+11 net** (single method extension).

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|----------|-----------------|---------------------|
| Endpoint healthy | Identical — `.catch` chain only fires on error | Identical |
| Endpoint flake | Telemetry counter increments; analytics event on 1st + every 10th failure | Silent loss |
| Storm of failures | Counter caps the analytics events; not a feedback loop | Silent storm |
| Frozen-zone regression | NEGLIGIBLE — additive to existing `.catch` path | None |
| Memory leak | NONE — single counter, no array growth | None |
| NF525 implication | INDIRECT — better visibility into receipt-printer subsystem; no fiscal touch directly | Same |

## LOCK feasibility

- ≤15 LOC, single concern? **YES (+11 net LOC)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file)**

## Owner recommendation

- [ ] APPLY-WITH-LOCK
- [x] **DEFER-V1.0.2** (recommended — V1 Le Cayenne single-borne typically has stable LAN; the gap is more relevant at SaaS / multi-borne scale. Pair with PROP-002 + PROP-011 as a "silent observability sweep" mini-cycle in V1.0.2.)
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable for V1 ship)

**Pre-condition** : check whether `kioskHardware.reportHardwareEvent` (called at L990) already has retry/queue logic. If yes, parts of this proposal are redundant.

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones + §8 NF525 (receipt-printer ↔ ticket invariant)
- File L978–1031 (`_bootHardware`, `_reportHealthcheck`, `_reportInfo`)
- Sibling pattern: PROP-KioskAppComponent-002 (Echo telemetry)
