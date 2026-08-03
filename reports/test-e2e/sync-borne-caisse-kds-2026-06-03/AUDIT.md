# AUDIT — Synchronisation Borne ↔ Caisse ↔ KDS ↔ OSS ↔ Tracker ↔ Encaissement ↔ Dashboard
**V1 LOCAL Le Cayenne — single-box, FR, branch_id=1**
Date: 2026-06-03 · Branch: `heal/cms-pr1-quickwins-2026-05-18` · HEAD `aeaf0f046`
Mission: garantir zéro perte / zéro doublon / zéro statut figé / latence mesurée sur TOUTE commande (borne + caisse), accessible partout (KDS/OSS/tracker/encaissement/dashboard).

> **STATUS: Phase A (static audit) DONE · Phase C (live) GATED on abuse-e2e drain.**
> Findings below merge: (a) orchestrator direct-reads, (b) a 12-agent adversarial
> workflow (`wf_23e31275-40d`, 6 waves × verify, 1.17M tok), (c) orchestrator
> verify-before-report triage (§3ter) that re-rated several agent findings against
> actual source. Latencies + screenshots = Phase C (live, after drain).

---

## 0. Coordination gate (Step 0)

`abuse-e2e-2026-06-01` batch **ACTIVE** on shared server/DB (live Wave-E captures @04:31).
- **Drain EXIT criterion:** no write to `reports/test-e2e/abuse-e2e-2026-06-01/**` nor `tests/e2e/__screenshots__/**` for ≥25 min. (Watcher armed: monitor `bcbtgzpbj`.)
- **Phase A/B = read-only buffer** (this doc). **Phase C live writes** require `SYNC-E2E-` order prefix + `cleanup_orphans.sh` (bidirectional contamination defense).
- Services UP (shared): soketi :6001 (8936), redis :6379 (4743), `queue:work redis` default (8945) **+ `--queue=high` (55021)**, serve :8000 (8929).

---

## 1. Surfaces & 2. Channel/transport

Transport: **soketi :6001** (Pusher-compat) via `config('broadcasting.default')`. Channel: private **`branch.{branchId}`** (`routes/channels.php`). Chef (branch_id=1) → sub-second WS push; **admin (branch_id=0) → poll-only BY DESIGN** (`KitchenDisplaySystemComponent:1896`). **⇒ measure latency as `chef@lecayenne.fr`, never admin** (living-sync lesson).

| Surface | Echo events bound | Poll fallback | Poll reads orders-table direct? |
|---|---|---|---|
| **KDS** | OrderStatusChanged (JS-filtered), OrderCreated→debouncedRefresh, OrderPaidAtCounter→refetch, KdsOrderRecalled | 60s WS-up / 5s WS-down (KdsSyncService) | ✅ yes → recovers missed WS |
| **OSS** (PreparingAndReady) | OrderStatusChanged(281), OrderCreated(285) | OssSyncService poll (≤60s clamp) | ✅ |
| **POS tracker** | OrderCreated/OrderStatusChanged/OrderPaidAtCounter (692/694/705)→fetchOrders | 8–60s | ✅ |
| **Kiosk waiting** | OrderCreated(274)/OrderStatusChanged(284) | 15s + overlap guard `_pollInFlight` | ✅ (single order) |
| **Encaissement** | **NONE** ❌ | **poll-only 20s** (:98) | ✅ (fetchPending) |
| **Dashboard** (RealtimeReport/SlaAlerts) | none | 30s / 15s | ✅ (backend computes) |

---

## 3. Pipeline (producer → outbox → relay → broadcast → consumer)

