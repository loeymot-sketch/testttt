# CONVERGENCE FINAL — Synchronisation Borne ↔ Caisse ↔ KDS ↔ OSS ↔ Tracker ↔ Encaissement
**V1 LOCAL Le Cayenne — 2026-06-03 · Branch `heal/cms-pr1-quickwins-2026-05-18`**

## VERDICT: ✅ GREEN — converged, 2 consecutive green rounds (set-equality)

Both in-scope sync defects healed and live-validated; the sync backbone is otherwise
robust. **0 frozen-zone touched · NF525 CHAIN OK · no push.**

Commits: `f2bb80e88` (Phase A audit), heal-plan checkpoint, **`b14bc6036`** (the 2 heals).

---

## 1. What was validated LIVE (chef@lecayenne.fr branch_id=1 + pos@lecayenne.fr)

| Claim | Result | Evidence |
|---|---|---|
| Borne order → KDS real-time | ✅ | A0009/A0010 rendered on chef KDS board (`captures/sync-r0-01-kds-chef-board.png`), `OrderCreated @ private-branch.1` dispatched |
| **Caisse** order → KDS real-time | ✅ | source=15 orders (A0003/A0006/A0008) broadcast `OrderCreated @ private-branch.1 disp=Y` — same pipeline |
| Chef subscribes `private-branch.1` | ✅ | `subscribed:true` first try (living-sync token fix holds) |
| Status PREPARING→PREPARED propagates | ✅ | 4→7→8 via change-status (HTTP 202, `expected_status` TOCTOU guard), OrderStatusChanged received on branch.1 |
| Counter-deferred state on KDS | ✅ | ACCEPT+PENDING_COUNTER orders show "EN ATTENTE ENCAISSEMENT" (intended Plan-B design) |
| Numeric integrity (money) | ✅ | borne total €1,50 = kiosk = DB `orders.total` = encaissement; no client-side total recompute (only count badges) |
| Encaissement queue accessibility | ✅ | borne PENDING_COUNTER orders present in `counter-collect/pending` data |
| PENDING→ACCEPT linchpin (zéro-perte) | ✅ | status=ACCEPT synchronous in create path → poll recovers on worker death (no loss) |
| Idempotency keys on submit | ✅ | X-Idempotency-Key on kiosk payment-confirm + offline replay |

## 2. Latency measurements (precise, browser WS-receipt timestamping)

| Scenario | Before heal | After heal (block_for=5) |
|---|---|---|
| Borne→KDS broadcast, **cold** worker (idle queue) | **2292 ms** (A0010) — ⚠ >2s flag | **269 ms** (A0011) ✅ 130–500ms band |
| Borne→KDS broadcast, **warm** worker | ~900–1500 ms | **WS push arrived BEFORE the HTTP store-response** (sub-50ms) |
| Status-change propagation (warm) | 916–1496 ms | (block_for makes pickup instant) |
| **Encaissement** new-order surfacing | **poll-only ≤20 s** (no Echo) | **~1.2–1.5 s** Echo-triggered refetch ✅ |

## 3. HEALS applied (both non-frozen, live-validated, committed `b14bc6036`)

### F-W5-01 (P1) — Encaissement realtime
`EncaissementComponent.vue` was poll-only 20s / no Echo (a *new* unified page oversight,
not by-design). Added canonical Echo subscription (`onEvents` mirroring OSS/KDS/tracker)
on `private-branch.{id}` for OrderCreated/OrderPaidAtCounter/OrderStatusChanged → `fetchPending`;
20s poll kept as WS-down fallback. Added robust `authBranchId()` (auth module is **not
namespaced** — first patch used the wrong getter path and resolved branch=0; caught + fixed
by the heal loop). **Before:** `channels:[]`. **After:** `private-branch.1 subscribed:true`,
refetch ~1.2s. Visual: `captures/sync-r1-02-encaissement-healed-pos.png` (renders clean).

