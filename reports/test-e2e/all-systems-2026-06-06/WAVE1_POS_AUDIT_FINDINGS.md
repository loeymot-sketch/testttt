# Wave-1 POS Read-Only Audit — Confirmed Findings (feeds the all-systems E2E GOAL)
**2026-06-06 · workflow `wfa1r9zqm` (34 agents, ~2.6M tokens, ~24 min) · adversarial refute-by-default.**
Source-of-truth feeder for the CAISSE/KDS/CENTRAL/SHARED units' "known-weak" lines in `plans/GOAL_E2E_ALL_SYSTEMS_FINISH_2026-06-06.md`. All findings re-read file:line by an adversarial verifier; refute-by-default on mature code (2969 tests green).

## Register — P0=0 · P1=1 · P2=10 · P3=9 (20 confirmed) · 8 refuted
> ⚠️ **KDS dimension agent FAILED (stream idle timeout)** — the dedicated KDS UI audit did NOT complete. The SYNC agent partially covered KDS *sync* (KDS-01/02/03). The GOAL's K1–K4 units must NOT assume prior audit coverage of KDS UI/bump/routing.

### P1 (1)
- **OFF-02** `PosComponent.vue:3922` (non-frozen, HIGH): offline enqueue does NOT clear the cart → re-click enqueues a 2nd copy with a fresh idempotency key → **double order + double cash line on replay**. Fix: after successful `enqueueOrder` offline, reset cart/form mirroring the online success path (:3725-3735). → **GOAL U-S3 + U-C2**.

### P2 (10)
- **OFF-01** `PosComponent.vue:3910` (HIGH): enqueue only fires on `navigator.onLine===false` — server-unreachable-while-interface-up **loses the sale** instead of queueing. Fix: treat transport/5xx/timeout as offline signal. → U-S3.
- **OFF-03** `usePosOfflineState.js:71` (HIGH): auto-flush (online event + 30s timer) silently swallows sync results — cashier never told a replay failed. Fix: surface notice when `result.failed>0`. → U-S3.
- **DASH-04** `SlaAlertsComponent.vue:52-58` + `StockLowAlertsWidget.vue:88-98` (HIGH): SLA/low-stock widgets render a reassuring 'all good' state on API failure (false-negative). Fix: `.catch` + error flag → 'Données indisponibles'. → U-CE1.
- **CASH-01** `RefundWithCounterEntryService.php:280-289` + `PaymentService.php:620-627` + `CashOverviewController.php:308-322` (HIGH): cash-OUT skips (refund/cashback, no open session) undetectable — overstate expected cash, invisible to EOD detector; claimed 'cron alert' mitigation does not exist. Fix: durable skipped-OUT marker + extend `summarizeUnrecordedCash`. → U-C3.
- **POS-ERG-01** `PosComponent.vue:75 + :505` (HIGH): product grid right column occluded by fixed cart panel at 1024×768 (15/45 tiles partially hidden). Fix: align main-width tokens to cart footprint per breakpoint. → U-C1.
- **POS-ERG-02** `pos-v5.css:66-79` + `PosComponent.vue:76-247` (HIGH): operator-bar brand/title column collapses to 0px; action nav overlaps title when kiosk-cash button present. Fix: constrain nav track / allow wrap. → U-C1.
- **POS-ERG-03** `PosComponent.vue:2093-2124` → `appService.js:71-77` (HIGH): cart totals + grand-total + pay CTA render en-US `0.90€` while catalogue shows FR `0,90 €`. Fix: POS-local FR formatter (do NOT edit shared appService). → U-C2.
- **POS-ERG-04** `PosComponent.vue:840-851` + `PosV5QtyStepper.vue:199` (HIGH): in-cart qty stepper buttons 22×22px — far below 44px, hit on every multi-qty order. Fix: size md / raise dims. → U-C1.
- **KDS-02** `KdsSyncService.js:372-394` (HIGH): KDS fallback poller permanently HALTS on any non-5xx (401/403/404/429) — kitchen silently loses its degradation safety-net. Fix: reschedule on ALL handled error classes. → U-K1/U-S2.
- **KDS-03** `KitchenDisplaySystemComponent.vue:1911-1914` (HIGH): KDS under admin account (branch_id≤0) silently receives ZERO live push (subscribeEcho early-returns) and the fallback banner stays hidden (WS 'connected'). Fix: require branch station account OR explicit central-viewer banner. → U-K1/U-K4 + owner ops-note.

