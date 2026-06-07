# Agent 07 — SYSTÈME OSS (Order Status Screen / customer wall) — Round 1
**Date:** 2026-06-07 · **Surface:** `/admin/order-status-screen` (public customer wall) · **Tolérance:** ZÉRO
**DB:** foodking_e2e (disposable clone) · **Server:** http://127.0.0.1:8766 · **Verdict:** PASS (non-blocking)

---

## Scope validated
The customer-facing order-status wall (lobby TV). Two columns "En préparation" (`#B0004D`) | "Prêt"
(`#1AB759`), large queue numbers `N°A####`, real-time transitions via 5s polling fallback (the public
wall is structurally poll-only: `subscribeEcho()` early-returns for `branchId<=0`).

**Anchors audited (read in full):**
- `app/Http/Controllers/Admin/OrderStatusScreenController.php` (index / publicIndex / popular)
- `app/Services/OrderStatusScreenOrderService.php` (`list()` :37 / `listForBranch()` :206 — the SSOT query)
- `resources/js/components/admin/orderStatusScreen/{OrderStatusScreenComponent,PreparingAndReadyComponent}.vue`
- `resources/js/services/OssSyncService.js` (poll cadence + degradation)
- `resources/js/store/modules/orderStatusScreenOrder.js` (auth-branch URL selection)
- `resources/js/router/index.js:218-254` (public-friendly auth bypass)
- `app/Http/Resources/{CDSOrderDetailsResource,PosShortcutOrderResource}.php`
- `routes/api.php:1216-1293` (admin + public OSS routes)

---

## Method (how PASS was earned — drove + inspected + tried to break)
New spec `tests/e2e/goal-100pct-oss-2026-06-07.spec.js` (9 cases, serial, 1 worker). It DRIVES real DB
state on the clone via `php artisan tinker` (APP_ENV=e2e), hits the **real** public endpoint (no mocking,
unlike the prior `goal-functional-oss-*` specs which mocked the API), and inspects the real Vue render +
real 5s polling. Pre-existing OSS-eligible live orders neutralized (status→DELIVERED) at suite start and
restored at end. All seeds scoped `OSS-A07-%` and `forceDelete`d after.

**Transition seam (honest):** transitions driven at the **data-source layer** (`orders.status` UPDATE) —
this is exactly what the wall reads through `OrderStatusScreenOrderService`, so it isolates the OSS surface
and proves what this agent owns. The end-to-end **KDS-bump→OSS** and **encaissement→PREPARING** UI chains
are owned/validated by agents 06 and 01. The flash/pulse cue I captured fired via the **poll path**
(`_hydrateFromRows` new-ready detection), NOT Echo — which is the correct realistic behavior for the
public wall (branchId≤0, no channel subscription).

---

## Results — 9/9 E2E green + 17 PHPUnit + 31 Vitest green, console clean, 0 NF525 residue

| Test | What it drove | Result |
|------|---------------|--------|
| T-00 | Public wall reachable UNAUTH (no /login bounce), FR headers, no raw label | PASS — renders both columns "En préparation"/"Prêt", no `label.x` |
| T-01 | Empty state both columns | PASS — both show `—` placeholder, no broken layout (screenshot analysed) |
| T-02 | Mixed PREPARING/PREPARED + FIFO | PASS — `[A9001,A9002,A9003]` / `[A9004,A9005]` exact string-FIFO order |
| T-03 | Live PREPARING→PREPARED transition + flash | PASS — moved in **≤5136ms** (incl. ~1-2s tinker harness overhead; true poll-to-render lower), `oss-ready-flash` + `oss-pulse-ready` both applied |
| T-04 | DELIVERED auto-removal | PASS — A9020 left the wall within poll cadence, A9021 remained |
| T-05 | 10 orders multi-column + autoscroll + no-dup | PASS — `oss-autoscroll` engaged (>8), 5/5 split, `Set(all).size===10` no order in both columns |
| T-06 | KIOSK order with NULL queue_number | PASS-as-probe — raw `token` leaks to wall (see Finding F-OSS-01, P3) |
| T-07 | Stale prune (>8h) | PASS — 9h-old order pruned, fresh order present (config `oss.stale_window_hours=8`) |
| T-08 | Public API JSON contract + PII-free | PASS — `content-type: application/json`, exactly 6 keys `[id, order_serial_no, order_type, queue_number, status, token]`, no name/phone/total |

