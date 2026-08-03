# HEAL_PLAN — F-W5-01 (Encaissement realtime) + Phase C choreography
Pre-staged during the abuse-e2e drain gate (read-only). Apply when DRAINED.

## F-W5-01 — add Echo to EncaissementComponent.vue (NON-FROZEN, ~15 LOC)

Mirror the canonical OSS pattern (`PreparingAndReadyComponent.vue:260-304`). **Keep the 20s
poll as fallback** (do NOT remove it) — Echo = primary sub-second, poll = safety net, exactly
like every other operational surface.

```js
// 1) import (top of <script>, alongside existing imports)
import { onEvents } from "../../../services/eventContract";

// 2) mounted() — after the existing setInterval poll:
this.subscribeEcho();

// 3) beforeUnmount() — before/after clearing pollTimer:
this.unsubscribeEcho();

// 4) methods{}: add
subscribeEcho() {
    if (!window.Echo) return;
    // mirror OSS: this.authBranchId() (global auth mixin). Fallback if absent:
    const branchId = Number(
        (typeof this.authBranchId === 'function' ? this.authBranchId() : null)
        ?? this.$store.getters['auth/authBranchId'] ?? 0
    );
    if (branchId <= 0) return;            // admin(0) stays poll-only by design
    this.unsubscribeEcho();               // prevent duplicate listeners on re-mount
    try {
        this._eventSub = onEvents(branchId, [
            { broadcastAs: 'OrderCreated',       handler: () => this.fetchPending(true) }, // new borne order arrives
            { broadcastAs: 'OrderPaidAtCounter', handler: () => this.fetchPending(true) }, // order collected → drops off
            { broadcastAs: 'OrderStatusChanged', handler: () => this.fetchPending(true) }, // catch-all (cancel/refund)
        ]);
    } catch (e) { console.warn('[Encaissement] Echo subscription failed:', e.message); }
},
unsubscribeEcho() {
    try { this._eventSub?.unsubscribe(); } catch (_) {}
    this._eventSub = null;
},
```

Notes:
- `fetchPending(true)` = silent refresh (no spinner) — re-queries `admin/pos/counter-collect/pending`
  which filters `PENDING_COUNTER`, so it naturally ADDS new borne orders + REMOVES collected ones.
- At apply-time: confirm OSS's `authBranchId()` resolution (grep its methods / global mixin) and use
  the identical call; the Vuex-getter fallback above is belt-and-suspenders.
- Rebuild bundle after edit (`npm run dev`/webpack) — required for the .vue change to take effect.
- Risk: minimal, additive (no removal, no logic change to existing poll/collect). Not frozen-zone.

## Phase C choreography (after DRAIN, login chef@lecayenne.fr branch_id=1)

**Round 0 — baseline (pre-heal), prove the gap:**
1. Inject borne CASH order (`SYNC-E2E-` name) → start timer.
2. Watch KDS (chef WS) → record latency to card appearing (expect ~130–500ms).
3. Watch /admin/encaissement → record latency to row appearing (expect ~0–20s = F-W5-01 baseline).
4. Numeric integrity: total string-equal across kiosk/KDS/OSS/tracker/encaissement + DB `orders.total`.
5. PREPARING→PREPARED on KDS → measure propagation to OSS + tracker + kiosk-waiting.
6. Counter-collect the borne order → measure OrderPaidAtCounter propagation (KDS/kiosk reflect paid;
   encaissement row clears — baseline ≤20s).
7. Edges: kill worker (poll still shows order = no loss, confirm linchpin live); WS cut (graceful);
   double-tap submit (1 order).

**Apply F-W5-01 heal → rebuild bundle.**

**Round 1 — post-heal:** repeat steps 1–3 + 6 → encaissement row now appears/clears **sub-second**
(< 2s). Plus full re-sweep for regressions.

**Round 2 — confirm:** repeat → P0+P1=0, findings set-equal to Round 1 → converge.

Cleanup `SYNC-E2E-` orders (`cleanup_orphans.sh`). No push. Frozen/NF525 → escalate.

## Out-of-scope items to surface to owner (deliverable #4) — NOT healed here
- Fiscal payload completeness (W1-P0-002/P1-002): `fiscal_sequence_no` absent from broadcast payload.
- Channel-auth guest-role latent (W3-R6, security/channels.php).
- IdempotencyKeyMiddleware fail_open race (W6-R5, FROZEN §7).
- Counter-deferred "cook-before-pay" semantics (W1-P0-001/W5 PENDING_COUNTER) — confirm intent.