```
ORDER CREATE
  POS:   OrderService.php   DB::transaction { persist + fiscal alloc(1095) ; OrderCreated::dispatch(1255) }
  KIOSK: FrontendOrderService.php  DB::transaction { create(260); GATE shouldDispatchNewOrderSignals(237) }
         ├─ cash counter-deferred → ps=PENDING_COUNTER, OrderCreated fires (prep while customer queues)  [INTENTIONAL]
         └─ card / ticket-resto   → ps=UNPAID, OrderCreated SUPPRESSED until finalizePaidKioskOrder(1314) [INTENTIONAL ghost-order guard]
       (trait DispatchableAfterCommit → transactionLevel>0 → DB::afterCommit → fires AFTER commit; DROPPED on rollback ✅)
  → event(OrderCreated) → Persist*ToOutbox listener → DomainEvent row (channel JSON, broadcast_as, idempotency_key)
       → DB::afterCommit → DispatchDomainEventsJob::dispatch(id) onQueue('high')
  → queue:work --queue=high → DispatchDomainEventsJob: lockForUpdate claim (no double) → assertEnvelopeValid
       → broadcaster->broadcast(channels, broadcast_as, envelope) → soketi ; Cache::put('ws:heartbeat',120)
       → fail → dispatched_at=null, attempts++, retry $tries=6 backoff[1,5,15,60,300]; PayloadMismatch→fail()once
       → swallow (afterCommit throw) → OutboxBroadcastSwallowedEvent → EscalateOutboxBroadcastSwallowed (Log::critical, WIRED)
  → soketi private-branch.{id} → CONSUMERS (.listen)
RECOVERY cron (Kernel.php): outbox:rescue(L40) · outbox:monitor(L50) · outbox:retry-failed --since=24h(L64) · outbox:prune(L158)
```

---

## 4. POSITIVES (static-inferred — **live-confirm in Phase C**)

> Statically traced + adversarial-verified, but asserted as **static-inferred pending Phase C live confirmation** (§3ter discipline applied to positives, not just agent negatives).

- ✅ **No ghost broadcast on rollback** — `DispatchableAfterCommit` fires post-commit only (sentinel-locked).
- ✅ **No double-broadcast** — atomic `lockForUpdate` claim + `dispatched_at` guard + UNIQUE `idempotency_key`.
- ✅ **High lane IS drained** (worker pid 55021) → real-time band.
- ✅ **Zéro-perte holds — linchpin TRACED.** KDS poll sees only `[ACCEPT,PREPARING,PREPARED]` (KdsSyncService:50) BUT the `PENDING→ACCEPT` transition is **synchronous in the create/payment path, NOT worker-dependent**: kiosk cash counter-deferred sets `status=ACCEPT` inside the create tx (`FrontendOrderService:208,590-591`), POS at create (`OrderService:742,756`), kiosk card on `finalizePaidKioskOrder:1243`. ⇒ dead worker loses the real-time PUSH but **not the order** (poll reads it as ACCEPT). Card/TR stay PENDING-until-paid = intentional ghost-guard. *(live-confirm Phase C edge.)*
- ✅ **Money numeric integrity intact** — NO client-side total recompute on any surface (grep-confirmed); surfaces render backend total verbatim.
- ✅ **Outbox swallow IS escalated** — `EscalateOutboxBroadcastSwallowed` wired in `EventServiceProvider:318` (Log::critical). *(Corrects an early orchestrator note based on a stale code comment.)*
- ✅ **Recovery crons all scheduled**; payload-contract validated pre-broadcast; double-tap idempotent (UUID key + middleware + DB UNIQUE).
- ✅ **WS degradation graceful** — activityTimeout 30s/pong 5s reconnect, subscription_error re-auth + SESSION_INVALID@3-fail/60s, proactive 2h token refresh, no-key→poll-only.

---

## 5. RUPTURE POINTS — triaged (verify-before-report applied)