### P3 (9)
- **DASH-01** `OverviewComponent.vue:59-63` + ChannelStats/OrderStatistics/SalesSummary: fetch-once-on-mount, no auto-refresh/ws — stale until reload (siblings DO poll 15-30s). Fix: add setInterval mirror. → U-CE1.
- **DASH-03** `CustomerStatsComponent.vue` + `DashboardComponent.vue:67-99`: orphaned components (backend computes, nothing renders). Fix: mount or delete. → U-CE1.
- **DASH-05** `DashboardService.php:440-465`: SLA 'cuisine >15min' uses `updated_at` proxy for 'entered PREPARING' — later writes reset the clock, hiding late tickets. Fix: real transition time. → U-CE1.
- **DASH-06** `DashboardService.php:383-391`: 'Total articles menu' counts inactive/unpublished items — can drift from 45-item SSOT. Fix: align semantic (`status=ACTIVE` or relabel). → U-CE1/U-CE2.
- **CASH-03** `CashDrawerController.php:46-65`: no-sale hardware drawer pop with no open session writes only `Log::warning`, not a durable `audit_logs` row — breaks 'Action tracée' on real hardware. Fix: session-less audit_logs row. → U-C3.
- **POS-ERG-05** `pos-v5.css:778-797` + `PosComponent.vue:866-915`: order-type segmented items 40px, discount controls 34-36px — under 44px. Fix: ≥44px. → U-C1.
- **POS-ERG-07** `pos-wizard.js:218-220` (**FROZEN, frozen_zone_touch=TRUE**): wizard renders en-US `€8.50 / +€2.50` alongside FR formule prices. **FLAG-ONLY — owner gate (G-FROZEN), no edit proposed.** → U-C1 frozen-note.
- **KDS-01** `KdsSyncService.js:298,:241-243`: adaptive-cadence poller reads non-existent `wsService.state` (wrong field) → WS-aware cadence ladder is dead code. Fix: use `getState?.()`. → U-S2.
- **DOC-01** `SYNC_CONTRACT.md §5/§7`: stale vs shipped — OSS wall now 5s (not ~60s), KDS banner now fail-safe-to-visible (not suppressed in local). Fix: update doc. → U-K4/U-S2.

## Refuted by adversarial pass (8 — kept out of the register; recorded for honesty)
- **DASH-02** low-stock widget unmounted — refuted (covered by DASH-04 / impact reframed).
- **OFF-04** queue never purges → post-24h-TTL re-execute — refuted (`purgeExpired` dead but TTL replay path not reachable as framed).
- **CASH-02** no per-operator PIN/re-auth — refuted as **NOT a V1 defect** (single-operator envelope; `permission:pos` gates). ⚠️ **BUT owner Mission-2 brief explicitly wants PIN+rôles → this is an owner FEATURE-SCOPE item, not a code defect.** → GOAL U-C3 + owner gate.
- **CASH-04** per-user (not per-drawer) UNIQUE open-session — refuted (intended documented behavior).
- **CASH-05** `cash_movements` has no actor column — refuted (actor captured via session + HMAC chain).
- **POS-ERG-06** two adjacent ↻ cart actions same glyph — refuted (evidence materially false on re-read).
- **POS-ERG-08** frozen wizard viande steppers 26px — refuted (misstated standard; active renderer differs).
- **SYNC/DASH-01 dup** — refuted as duplicate of confirmed DASH-01.

## Validated strengths (what the E2E agents must NOT regress)
**Dashboard:** BranchScope on aggregates sound (admin→all, staff→own; AuditTrail manually branch-filtered as BranchScope-exempt); realized-revenue nets refunds to ~0; Paris-day [start,endExclusive) uniform; refund mirrors excluded from PLACED counts; AuditTrail reads NF525 hash-chained AuditLog (GAP-FIX-02); empty/i18n coherent.
**Offline:** lost-response idempotent replay correct (stable X-Idempotency-Key → middleware cached 2xx); IndexedDB persists across reload + localStorage Safari-ITP fallback; PCI/PII stripped on enqueue; MAX_ENTRIES=50 reject-new preserves oldest cash sales; concurrent-flush guard; visible degraded banner + queue-depth badge; offline CASH defaults received=total.
**Cash:** openSession TOCTOU 3-tier (Cache::lock + lockForUpdate + UNIQUE partial index); recordMovement re-checks OPEN under lock; variance gate permission-bound fail-closed; ownership gate (cashier B can't close A's drawer); IN-path gap durable+detectable; reconcile == CashOverview formula.
**POS-UX:** dine-in correctly OFF (no 'sur place'/floorplan leak); 45-item SSOT renders; category pills 96px/56px; i18n fully resolved (0 raw keys); discount requires 3+ char reason client+server; console clean.
**Sync:** POS/kiosk→KDS(branch 1) real-time via OrderCreated/StatusChanged/PaidAtCounter; status-change JS-filtered to KDS transitions; best-effort broadcast non-blocking (afterCommit+try/catch); WS-reconnect self-heals (refreshOrderList+restartPolling); 2xx POST interceptor self-heals board; OSS public wall healed 60s→5s; KDS fallback banner fail-safe-to-visible; outbox dedupe by correlation_id.
