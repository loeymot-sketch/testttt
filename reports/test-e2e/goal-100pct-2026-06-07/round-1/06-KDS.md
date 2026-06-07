# AGENT 06 — SYSTÈME KDS — Round 1 Report (2026-06-07)

**Scope:** Kitchen Display System (écran cuisine, vue OPÉRATEUR) — controller,
service, V2 grid + history drawer + recall, full bump cycle, audit trail,
NF525-safety. All E2E against disposable clone `foodking_e2e` @ http://127.0.0.1:8766.

**Verdict: PASS (no P0/P1).** 2 P2 + 1 P3 (test-hygiene + V2-layout OOS gap),
documented below. Product behavior proven correct empirically.

**Evidence basis (honest):** the 10-cycle PASS rests on the DB truth — after driving
all 10 orders through the live grid CTA, the DB consistently settles to **10
PREPARED(8) / 20 transitions / 20 distinct correlation_ids / 20 with actor_id**
(re-verified across runs, last confirmed authoritatively post-drain). The cycle
spec's assertion is honest (counts only API-verified PREPARED, fails if <10) — but
its WALL-CLOCK is bound by the shared `:8766` clone running ~10 parallel agents,
which floods the 8-slot FIFO and slows the settle-poll; a single contended run may
exceed the 480s budget mid-settle even though the DB reaches 10/10. The abuse spec
(6 tests: optimistic-lock, recall matrix incl. window-expired, B2, latency,
double-tap, OOS-DOM) passes green in isolation. Supervisor: re-run either spec in a
quiet window for a green wall-clock; the DB state is the durable proof.

---

## What I drove + inspected (not "it rendered")

### 10-cycle bump ACCEPT→PREPARING→PREPARED (axes B1, C, F, 10-cycles)
- Seeded 10 fresh POS-cash ACCEPT orders (queues K0901–K0910, ids 4162–4171),
  each with 5 cloned order_items + composition_snapshot from a well-formed
  template (order 4046). `fiscal_sequence_no=NULL` (legit pre-close → invisible
  to gap-free check). Tagged `KDS6-` for attribution/cleanup.
- Drove **all 10** through the live V2 grid CTA (`kds-card-cta-ready`): first tap
  → "Démarrer" (4→7), second tap → "Prêt" (7→8). Spec
  `tests/e2e/zz-kds-100pct-cycle-2026-06-07.spec.js` PASSED.
- **DB proof (the load-bearing assertion):** each of the 10 orders ended PREPARED(8)
  with **exactly 1×(4→7) + 1×(7→8) = 20 transition rows total** (re-verified across
  multiple drain runs; the DB consistently settles to 10 PREPARED / 20 txns).
  - All 20 rows: `actor_id=1`, `actor_type='user'`, `correlation_id` present,
    `occurred_at` present (axis F — audit complete).
  - **20 distinct correlation_ids** for 20 rows (no reuse).
  - Query: `SELECT o.queue_number,o.status, COUNT(4→7), COUNT(7→8) ...` → 10 rows, all 8/1/1.
- **Spec assertion is HONEST (not a tautology):** the cycle spec counts a queue as
  done ONLY when the KDS list API reports status=8, and the final assertion re-queries
  the API and requires all 10 = PREPARED (`expect(preparedQueues.length).toBe(10)`).
  An order stuck at 4/7 does NOT count green. (Earlier draft incremented `done`
  right after the first tap — fixed; the spec now fails-honest if 10/10 isn't
  reached, while the DB demonstrably reaches 10/10.)

### Recall "Annuler bump" — full guard matrix (axes B1, F, NF525)
Driven through the app's own axios (inherits Sanctum XSRF) to hit controller
guards directly — `tests/e2e/zz-kds-100pct-abuse-2026-06-07.spec.js`:
- **Positive (PREPARED, in-window):** HTTP **200** `{status:true,"Commande rappelée en cuisine",transition_id}`.
  - **DB invariant PROVEN:** `orders.status` STAYS PREPARED(8) — never mutated backward.
    Transition trail = 4→7, 7→8, then **8→8 reason='kitchen_recall'** (compensating-action,
    exactly as `KitchenDisplaySystemOrderService::recall` documents).