### F-LAT-01 (P2) — Broadcast latency from idle worker
`config/queue.php` redis `block_for: null → env('REDIS_BLOCK_FOR', 5)`. With null, the
worker polled with `--sleep` (default 3s) when idle → first broadcast after a quiet gap
waited ~1–3s. Blocking pop (BRPOP) → instant pickup. **2292ms → 269ms** cold. High-lane
worker restarted live to apply.

## 4. Findings triaged NOT healed (verify-before-report §3ter; see AUDIT.md §5)

- **By-design (owner-confirm only):** kiosk card/TR hidden until pay + cash visible-before-pay
  (intentional Plan-B counter-deferred, `FrontendOrderService:225-237`); admin/dashboard poll lag.
- **Corrected agent over-calls:** W4 "NF525 violation" = order-count badges (P3, not money);
  worker-death = P2 degradation not P0 (poll reads orders table — confirmed live).
- **Out-of-scope (deliverable #4 — ask owner before widening):**
  - Fiscal: `fiscal_sequence_no` omitted from broadcast payload (not a sync defect; KDS doesn't need it).
  - Security: `branch.{id}` channel-auth guest-role latent-if-role-table-corrupt (`channels.php`).
  - Frozen §7: `IdempotencyKeyMiddleware` fail_open on Redis-down race (DB UNIQUE backstops).

## 5. NEW live observations (flagged, not healed — owner decision)

1. **Encaissement `counter-collect/pending` 200-row cap** — under abnormal pollution
   (>200 PENDING_COUNTER, left by the abuse-e2e soak), newly-arrived borne orders fall
   outside the cap and don't list. **Test-artifact-triggered** (a real restaurant never carries
   200+ uncollected counter orders); latent truncation behavior worth a `LIMIT`+ordering review.
2. **Default-lane queue worker** still runs with the old `block_for=null` in memory — only the
   **high lane** (broadcast-critical) was restarted live. Next worker restart / deploy picks up
   `block_for=5` everywhere. (Default lane = notifications, not latency-critical.)
3. **`CleanupStalePendingKioskOrders`** rejects (status→REJECTED) kiosk orders that are still
   `status=PENDING` after **15 min**. Auto-accepted counter-deferred orders are ACCEPT (not
   matched), so V1 is safe; flagged for owner awareness if any borne order can linger in PENDING.

## 6. Environment / hygiene notes

- **Shared-DB volatility:** my 5 `SYNC-E2E` test orders (ids 4119–4123) were hard-deleted by the
  other session's test-cleanup/restore mid-run (DB max id reverted to 4118 @ 04:49). This is the
  bidirectional contamination flagged at Step 0 — it does **not** invalidate results (sync was
  measured correctly while orders existed). No manual cleanup needed (orders gone).
- Browser HTTP cache was disabled via CDP for the test (session-only; resets on browser close).
- Test tokens (`kiosk-token`, `sync-e2e-admin`) — 480-min TTL, will expire; revoke query matched 0
  (harmless).
- Bundles (`public/js/*`, tracked-but-gitignored) rebuilt locally; **must `npm run prod` on deploy**.

## 7. Evidence index

- `AUDIT.md` — architecture, channel/outbox map, triaged P0/P1/P2, prioritized plan.
- `HEAL_PLAN.md` — heal design + Phase C choreography.
- `static-audit/workflow-raw-findings.json` — 12-agent adversarial workflow raw output.
- `captures/sync-r0-01-kds-chef-board.png` — borne A0009/A0010 live on chef KDS.
- `captures/sync-r1-02-encaissement-healed-pos.png` — healed encaissement (Echo active).
- Gates: NF525 `fiscal:verify-chain --all` = CHAIN OK · outbox sentinels 17 passed/2 skipped ·
  frozen-zone diff = 0 · `git diff --stat` = 2 files (config/queue.php + EncaissementComponent.vue).

_Converged 2026-06-03 ~06:00. No push. Owner gates: §4 out-of-scope items + §5 observations._
