# AGENT 01 — SYNC CONTROLLER · Round 1 Report
**Date:** 2026-06-07 · **Scope:** AXE E — synchronisation temps-réel cross-surface (borne↔caisse↔KDS↔OSS↔dashboard)
**Harness:** DB `foodking_e2e` · server `http://127.0.0.1:8766` · soketi `:6001` · redis · single worker
**Verdict:** No product P0/P1. **blocking=false.** Live-push axes PARTIAL due to a **test-harness worker gap (W0 infra)** — NOT a product defect. Durability/idempotency/ordering/degradation invariants PROVEN.

---

## 0. INFRASTRUCTURE FINDING (W0 — to supervisor, NOT a product P0/P1)

**SYNC-INFRA-01 — No `foodking_e2e`-bound queue worker; the only worker is bound to the OPERATING DB `foodking`, and both envs share the same redis queue prefix.**

- Evidence:
  - Running worker pid 21046: `php artisan queue:work redis --queue=high`, **cwd = main checkout** `/…/testttt` whose `.env` has `DB_DATABASE=foodking` (operating). (`ps eww 21046`, `lsof -p 21046 | grep cwd`).
  - Redis prefix resolves identically for both envs: `le_cayenne_database_` (from `APP_NAME="Le Cayenne"`, `config/database.php:128`). `DispatchDomainEventsJob` enqueues `onQueue('high')` (`app/Jobs/DispatchDomainEventsJob.php:46`) → queue key `le_cayenne_database_queues:high` is SHARED between `foodking` and `foodking_e2e`.
  - Consequence: an outbox `DispatchDomainEventsJob` enqueued by the e2e server is raced for by the foodking-bound worker, which loads the `DomainEvent` by id from `foodking` (wrong DB) → e2e events sit `dispatched_at=NULL, attempts=0`. Snapshot: `foodking_e2e.domain_events` had ~68→103 pending with `attempts=0` while the foodking worker was up.
- **Why this is INFRA, not product:** In production, Le Cayenne is a single box — one DB (`foodking`), one worker, one `APP_NAME`. The collision is impossible there. Filing this as a product P0 would be the §3ter hallucination trap (right evidence, wrong subject).
- **Safety closed:** `DispatchDomainEventsJob` is **broadcast-only** — every `->save()` (lines 83/155/166/213) targets the `$domainEvent` model (`dispatched_at`/`attempts`/`last_error`) only; it writes NO orders/audit_logs/z_reports. So even if the foodking-bound worker processes an e2e job, it CANNOT corrupt the operating NF525 chain. The e2e **server** is correctly bound to `foodking_e2e`. Operating DB verified untouched (`foodking.orders WHERE queue_number LIKE 'BURST%'` = 0).
- **Recommendation (supervisor):** provision an isolated e2e worker with a distinct `REDIS_DB` (or `REDIS_PREFIX`) on BOTH the e2e server and a scoped `queue:work` so live WS-push validation (E1/E2/E3 real-time) becomes possible without racing the box worker. Until then live-push axes are PARTIAL (the producer/dispatch/soketi path is independently PROVEN — see E2 notes).

---

## 1. AXE E RESULTS

### E1 — Borne crée commande → apparaît file encaissement caisse + KDS + dashboard KPI — **PARTIAL** (data-path PASS; live caisse-list render + dashboard-KPI not driven)
- **Data path PROVEN:** the unified encaissement feed query (`routes/api.php:811-836`, `GET api/admin/pos/counter-collect/pending`) replicated exactly via tinker against `foodking_e2e` returns **200 rows, all 200 kiosk-origin** (queue A0082…), **FIFO by created_at** (oldest 2026-05-28). So a borne order with `payment_status=PENDING_COUNTER (15)` + `source_surface='kiosk'` deterministically surfaces in the caisse encaissement queue.
- **KDS visibility PROVEN:** KDS V2 screenshots (`3-kds-after.png`, `1-kds-soketi-blocked.png`) show borne orders A0003-A0006 on the kitchen board tagged **"EN ATTENTE ENCAISSEMENT"** (the PENDING_COUNTER state the caisse list consumes).
- **Not driven (the PARTIAL gap):** (1) the live caisse encaissement *list render* via the SPA — blocked by a **harness auth limitation** (raw `fetch`/`window.axios` in `page.evaluate` returned 401; the SPA uses Sanctum Bearer tokens in Vuex localStorage, `resources/js/shared/axios-setup.js:48-57`; this is a harness-token issue, NOT a product 401 — the route `abort_unless(can('pos'))` is correct authz). (2) **Dashboard-KPI propagation** of a new borne order was **not verified** — that is the agent-08 (ADMIN/Dashboard) lane and a passive ~60s poll by design. Spec: `tests/e2e/zz-sync-e1-borne-to-caisse-2026-06-07.spec.js`.