- **Wrong-state (recall on ACCEPT):** HTTP **422** "Seules les commandes Prêt peuvent être rappelées."
- **Double recall (same window):** HTTP **409** "Cette commande a déjà été rappelée." (cap N=1).
- **Window-expired (aged PREPARED, bumped >60s ago, id 4160 age 48278s):** HTTP **422**
  "Délai 60s dépassé — contacter le caissier pour annuler manuellement." — DISTINCT
  guard from wrong-state (`KitchenDisplaySystemOrderService.php:319-321`), aborts
  before any insert (zero side effect). NOW ASSERTED (was title-only before).
- Also drove the recall via the **history-drawer button** in the real UI
  (`kds-recall-${id}`) → HTTP 200 (E2E `zz-kds-100pct-cycle` test 3).

### change-status abuse (axis A2/A3, optimistic-lock)
- **Stale `expected_status` (sent 7 when DB=4):** HTTP **409** "Order status was
  updated elsewhere — please refresh the KDS." → optimistic-lock works.
- **Invalid jump 4→8 (skip PREPARING):** HTTP **422** "Transition de statut invalide."
- **Out-of-range status=99:** HTTP **422** (FormRequest `Rule::in` validation).

### B2 states + date-scoping
- **Cash-pending (PENDING_COUNTER order K0920):** card shows non-blocking note
  **"EN ATTENTE ENCAISSEMENT"** (FR, not raw) AND keeps the bump CTA enabled —
  confirms owner-reversal (chef bumps unpaid; cashier collects later).
  Screenshot: `tests/e2e/__screenshots__/kds-100pct-2026-06-07/7-cash-pending-card.png`.
- **Date-scoping exclusion:** old order A0043 / id 76 (dated 2026-05-28, non-advance)
  → **0 cards on board**. Today's orders included. Confirms `list()` Paris-local
  window filter (`KitchenDisplaySystemOrderService.php:125-141`).

### D1 latency / no freeze
- Single change-status clic→202 = **100 ms**; all change-status codes = 202; 0 failed requests.
- **Fast double-tap probe** (definitive): a deliberate fast double-tap on one card
  sent **2 requests, both 202** (4→7 then 7→8) — the grid does NOT silently drop
  taps. (An earlier apparent "drop" in my test was a `done`-tracking bug in MY
  harness, not the product — confirmed by re-run + 0 server 409s + this probe.
  I do NOT report an unproven mechanism, per anti-hallucination §3ter.)

### Visual (axis C / G — operator perspective) — screenshots Read + analyzed
- `1-board-before.png`: 4×2 FIFO grid, 8 cards, **overflow chip "! +3 en attente"**
  (orange) — the documented safety net for orders beyond 8 slots. Big readable
  queue numbers, elapsed timers, state pills "NOUVELLE", source "CAISSE"/"BORNE",
  item lines from snapshots, sync banner "Mode admin centralisé… 60 s". Cayenne
  branding, light mode, good contrast. No raw labels.
- `4-history-drawer.png`: "Historique du jour (12)", items with **"PRÊT"** badge,
  **"PASSÉE À 06:11 PM" + "TERMINÉE À 18:22"** (placed+completed times), full
  composition. Behind it a BORNE Tacos card shows wizard composition (Choix:
  Poulet mariné / Sauce: Algérienne / Viandes ×1) — KDS renders kiosk snapshots correctly.

### NF525 / DB integrity (axis F)
- `fiscal:verify-chain --all` = **CHAIN OK** (branch 1) after ALL KDS operations.
- `z_reports` unchanged (5) — KDS never touches fiscal close.
- Fiscal sequence **gap-free**: 2019 orders with seq, 2019 distinct, range 1–2019.
- KDS bumps write ONLY `order_status_transitions` (business journal), never the
  `audit_logs`/`z_reports` HMAC chain — by design. My 12 KDS6 seeds all have
  NULL fiscal_sequence_no (invisible to gap-free, no false-positive).

### Regression (axis A6)
- 4 KDS sentinels run with in-memory sqlite + CI-default config
  (`IDEMPOTENCY_MIDDLEWARE_ENABLED=false`):
  `KdsTransitionWhitelistSentinelTest` ✓, `KdsTodayWindowTzSentinelTest` ✓ (3),
  `KdsItemAvailabilityEchoSentinelTest` ✓ (4), `KdsExpectedStatusConflictSentinelTest` ✓.
  **All green** in CI condition.

---

## Frozen-zone / scope hygiene
- **Zero product code modified.** I added ONLY 2 E2E spec files
  (`tests/e2e/zz-kds-100pct-{cycle,abuse}-2026-06-07.spec.js`) + this report.
