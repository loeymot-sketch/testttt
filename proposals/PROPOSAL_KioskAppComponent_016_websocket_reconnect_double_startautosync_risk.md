# PROPOSAL — KioskAppComponent.vue — WebSocket reconnect handler re-invokes `startAutoSync` — duplicate timer / double-poster risk if helper not idempotent

**ID** : PROP-KioskAppComponent-016
**Date** : 2026-05-23
**Frozen file** : `resources/js/components/frontend/kiosk/KioskAppComponent.vue`
**Frozen reason** : Per `CLAUDE.md §7` Frozen Zones — kiosk shell component.
**Existing LOCK** : none.

## Finding (read-only audit)

At L383–396, the `connected` handler on `window._wsService` fires `startAutoSync(...)` again if `getPendingCount() > 0`:

```js
if (window._wsService) {
  this._onWsReconnect = () => {
    if (getPendingCount() > 0) {
      startAutoSync((url, data, config) => axios.post(url, data, config || {}), syncCb);
    }
    // [V1.5C R2] After a WebSocket reconnect, Pusher may have missed catalog events
    // during the gap — force a fresh menu fetch for the active branch.
    const bid = this.$store?.state?.kioskMenu?.branchId;
    if (bid) {
      this.$store.dispatch('kioskMenu/fetchMenu', { force: true, branchId: bid }).catch(() => {});
    }
  };
  window._wsService.on('connected', this._onWsReconnect);
}
```

`startAutoSync` was already called at L344 in `mounted()`. If `startAutoSync` is NOT internally idempotent (it does not check for an existing interval/loop), the reconnect handler creates a **parallel** auto-sync loop. Over multiple reconnects, you could have N parallel loops, each POSTing the queue concurrently — duplicate POSTs to the backend, potentially racing the same idempotency key.

The backend has `X-Idempotency-Key` middleware (CLAUDE.md §9 Idempotency invariant) that *should* dedupe, but the kiosk-side queue may not be designed for concurrent drainers (entry locked-vs-not race conditions).

The fix depends on what `startAutoSync` actually does. Three possible cases:
1. **Idempotent** — the call is a no-op if already running. No bug. Proposal is moot.
2. **Replaces existing loop** — the new call clears the old interval and starts a fresh one. No accumulation but slight wasted work. Acceptable.
3. **Spawns parallel loop** — accumulating loops on each reconnect. Bug.

I can NOT verify the case without reading `helpers/kioskOfflineQueue.js`. The proposal therefore is **defensive: guard the second call so it is unambiguously safe regardless of helper behavior**.

### Personas impacted
- **Customer** (LOW in best case, MEDIUM if duplicate POSTs cause confusing UX — receipt printed twice, order acknowledged twice, etc.).
- **Backend / NF525** (MEDIUM — duplicate order submission storm during reconnect storm could stress fiscal sequence allocation, but idempotency middleware should block).

## Reasoning fort (multi-perspective)

### Chef perspective
Indirect — duplicate kitchen tickets if dedup fails downstream.

### Client perspective
Confusion.

### Cashier perspective
She handles the duplicate-receipt complaints.

### Owner perspective
The cheapest defense is a 3-LOC `stopAutoSync()` before the second `startAutoSync(...)`. Belt-and-braces regardless of helper.

### Multi-tenant-future
Fleet of bornes with flaky WiFi (food court Wi-Fi is famously bad) hits this regularly.

### Adversarial dispute (challenge yourself)
- **False positive?** YES if `startAutoSync` is idempotent. **The proposal is conditional on the helper's contract.**
- **Goal cares?** V1 production-readiness yes, given NF525 idempotency invariant.
- **Scope-minimal?** Yes — 1 LOC inside the reconnect handler.

## Proposed change

```diff
   if (window._wsService) {
     this._onWsReconnect = () => {
       if (getPendingCount() > 0) {
+        // PROP-KioskAppComponent-016: defensive — explicitly stop any prior
+        // auto-sync loop before starting a new one, so this handler stays
+        // safe regardless of whether startAutoSync is internally idempotent.
+        try { stopAutoSync(); } catch (_) {}
         startAutoSync((url, data, config) => axios.post(url, data, config || {}), syncCb);
       }
       const bid = this.$store?.state?.kioskMenu?.branchId;
       if (bid) {
         this.$store.dispatch('kioskMenu/fetchMenu', { force: true, branchId: bid }).catch(() => {});
       }
     };
     window._wsService.on('connected', this._onWsReconnect);
   }
```

`stopAutoSync` is already imported at L167. Zero new imports.

Total source LOC delta : **+3 net**.

## Risk analysis

| Scenario | Risk if applied | Risk if NOT applied |
|---|---|---|
| `startAutoSync` idempotent | No-op stop + identical start = same behavior | Same |
| `startAutoSync` replaces | Same effect, slightly more explicit | Same |
| `startAutoSync` spawns parallel | Bug eliminated | Accumulating loops on each reconnect — eventually duplicate POSTs |
| Reconnect with no pending | `stopAutoSync` not called (still inside `if`) | Same |
| Frozen-zone regression | NEGLIGIBLE — 1 LOC additive | None |
| NF525 implication | INDIRECT positive — guards idempotency invariant from queue-side double drain | If parallel drain exists, fiscal allocation could be stressed |

## LOCK feasibility

- ≤5 LOC, single concern? **YES (+3 net LOC)**
- Architectural redesign needed? **NO**
- Owner gate required? **YES (frozen file)**

## Owner recommendation

- [x] **APPLY-WITH-LOCK** (recommended — defensive, scope-minimal, protects NF525 idempotency invariant by clarity)
- [ ] DEFER-V1.0.2
- [ ] DEFER-V2
- [ ] KEEP-AS-IS (acceptable only if owner verifies `startAutoSync` internally idempotent and accepts the implicit contract)

**Pre-condition** : 5-min read of `helpers/kioskOfflineQueue.js` `startAutoSync` semantics. If verified idempotent, proposal is documentation-grade and can be downgraded to KEEP-AS-IS + an inline comment explaining why.

**Signed-off-by-owner** : ___________  **Date** : ___________

## References
- `CLAUDE.md §7` Frozen Zones + §9 Idempotency invariant
- File L383–396 (reconnect handler), L162–169 (helper imports)