### E2 — Changement statut KDS → reflété OSS (+ tracker + dashboard) — **PASS (scoped to OSS, the in-V1 surface)**
- **Driven live, multi-context:** `tests/e2e/zz-sync-multicontext-2026-06-07.spec.js` (2 browser contexts). Chef advanced subject **A0005** on KDS (clicked `kds-card-cta-ready`) → OSS public wall went from absent to **"En préparation N°A0005"**. Latency **3573 ms**, **0 page errors** both surfaces. Screenshots `3-kds-after.png` (KDS card now "EN COURS"/"Prêt") + `4-oss-after.png` (A0005 on OSS wall).
- **Scope note (why PASS is the OSS surface only):** the axis lists OSS + tracker + dashboard. **OSS propagation = PROVEN live.** The **customer tracker** is the standalone web/app, **out of V1 backend wireup scope** (CLAUDE.md §3bis — mobile/web standalone, no API wireup). The **dashboard** is the agent-08 lane and refreshes by passive ~60s poll by design (SYNC_CONTRACT §5). So the only surface in this lane's scope (OSS) is fully proven → **scoped PASS.**
- **Precision (no over-claim):** the ~3.5 s latency ≈ OSS/poll cadence, NOT the contract's ~512 ms / ~6 ms WS path. Because the only worker is foodking-bound (SYNC-INFRA-01), the organic bump event sat pending and OSS picked the new status via its **poll**. The E2 checklist explicitly allows "< quelques s **ou polling**", so PASS stands on the polling path. The three real-time artifacts are separated in §2.
- Browser WS **connectivity** is healthy: both KDS and OSS reported `pusherState:"connected", isConnected:true`.

### E3 — Toggle stock admin (OOS) → propagé caisse + borne + wizard EN DIRECT — **PARTIAL** (mechanism driven; live UI disappearance not browser-driven)
- **Driven, not inferred:** primed kiosk menu cache `kiosk.menu.branch.1` with a sentinel → toggled item 2 (Frites Seules) `is_available=false` → fired `ItemAvailabilityChanged` → **cache was INVALIDATED** by `InvalidateKioskMenuCacheOnItemAvailabilityChanged` (`Cache::forget('kiosk.menu.branch.1')`, listener line 43/72) → next menu fetch returns fresh data sans OOS item. Item persisted to DB then restored.
- **Outbox row written:** `domain_events` id 8379, `ItemAvailabilityChanged`, `reason=stock_oos_e3test`, `item_id=2`, `channel=["private-branch.1"]` → propagates to kiosk/caisse/wizard via WS push (when worker drains) AND via cache-invalidation+poll.
- **PARTIAL:** the live item-vanishing in the kiosk/caisse browser UI was not driven (would require the next menu fetch render + the worker-gap-affected WS push). Cache invalidation guarantees correctness on next fetch regardless.

### E4 — ws:6001 down → polling fallback prend le relais, aucun event perdu — **PASS** (core invariant, driven live)
- **Driven:** `tests/e2e/zz-sync-degradation-2026-06-07.spec.js` blocks soketi via `page.routeWebSocket(/:6001\/app\//, ws=>ws.close({code:1006}))`. Result: WS genuinely DOWN (`isConnected:false, pusherState:"unavailable"`), **fallback banner VISIBLE** ("Mode secours actif — actualisation automatique toutes les 5s.", tag SYNC·LOCAL), **board STILL renders all orders via polling** (no blank/no loss), **0 page errors**. Screenshot `sync-degradation-2026-06-07/1-kds-soketi-blocked.png` shows banner + full board + "+4 en attente" overflow chip.
- **e2e-vs-box-local distinction (no conflation):** my live proof ran with `appEnv:"e2e"`, where the suppression gate `env==='local' && FK_KDS_SHOW_FALLBACK_BANNER===false` is bypassed (env≠local) so the banner shows. The **real box runs `local`**; its banner-visible behavior rests on a **code read** — on the box the flag is undefined → `'local' && (undefined===false)` → `'local' && false` → not suppressed → VISIBLE. The only suppressing combo is local+flag=false, which the box never sets. Combined (live-e2e proof + box-local code read) ⇒ PASS.
- **No-data-loss at the write layer:** advancing A0005 on KDS wrote `domain_events` id 8329 (`new_status=7`, qn A0005) with `dispatched_at=NULL` — the outbox row persists independent of any worker; a polling screen sees status=7 immediately, and `outbox:rescue` re-broadcasts later. Full lifecycle confirmed on id 8311 (`new_status=4`, dispatched_at set after manual `handle()`).
- **Stale-doc correction (real find):** SYNC_CONTRACT §7 + the old code comment claimed the KDS polling banner is "suppressed when APP_ENV=local" → kitchen blind on soketi death. **This was HEALED (commit PR-02, 2026-06-04):** the banner is now **fail-safe-to-visible** — suppressed ONLY if `appEnv==='local' && window.FK_KDS_SHOW_FALLBACK_BANNER===false` (opt-out, never opt-in); on the real box the flag is undefined → banner VISIBLE (`KitchenDisplaySystemComponent.vue:1343-1350`; V2 path `KdsStatusBanner.vue:101-108`). Residual: the `config/kds.php('show_fallback_banner')`→`master.blade.php`→`window.FK_*` wiring is *deferred* (documented at :1333-1337) — acceptable because the box default already satisfies the mandate.