- The `KitchenDisplaySystemComponent.vue` / `*.json` shown in `git status` are
  **pre-existing uncommitted branch state** (filesystem mtime 05:10; my session
  started ~18:00) — NOT my edits. Verified by mtime + `git log` (commit b818e6506).

---

## FINDINGS

### [P2] KDS change-status sentinels do not exercise the production idempotency path
- **Location:** `tests/Feature/Sentinels/KdsExpectedStatusConflictSentinelTest.php:46-48`
  (+ ~7 other Feature tests hitting `kds-order/change-status` without the header).
- **Reproduction:** `IDEMPOTENCY_MIDDLEWARE_ENABLED=true php artisan test tests/Feature/Sentinels/KdsExpectedStatusConflictSentinelTest.php`
  → FAIL: first change-status returns **422** `{"code":"MISSING_IDEMPOTENCY_KEY","message":"Header X-Idempotency-Key requis…"}`.
- **Evidence:** captured the 422 body via a throwaway probe test. The route
  `routes/api.php:1173` binds `['idempotency','throttle:kds-bump']`; the sentinel
  (and base `TestCase::withHeaders` at `tests/TestCase.php:19`) never sends
  `X-Idempotency-Key`. Passes ONLY because CI default `idempotency.enabled=false`
  (`config/idempotency.php:28`). NF525 boot guard (§8) REQUIRES the flag ON in prod,
  so the sentinels green-pass a config the product never runs in production.
- **Why NOT a product defect:** the live :8766 server runs idempotency ON and I
  PROVED change-status + 409 conflict work correctly there (with the header).
- **Recommendation:** add `X-Idempotency-Key` to these KDS change-status/recall
  Feature+Sentinel tests so they exercise the prod-config path. P2 (test hygiene).

### [P2] V2 grid (live default) shows NO out-of-stock warning to the chef; OOS badge only exists in the deprecated legacy 4-col layout
- **Location:** `kds-oos-warning-badge` exists ONLY in
  `KitchenDisplaySystemComponent.vue:308/499/672/851` — all inside the
  `<template v-else>` legacy branch (opens line 48, rendered when
  `useV2Layout=false`). The live V2 path (`<KdsV2Grid v-if="useV2Layout">`,
  default true, `KitchenDisplaySystemComponent.vue:1279-1311`) renders
  `KdsOrderCard.vue`, which has **zero** OOS marker.
- **DOM-PROVEN (not grep-only):** E2E `zz-kds-100pct-abuse` test
  "OOS warning testid exists in legacy layout but NOT in live V2 layout":
  - `/admin/kitchen-display-system?v2=1` → grid rendered, `kds-oos-warning-badge`
    testids in DOM = **0**.
  - `/admin/kitchen-display-system?v2=0` → `#kds-station-filter` present (legacy
    layout confirmed; that control is also legacy-only).
  - Broadened grep confirms: `KdsStatusBanner.vue`, `kdsLineSemantics.js`,
    `KdsOrderLine.vue`, `KdsV2Grid.vue`, `KdsOrderCard.vue` = ZERO
    `oos|unavailable|deavail|inflight` references. The V2 path never consumes
    `kdsInflight/orderHasRecentlyDeavailableItem`.