### 5.1 — REAL, in-scope, HEALABLE (non-frozen)
| ID | Sev | Title | file:line | Repro | Heal |
|---|---|---|---|---|---|
| **F-W5-01** | **P1** | Encaissement **poll-only 20s, no Echo** — new borne order surfaces ≤20s late; after counter-collect on another screen it lingers ≤20s | `EncaissementComponent.vue:90-103` (no Echo; `setInterval(fetchPending,20000)`) | borne cash order → time-to-appear in /admin/encaissement | **Add Echo** sub on `branch.{id}` for OrderCreated + OrderPaidAtCounter + OrderStatusChanged → fetchPending (mirror KDS/tracker pattern). NON-FROZEN. |

> **F-W5-01 is an oversight, not by-design** (skepticism applied before healing). `EncaissementComponent` is a NEW unified page (`d60acdfe2 feat(caisse-unified W-ENC)`); its only comment calls the 20s poll a *"light poll … without a manual refresh"* — **no** deliberate Echo-omission rationale (unlike admin/dashboard poll-only, which carry explicit by-design comments). Every other operational surface has Echo; this new page conspicuously doesn't → align it. **Low impact**: the order is already live on KDS + POS tracker (Echo sub-second); /admin/encaissement is a secondary collection view, so the 20s lag affects only a cashier watching *that* screen for a freshly-arrived borne order.

### 5.2 — REAL but LOW (disclose; optional micro-heal)
| ID | Sev | Title | file:line | Note |
|---|---|---|---|---|
| W1-P1-001 | P2 | `OrderStatusChanged` dispatched **outside** tx (admin changeStatus) → narrow commit→dispatch crash window; inconsistent w/ OrderCreated (inside-tx) | `OrderService.php:2243` (tx closes 2231) | Poll recovers. Optional: wrap in tx / afterCommit for consistency. |
| W3-R5 | P2 | PREPARING→PREPARED idempotent-retry skips re-dispatch (WS-down + manual retry) → other station waits for poll | `PersistOrderStatusChangedToOutbox.php:27-33` | Poll recovers; needs owner-gate (outbox idempotency). |
| W3-R2 | P3 | WS push + poll both hit `/api/admin/kds-order` within 300ms debounce → 2 API calls (NO duplicate cards — version-gated) | `KitchenDisplaySystemComponent.vue:2409-2416` | Harmless; cosmetic load. |
| W6-R4 | P2 | Kiosk offline order abandoned after 10 retries, **no admin recovery endpoint** | `kioskOfflineQueue.js:565-586` | V1 single-box mostly-online; low likelihood. |
| W2-R3 | P3 | Corrupt JSON in `channel` col broadcasts `['string']` (not `[null]`) | `DispatchDomainEventsJob.php:100-105` | Requires DB corruption. |

### 5.3 — BY DESIGN (disclose, **do NOT heal** — confirm intent w/ owner)
- **W1-P0-001 (card/TR hidden until pay)** + **W5-PENDING-COUNTER (cash visible pre-pay)** — `FrontendOrderService.php:225-237` documented decision table: cash counter-deferred preps immediately (queue at counter), card/TR wait for TPE to avoid ghost orders. **Intentional Plan-B flow.** ⇒ owner confirm only.
- **W3-R1 / W4-P1-01 / W4-P1-02** — admin KDS + dashboard are **poll** (15–60s) by design; eventual-consistency lag vs chef sub-second push. Not a defect.

### 5.4 — MISLABELED by agents → corrected
- **W4-P1-03 / W4-P1-04** — agent called client-side **order-COUNT badges** ("active/ready" buckets, `PosComponent.vue:3104-3110`, `PosOrdersTracker:640`) an *"NF525 violation"*. **FALSE** — NF525 governs money/pricing SSOT; these are integer counts for a badge. Money totals are recompute-free. ⇒ **P3 cosmetic count-divergence**, NF525 label stripped.
- **W6-R1** — agent rated worker-death **P0 "kitchen blind"**. **FALSE** — poll reads orders table direct (recovers) + cron re-dispatches. ⇒ **P2 graceful degradation.**