**Supporting suites (run, not assumed):**
- Vitest 31/31: `ossChimePublicWall` (7), `ossWakeLockOnMount` (6), `orderStatusScreenOssSync` (1), `ossFullscreenNoUndefinedRefSentinel` (2), `posOssCadenceCap` (11), `ossSyncFallback` (4).
- PHPUnit 17/17 (on dedicated `foodking_test`, RefreshDatabase-safe): `OssCustomerScreenFilterTest` (8 — DELIVERY/POS/DINING excluded, KIOSK/TAKEAWAY included, PENDING/ACCEPT hidden, DELIVERED removed), `OssPolishClusterTest` (4 — stale prune + branch-scoped popular), `OssPublicNoPiiTest` (1), `OSSReadOnlyTest` (1 — OSS is strictly read-only), `OssAdminBranchPolicyTest` (2), `OssAdminBranchPolicySentinelTest` (1 — branch staff cannot request global scope).
- Console: 0 errors over an 8s wall session (TOTAL=0, including noise).
- **NF525 chain `fiscal:verify-chain --all` → CHAIN OK**; 0 A07 orders held a `fiscal_sequence_no`; `max_seq=2019`/`cnt=2019` gap-free; audit_logs untouched (2760). My raw create/forceDelete bypassed `OrderService` allocation → zero fiscal residue for agents 02/09.
- Data restored: 0 A07 leftovers, live order 4161 restored to status 8, live endpoint sane.

---

## Visual analysis (screenshots Read, mandatory)
`reports/test-e2e/goal-100pct-2026-06-07/round-1/oss-shots/*.png`
- **t02-mixed-fifo**: 2 clean columns, magenta/green headers, huge bold `N°A####` (text-[56px]), FIFO correct, no overflow, readable from distance.
- **t01-both-empty**: `—` placeholder both columns (subtle but present), no debris.
- **t03b-after-transition**: A9010 moved to PRÊT showing green halo pulse + whole-column light-green flash tint; PREPARING now `—`. The client clearly sees their number light up green when ready. **C3 client-perspective: clear and instant.**
- **t05b-ten-split**: 5/5 split, no duplicate across columns, no clipping.

**Palette note (NOT a finding):** OSS uses semantic `#B0004D`/`#1AB759`, NOT brand `#F4501E`. This is an
owner-attested exception (Wave Q-3/S-3 directives in component header). Contrast/readability good; judged PASS.

---

## Findings (all real, with file:line + reproduction + evidence)

### F-OSS-01 [P3] Raw `token` string can leak onto the customer wall when queue_number is NULL
- **Location:** `resources/js/components/admin/orderStatusScreen/PreparingAndReadyComponent.vue:37,62` — display is `item.queue_number ? 'N°'+item.queue_number : item.token`.
- **Reproduction:** seed a KIOSK (type 25) order, `queue_number=NULL`, `token='RAW-TOKEN-LEAK-XYZ'`, status PREPARING → wall renders the raw token string (T-06, evidence `t06-state.json` / `t06-raw-token.png`).
- **Severity rationale (downgraded P1→P3):** traced the real kiosk create path — `FrontendOrderService.php:504` always routes through `saveFrontendOrderWithQueueNumber()` → `allocateQueueNumber()` (`:984`), so a real KIOSK/TAKEAWAY order reaching PREPARING/PREPARED ALWAYS has a zero-padded `A####` queue. TAKEAWAY without queue is excluded by the service filter (`:50 whereNotNull('queue_number')`). The leak is therefore **not reachable via the normal V1 flow** — defensive/cosmetic only.
- **Recommendation:** harden the display fallback to a neutral label (e.g. `'N°—'` or `order_serial_no`) instead of raw `token`, so a malformed order never paints an internal token on a public TV. Non-blocking.

### F-OSS-02 [P3] FIFO is a string sort — non-`A####` or 5-digit rollover would mis-order
- **Location:** `OrderStatusScreenOrderService.php:144,264` — `orderBy('queue_number','asc')`.
- **Evidence:** correct for uniform zero-padded `A0001..A9999` (proven T-02/T-05). Boundary: legacy fixtures `901`/`903` (no A-prefix) sort before `A0001`; `A10000` sorts before `A9999`.
- **Severity rationale:** real allocator always emits zero-padded `A####` 4-digit (verified `OrderService::allocateQueueNumber`), so neither boundary is V1-reachable. Note only.

No P0/P1 found. **Not blocking.**

---

## Residuals / coverage gaps (transparent)
- **Dynamic brand-new-arrival not separately asserted:** I proved status-*change* transitions (T-03/04/05) and initial multi-order render, but did not seed a brand-new PREPARING order *while the wall was open* (OrderCreated→poll→appear). Same full-list re-fetch mechanism as T-03 → low risk; one-assertion gap for airtight E-axis.
- **Latency figure is conservative:** `t0` set before `setStatus()`, and `execFileSync` blocks for full tinker bootstrap, so 5136ms includes ~1-2s harness overhead. Reported as upper bound; flake risk if tinker spikes (a future >8s breach would be harness, not regression).
- **WS-down vs WS-up cadence:** public wall is poll-only by design (validated). Cross-surface WS-degradation (ws:6001 down→polling, SYNC-WS-01) for the *authed* branch-staff OSS is agent 01's scope.