### E5 — Pas de double-comptage, pas d'event fantôme, ordre correct — **PASS** (driven)
- **Anti-double-count PROVEN:** re-firing `OrderCreated` for the same 10 orders wrote **0 new outbox rows** — `PersistOrderCreatedToOutbox` keys on `sha1(ORDER_CREATED|order_id)` via `firstOrCreate` (listener line 23-25), and `domain_events.idempotency_key` is UNIQUE. No transition produces a duplicate event.
- **No phantom/malformed:** `SELECT COUNT(*) … WHERE new_status IS NULL OR order_id IS NULL` = **0** across all `OrderStatusChanged`.
- **Ordering coherent:** order 4203 transitions chain correctly `1→4` (18:24:07) then `4→7` (18:28:12) — each old_status = prior new_status; outbox `id` monotonic with `occurred_at`.
- **Contract validation:** **0** `contract_violation` (PayloadMismatch) rows in `foodking_e2e`; only historical error ever = a single `Pusher error: cURL error 7` (soketi-down past run) — the documented best-effort-broadcast graceful path (doesn't fail HTTP).

### E6 — Stress 10 commandes en rafale → toutes synchronisées, aucune perdue — **PASS** (driven, write-layer)
- **Driven:** created 10 NEW kiosk orders (ids 4215-4224, fresh uuid idempotency/token) and **burst-fired `OrderCreated` in 184 ms**. Result: **exactly 10 new `domain_events` rows, 0 lost** (delta=10); **10 distinct idempotency keys, 10 distinct aggregate_ids, all `OrderCreated`, all `channel=["private-branch.1"]`**; outbox ids 8368-8377 monotonic with aggregate_ids 4215-4224 (insertion order preserved). Test orders cleaned up after; operating DB never touched.

---

## 2. END-TO-END WS DELIVERY (independent proof of the dispatch→soketi→subscriber path)

To isolate the product path from the harness worker gap, I subscribed a real WS client to `private-branch.1` (manual Pusher auth: `HMAC-SHA256(socket_id:channel, app-secret)`, `/tmp/sync-ws-probe.js`), then triggered `DispatchDomainEventsJob->handle()` synchronously (bound to `foodking_e2e`):
- Subscriber **received `OrderStatusChanged`** with a well-formed envelope: `{version:1, type:order.status_changed, aggregate_id:4203, branch_id:1, correlation_id, payload:{order_id, new_status:4, old_status:1, queue_number:"A0005", payment_method, payment_status, payment_pending_counter}}`.
- The `domain_events` row flipped `dispatched_at→SET, attempts=1, last_error=NULL`.
- ⇒ **Producer → outbox write → DispatchDomainEventsJob → soketi → subscribed client is PRODUCT-CORRECT.** The only missing link in the harness is an isolated worker to trigger this organically (SYNC-INFRA-01).

---

## 3. SECONDARY OBSERVATIONS (P2/P3, not blocking)

- **SYNC-DOC-01 (P3, doc drift):** `SYNC_CONTRACT.md §4` references a `channels` (array) column and §7 the stale local-suppression behavior; actual schema is `channel` (singular varchar holding a JSON array string) and the banner is fail-safe-to-visible. Recommend refreshing the contract doc.
- **KDS bump locality (P3, documented design):** KDS banner states bump "Prêt" pastilles are per-browser ("ne se synchronisent pas entre plusieurs écrans KDS"). Acceptable for V1 single-box; flag for any future multi-KDS-screen setup.
- **OSS public wall polls (≤60s by design):** SYNC_CONTRACT §5 — the public wall does not subscribe (subscribeEcho early-returns for branchId≤0) and polls. Authenticated OSS used here connected via WS; the public-wall staleness is a known UX weak point, not a defect.

## 4. CROSS-DEVICE (hardware) — 🖥️ deferred
True 3-physical-machine sync (borne / caisse / KDS on separate devices) is part-hardware. All logic above is single-box-proven; on real hardware the only new variable is the LAN/printer node. Marked 🖥️ to confirm on the real setup.

---

## 5. ARTIFACTS
- Specs (authored/run): `tests/e2e/zz-sync-multicontext-2026-06-07.spec.js` (E2, pre-existing, driven), `tests/e2e/zz-sync-degradation-2026-06-07.spec.js` (E4, new), `tests/e2e/zz-sync-e1-borne-to-caisse-2026-06-07.spec.js` (E1, new).
- Screenshots: `tests/e2e/__screenshots__/sync-multicontext-2026-06-07/{1..4}-*.png`, `…/sync-degradation-2026-06-07/1-kds-soketi-blocked.png`.
- WS probe: `/tmp/sync-ws-probe.js` (received envelope logged).
- DB evidence: `foodking_e2e.domain_events` (ids 8311/8329/8368-8377/8379), feed query (200 kiosk FIFO rows).