### 5.5 — OUT OF STRICT "SYNC" SCOPE → flagged separately (deliverable #4; ask owner before widening)
- **W1-P0-002 / W1-P1-002 (fiscal)** — `fiscal_sequence_no` omitted from OrderCreated/OrderStatusChanged broadcast payload (`PersistOrderCreatedToOutbox.php:32-43`). **Not a sync defect** (KDS doesn't need fiscal seq to display). Possible fiscal/receipt-metadata concern → **owner decision**, not sync-scope heal.
- **W3-R6 (security)** — `branch.{id}` channel auth guest-role check is *latent-if-role-table-corrupt* (`channels.php:41-62`). Recently hardened (token-name + role check present). Speculative; **security scope** → disclose.
- **W6-R5 (frozen)** — `IdempotencyKeyMiddleware` fail_open on Redis-down race; DB UNIQUE backstops. **FROZEN §7** → owner-gate, disclose only.

### 5.6 — VOID / mitigated (dropped)
- W1-P1-004 (afterCommit "fires post-rollback") — contradicts `DispatchableAfterCommit` (discarded on rollback). VOID.
- W2-R1 / W2-R2 / W2-R6 — adversarial-verifier voided (swallow wired; 24h param not 7d; UNIQUE prevents double). 
- W6-R6 (post-login stale token) — already fixed via `_refreshEchoAuth(explicitToken)` (bootstrap.js:368, P-AUTH living-sync). 
- W1-P1-003 (dual OrderCreated cash) — idempotency absorbs to ONE broadcast; non-issue.

---

## 6. PRIORITIZED CORRECTION PLAN (deliverable #2)

| Prio | Item | Action | Frozen? | Gate |
|---|---|---|---|---|
| **P1-1** | F-W5-01 encaissement poll-only | Add Echo sub to `EncaissementComponent.vue` (OrderCreated+OrderPaidAtCounter+OrderStatusChanged → fetchPending); keep 20s poll as fallback | No | heal in Phase C-heal |
| P2-1 | W1-P1-001 status dispatch outside-tx | (optional) align to inside-tx/afterCommit | No (core OrderService) | assess; only if low-risk |
| — | By-design (5.3) + fiscal/security/frozen (5.5) | **No code change** — owner confirmation / separate scope | mixed | escalate (deliverable #4) |

**Healing target for this mission = F-W5-01 (single clear in-scope sync gap).** All other in-scope items are P2/P3 disclose. Live test (Phase C) confirms F-W5-01 + measures latency + validates by-design behaviors before/after heal; converge 2 green rounds (set-equality).

---

## 7. Phase C live-test plan (gated on drain) — latencies + captures land here

Login **chef@lecayenne.fr (branch_id=1)** for KDS/OSS latency. All orders `SYNC-E2E-` prefixed.
1. Inject **borne cash** order → measure WS latency to KDS (target ~130–500ms, flag >2s); verify appears KDS + OSS + tracker + **encaissement** (record the poll lag = F-W5-01 baseline).
2. Inject **POS** order → propagation + latency.
3. **PREPARING→PREPARED** on KDS → propagate OSS + tracker + kiosk-waiting; measure.
4. **Counter encaissement** of borne order → OrderPaidAtCounter → KDS/kiosk reflect paid; /admin/encaissement clears (measure pre- vs post-heal).
5. **Numeric integrity** — total string-equal across borne=caisse=KDS=OSS=tracker=encaissement + DB `orders.total`.
6. **Edges:** worker death (poll recovers?), WS cut (graceful?), double-tap (1 order), kiosk offline.
7. **Heal F-W5-01** → rebuild → re-run 1+4 → confirm encaissement now sub-second → 2 green rounds.

Cleanup `SYNC-E2E-` orders post-run. No push. Frozen/NF525 → escalate.

---
_Phase A complete 2026-06-03 ~04:40. Phase C pending drain. Watcher: monitor bcbtgzpbj._