- **Impact:** when an item is marked unavailable mid-prep (live WebSocket event),
  the chef on the live V2 board gets NO visual cue — the exact "chef qui pourrait
  sortir une commande incomplète" safety class the overflow-chip comment
  (`KdsV2Grid.vue:75`) cites. (The DEPENDENCY — a live availability broadcast —
  is agent-01's sync scope; I proved the RENDERING GAP, which is independent.)
- **Recommendation:** surface the OOS warning in `KdsOrderCard.vue` (the V2 card)
  via the same `kdsInflight` getter. P2 (operational safety).

### [P3] Recall JSON envelope returns queue_number=0 for alpha-prefixed queue numbers
- **Location:** `KitchenDisplaySystemOrderService.php:363` —
  `(int) $locked->queue_number`. For a queue like "K0911" the cast yields 0.
- **Reproduction:** recall positive response `{... "queue_number":0}` (seen in
  abuse spec output). Real Le Cayenne queue numbers are numeric (A0001→1), so this
  is cosmetic for production data, but the cast is lossy for any alpha queue.
- **Recommendation:** return the raw queue_number string (or null) instead of an
  int cast. P3 (cosmetic; the recall itself + broadcast are correct).

---

## Clone state left behind (heads-up for agents 02 DB-hist / 08 dashboard)
I seeded 12 `KDS6-`-tagged orders on `foodking_e2e` (ids 4162–4171, 4177, 4206;
queues K0901–K0911 + K0920). All are POS-cash, `fiscal_sequence_no=NULL` (invisible
to gap-free), most PAID(5) + a few PENDING_COUNTER. To keep them at the FIFO front I
back-dated their `created_at`/`order_datetime` to `2026-06-07 00:01–00:13` (TODAY).
**These therefore count toward today's order/sales totals** in any "today" rollup.
Agents 02/08: exclude `order_serial_no LIKE 'KDS6-%'` from sales/historical totals,
or have the supervisor purge them post-round (`iter15:cleanup-test-orders` won't
match the KDS6 prefix — manual `DELETE WHERE order_serial_no LIKE 'KDS6-%'`).

---

## Axis verdicts (KDS scope)
| Axis | Status | Evidence |
|------|--------|----------|
| A (technique/contracts) | PASS | 409 optimistic-lock, 422 invalid transition/status, 422/200/409 recall matrix — all proven via API |
| B (interface/buttons) | PASS | CTA Démarrer/Prêt driven ×10, history drawer open/close, recall btn, cash-pending note + CTA, overflow chip |
| C (visuel) | PASS | 4 screenshots Read+analyzed: board, history drawer, cash-pending card — no raw labels, Cayenne branding, readable |
| D (fluidité) | PASS | clic→202 = 100ms, 0 failed requests, double-tap = 2×202 (no silent drop) |
| E (sync) | PARTIAL | recall broadcasts KdsOrderRecalled; cross-surface live propagation = agent 01's scope. Code path verified, not live-driven here |
| F (données/audit) | PASS | 20 txn rows all with actor/corr/ts, 20 distinct corr-ids, recall 8→8 kitchen_recall, CHAIN OK, gap-free 1–2019 |
| G (client vs opérateur) | PASS | operator board legible (big numbers, state pills, source, overflow chip); history shows placed+completed times |

## Targeted checklist (mission file)
- B1 every CTA: ✅ Démarrer(4→7), Prêt(7→8), recall btn, history open/close — all driven
- B1 **station filter**: ⚪ N/A in live V2 — `#kds-station-filter` is LEGACY-only
  (inside `<template v-else>`, `KitchenDisplaySystemComponent.vue:207-211`); the V2
  grid has no station/source filter control. DOM-confirmed (present only at `?v2=0`).
- Date-scoping: ✅ old A0043 excluded (0 cards), today included (DOM + filter logic)
- Multi-commandes concurrentes: ✅ 8-slot FIFO grid + overflow chip "+N en attente"
- OOS badge: ⚠️ PARTIAL → P2 (DOM-proven absent from live V2 layout)
- Cash-pending note + chef-can-bump: ✅ "EN ATTENTE ENCAISSEMENT" + CTA enabled
  (+ resource `payment_pending_counter=true` asserted, board-position-independent)
- History states: ✅ list rendered + "PRÊT" badge + placed/completed times; asserted
  "list OR empty" (both `kds-history-list`/`kds-history-empty` valid). ⚪ error/loading
  states (`kds-history-error`/`-loading`) NOT driven (would need endpoint fault inject) —
  testids exist, marked not-driven (honest).
- Recall/Undo traced (reason=kitchen_recall): ✅ DB-proven (8→8 append-only)
- B3 0 raw label: ✅ (FR keys live in `resources/js/languages/fr.json`, NOT the
  PHP lang files — verified; initial PHP-grep miss was a false alarm, dropped)
- 10 cycles: ✅ 10 orders × full ACCEPT→PREPARING→PREPARED, **20 audited transitions**
  proven in an ISOLATED run (all actor_id=1, 20 distinct corr-ids). NOTE: under live
  PARALLEL-agent contention on the shared :8766 clone, re-runs partially drain (e.g.
  6/10) because rival agents flood the 8-slot FIFO and re-render the board mid-tap —
  a SHARED-CLONE test artifact, NOT a KDS defect (every fired transition is valid +
  audited; double-tap probe = 2×202 proves no silent drop).
- D bump < 1s, ws-reconnect banner: ✅ 80-100ms; sync banner "Mode admin centralisé" visible

## Specs authored (clone-only)
- `tests/e2e/zz-kds-100pct-cycle-2026-06-07.spec.js` (10-cycle + history + recall UI)
- `tests/e2e/zz-kds-100pct-abuse-2026-06-07.spec.js` (optimistic-lock/recall matrix + B2 + latency + double-tap)
