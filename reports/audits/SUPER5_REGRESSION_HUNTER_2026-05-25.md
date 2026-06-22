# Super.5 — Regression Hunter Cross-System · 2026-05-25

**Agent**: SUPERVISOR AGENT #5 — REGRESSION HUNTER CROSS-SYSTEM
**Branch**: `heal/cms-pr1-quickwins-2026-05-18` HEAD `af92035b8`
**Heal range scanned**: `d601fdd34..af92035b8`
**Mode**: READ-ONLY · Static analysis + sentinel sweep · NO E2E browser drive
**Owner mandate honored**: explicit "READ-ONLY + UNVERIFIABLE acceptable"

---

## 1 · Heals analyzed

**Bulk diff**: 624 files / 118 384 insertions / 550 deletions across the 65+
commit range. Filtered to `app/ resources/ routes/ database/migrations/
config/` = **74 source-code files / +4 039 / -277**. Remaining delta is
test-suite additions (310 new sentinel cases) + deployment scripts +
findings JSON reports.

### Heal commits grouped by surface (post-d601fdd34)

| Cluster | Commit(s) | Surface | Targeted behaviour |
|---|---|---|---|
| K2 race / encaisser | `481013703`, `0579c0453`, `95f283bd3`, `7b7ffb325`, `15b8a5665`, `7b7ffb325`, `5ef37bd94`, `385f77288`, `5e646503b`, `ef619bfb8` | PaymentService, OrderService, RefundWithCounterEntryService, PosComponent, KdsV2Grid | 409 typed exception + lockForUpdate + cash_movement on refund + audit_logs cross-chain anchor + KDS overflow chip + AudioContext cleanup |
| K2-HEAL-04 Stripe cascade | (in Gateway/Stripe.php) | Stripe.php charge.refunded handler | Bridge charge.refunded → RefundCreated listener cascade (sealed-aware + idempotent via WebhookEvent firstOrCreate) |
| J2 authz / fraud | `01c39aba3`, `6a2c9555a`, `072ae68c0`, `6d89d4798`, `bd451c873`, `fe7dacaa2`, `ac885ff73` | BlockKioskTokenFromAdminRoutes middleware, LoyaltyController, OrderItem trigger, User::canBeDisabled, ClawbackLoyaltyPointsOnRefund | Kiosk token can't reach /api/admin/*, loyalty HMAC + plaintext default flipped to FALSE, composition_snapshot BEFORE UPDATE trigger, super-admin id===1 backdoor removed |
| L2 security | `73c89da21`, `8d7b2d8b4`, `e832e0a77`, `ff37ac21b`, `449550179`, `a31b9b155` | LanguageService, PrinterRequest, MailHost, ItemRequest upload, SenangPay boot guard, Z-open cron 00:05 | Path containment (RCE/SSRF/LFI) + SSRF allowlists + file-upload hardening + missing webhook secret production boot refuse + Z-OPEN safety-net cron at 00:05 Paris |
| G2 fiscal / TZ | `d8bb8c35d`, `c98e94459`, `a7ab61043`, `157de5e0c`, `1e1fbb912` | DashboardService TZ Paris bounds + Z-close 23:55 cron + receipt addons rendering + AppLibrary FR currency + OrderDetailsResource parent_order_id | TZ-bound alignment + Z-close safety-net (companion to L2-HEAL-07 Z-open) |
| H2 idempotency / loyalty | `2c5b07c5e`, `8c022d5ed`, `286997174`, `8c4c173ab`, `e6cb61316` | Idempotency 3-col UNIQUE migration + cashier_attribution audit + PosRedemptionService TTC double-count + deploy.sh backup safety-net | Cross-user idempotency leak closed + cashier attribution + TTC tax fix + pre-migrate DB backup |
| I2 cache / cron | `cba372066`, `ba6d110da`, `7368fc23c` | ItemService::update cache invalidate + sanctum:prune-expired cron + LOYALTY_QR_SECRET in env example | Kiosk catalog cache freshness + token table janitor + secret material discipline |
| F2 timeout / hardening | `8ebbd057a`, `1a1067e04`, `1ccf19745`, `12ebaeb9b`, `10539a012`, `7d501d5bc` | axios global timeout 30s + idempotency PENDING TTL decoupled + REMBOURSEMENT visual marker + innodb_lock_wait_timeout 5s + rate-limit unification | Network resilience + UX + DB lock budget |
| N-HEAL Wave M follow-on | `5e646503b`, `385f77288`, `ef619bfb8`, `5ef37bd94` | KdsV2Grid overflow chip + PosComponent self-recursive setTimeout + Resource updated_at + AudioContext cleanup | Chef-rush operational safety net + cadence re-evaluation |

**16 distinct phases** (F→N) closed 30+ heals. **0 frozen-zone diff** verified
by Phase N N-SWEEP. NF525 chain **BIT-IDENTICAL** at `5e646503b` (HEAD-3).

---

## 2 · Sentinel sweep

### 2.1 · JS Vitest sentinels (`tests/js/sentinels/`)

```
Test Files  1 failed | 41 passed (42)
Tests       2 failed | 330 passed (332)
Duration    4.92s
```

**Failures** (2):

| Spec | Test | Verdict |
|---|---|---|
| `f004KioskCancelReasonSent.spec.js` | `KioskPaymentComponent never posts cancel-status without reason key` | **PRE-EXISTING** |
| `f004KioskCancelReasonSent.spec.js` | `KioskWaitingComponent customer cancel sends customer_request reason` | **PRE-EXISTING** |

**Static verification**: `git log d601fdd34..af92035b8 -- tests/js/sentinels/f004KioskCancelReasonSent.spec.js resources/js/components/frontend/kiosk/KioskPaymentComponent.vue resources/js/components/frontend/kiosk/KioskWaitingComponent.vue` returned **0 commits**. None of the test or source files were touched in the heal range. The spec was last modified by `b8f05e609` (audit WAVE1) which is OUTSIDE the heal range.

**Corroborating evidence**: `reports/test-e2e/goal-2026-05-23/phase-n/N-SWEEP-findings.json:62-69`
recorded these same 2 failures with `wave_n_caused: false` and noted "0
commits in d601fdd34..HEAD", "tracked in V1.0.X backlog".

### 2.2 · PHP PHPUnit sentinels (`tests/Feature/Sentinels/`)

```
Tests:   339 passed, 1 failed, 1 risky, 2 skipped
Time:    44.00s
```

**Failure** (1):

| Test | Assertion | Verdict |
|---|---|---|
| `TpeSimulationDepthSentinelTest::reconcile_path_amount_echo_still_fires_under_pos_simulation_hardware` | Expected response 200, got 405 | **PRE-EXISTING TEST BUG (route name mismatch)** |

**Static verification**: Test POSTs `/api/frontend/payment-reconcile`. The
actual route is `/api/frontend/reconcile-pending` (`routes/api.php:1275`).
The string `payment-reconcile` exists in exactly ONE file in the entire
codebase (the test itself). This is a stale test URL, never matched a
real route. Confirmed `git log d601fdd34..af92035b8 -- routes/api.php
tests/Feature/Sentinels/TpeSimulationDepthSentinelTest.php
app/Http/Controllers/Frontend/PaymentReconcileController.php` = 0 commits
for the test or the controller. The 3 route-file commits in the heal
range (`481013703`, `01c39aba3`, `10539a012`) added blocks
elsewhere — none touched the reconcile endpoint. Phase N N-SWEEP
pre-heal already recorded this as `pre-existing, NOT introduced by Wave N`.

**Risky test**: `TpeSimulationDepthSentinelTest::exact_amount_still_accepted_under_pos_simulation_hardware`
("This test did not perform any assertions") — also pre-existing, same file.

### 2.3 · Sweep verdict

| Suite | Total | Pass | Fail | Regressions | Pre-existing |
|---|---|---|---|---|---|
| Vitest sentinels | 332 | 330 | 2 | **0** | 2 |
| PHPUnit sentinels | 343 | 339 | 1 | **0** | 1 |

---

## 3 · Cross-system probes

### Probe A — Borne → KDS chain after L2-HEAL-01 (LanguageService path containment)

**Heal**: `a31b9b155` adds `validateLangFilePath()` helper, called inside
`LanguageService::fileText`, `fileTextStore`, `list`, `show`.

**Risk model**: Does the path-containment fix change locale RESOLUTION
for borne (FR locale stored in KioskMachine.locale) → KDS payload (FR
label rendering)?

**Static evidence**:
- `LanguageService` is consumed by `Frontend/LanguageController` (lines
  23-31) and `Admin/LanguageController` (lines 33-105). Both wrap admin
  language-file MANAGEMENT (list / show / fileText / fileTextStore /
  destroy).
- Laravel runtime translation goes through `Translator` / `Lang::get()`
  which loads from `lang/` directly via the framework's namespace
  loader — **not via LanguageService**.
- Vue i18n loads from `resources/js/languages/*.json` at build time
  (Mix). Not affected by LanguageService::fileText.
- Borne sets locale via `app/Models/Branch::resolveLocale` → `config('kiosk.default_locale')` → translation rendering uses Laravel's native `__()` and Vue i18n's `$t()` — **no LanguageService dependency in the rendering hot path**.

**Verdict**: PASS · zero regression on borne → KDS locale chain.

### Probe B — POS shortcuts panels after K2 races healed

**Heals**: 
- `481013703` — `PaymentService::confirmCounterPayment` throws
  `PaymentAlreadyCollectedException` (RuntimeException) on
  different-cashier race; same-cashier replay preserves no-op 200.
- `0579c0453` — `OrderService::changeStatus` adds lockForUpdate inside
  DB::transaction + `setRawAttributes` to sync route-bound model.

**Risk model**: Does counter-collect modal still open & complete in
single-cashier happy path? Does Livré status flip still work?

**Static evidence**:
- New `PaymentAlreadyCollectedException` is caught at:
  - `routes/api.php:828` — POS encaisser route, returns 409 + structured payload
  - `app/Exceptions/Handler.php:80` — defense-in-depth render mapping
  - Both ABOVE the generic `Exception → 422` fallback. No 500.
- Existing happy path remains: cashier A enters confirmCounterPayment,
  acquires lock, writes audit row, returns OrderDetailsResource as
  before. The new branch only fires when collected_by_user_id ≠ caller.
- OrderService::changeStatus refactor uses **mirror pattern** of
  existing iter13 self-cancel branch (lines 1871-1901). The
  `setRawAttributes` sync is critical and present — observers reading
  $order post-tx see persisted state.
- Sentinel `PosCounterCollectRaceProtectionSentinelTest` (PASS, 4 cases)
  + `OrderServiceChangeStatusRaceSentinel` (PASS, 2/2 src + behavioural)
  lock the contracts. Both PHPUnit run-green.

**Verdict**: PASS · happy paths preserved; race losers now correctly
get 409 instead of phantom 200.

### Probe C — Stripe webhook after K2-HEAL-04 (charge.refunded cascade)

**Heal**: `app/Http/PaymentGateways/Gateways/Stripe.php` 318-440 adds
`charge.refunded` branch bridging to `RefundCreated` event.

**Risk model**: Does original `charge.succeeded` webhook still create
`CapturePaymentNotification` row and Order properly? Does refund cascade
double-process?

**Static evidence**:
- `charge.succeeded` branch (lines 289-317) is **structurally
  unchanged**: still deletes prior CPN, creates fresh CPN, calls
  `$event->markProcessed($orderId)`.
- `charge.refunded` branch is **gated by 3 idempotency layers**:
  1. `WebhookEvent::firstOrCreate(provider, webhook_id)` at the HTTP
     entry — same event.id → 200 `duplicate_ignored`, never reaches
     this branch (lines 255-281).
  2. `elseif ((int) $order->payment_status === PaymentStatus::REFUNDED)`
     short-circuits re-drive via DLQ (line 381).
  3. The bridged listener `PersistOrderPaymentStatusChangedOnRefundCreated`
     itself is sealed-aware (commit message attests skips mutation on
     sealed parent, still broadcasts).
- Unknown order / order-not-found / sealed-parent code paths all log
  structured warnings to `fiscal` channel — observable in
  reconciliation, NOT silent failures.

**Verdict**: PASS · zero double-process risk; original success path
intact.

### Probe D — Sanctum tokens after J2-HEAL-03 (HMAC default flip)

**CRITICAL REFRAME**: The task probe assumed `j2-heal-03` flipped a
**Sanctum** token format default. Empirical reading of commit
`6d89d4798` shows it flipped the **loyalty plaintext acceptance default**
(`LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT: true → false`) AND hardened the
ephemeral **loyalty `lt_` token** format (SHA256 → HMAC-SHA256 +
random_bytes(16)).

**Risk model (re-scoped)**: 
- Sanctum customer tokens **NOT touched** — no Sanctum config
  change in this commit. Existing Sanctum bearer tokens continue to
  work unchanged.
- `lt_<...>` tokens are **issued in /loyalty/scan response per scan**
  (ephemeral, not stored client-side long-term). New format takes
  effect on next scan, no grandfathering needed.
- The legacy-plaintext flip means raw `FK:<code>` and bare `<code>`
  payloads now return rejection by default. Already-deployed mobile
  clients that send those formats will fail. **Escape hatch documented
  in `.env.example`** (set `LOYALTY_QR_ACCEPT_LEGACY_PLAINTEXT=true`
  during V1 rollout if needed).

**Verdict**: PASS for Sanctum (no impact). AMBER for plaintext mobile
clients (deliberate security regression covered by escape-hatch env;
V1 LOCAL deployment uses signed QR — no impact today).

### Probe E — Composition immutability after J2-HEAL-06 (BEFORE UPDATE trigger)

**Heal**: `fe7dacaa2` adds migration
`add_composition_snapshot_immutability_trigger` (DB-layer trigger) +
Eloquent `updating()` hook on `OrderItem` (app-layer).

**Risk model**: Do non-composition status updates (release_at,
status, instruction) on `order_items` still work? Does the trigger
falsely fire on legitimate snapshot inserts (legacy NULL → JSON
backfill)?

**Static evidence**:
- Trigger **fires only when composition_snapshot appears in SET
  clause** (BEFORE UPDATE on column-level diff). Status updates,
  release_at, instruction — all freely updatable (commit message
  attests + migration comment lines 78-82 confirm).
- NULL → JSON allowed (legacy backfill path explicit).
- 6 sentinel cases (PASS):
  1. INSERT with snapshot — allowed
  2. UPDATE of unrelated column — allowed (no false positive)
  3. Eloquent ::save() snapshot mutation — blocked
  4. DB::table()->update() snapshot mutation — blocked
  5. NULL → snapshot backfill — allowed
  6. Raw nulling of non-null snapshot — blocked
- 177 Fiscal + 13 Refund + 10 KDS-flow tests regression-checked GREEN.

**Verdict**: PASS · status updates pass through, only snapshot
mutations blocked.

### Probe F — Z-loop after G2/L2 heals

**Heals**:
- `c98e94459` — `feat(g2-heal-06)`: Z-close safety-net cron 23:55 Paris
- `449550179` — `feat(l2-heal-07)`: Z-open safety-net cron 00:05 Paris
- `d8bb8c35d` — `fix(g2-heal-04)`: TZ-generation Paris-bounds alignment

**Risk model**: Day-boundary Z close+open transition seamless? Gap in
fiscal_sequence at 00:00 Paris time?

**Static evidence**:
- 10-minute window between 23:55 close and 00:05 open is **deliberate**
  to let close finish (Cache::lock + chain verify + audit logging are
  O(seconds), well under 10 min).
- Orders that land in the 23:55-00:05 window fall through the existing
  `fiscal_alloc_error_at` flag path + retry cron (degraded but
  graceful — Order model field present + FrontendOrderService:1165
  already handles unflagging).
- Each cron uses `STATUS_OPEN` pre-check so cashier-manual-close ahead
  of 23:55 = idempotent info-log, no false pager alarm.
- Both crons `withoutOverlapping()` + `onOneServer()` + per-branch
  isolation. ZReportService FROZEN §7 not touched (only `command`
  schedule lanes added — additive).

**Verdict**: PASS for chain continuity (no gap silently inserted into
fiscal_sequence). MINOR RISK: rare orders landing in 10-min window go
into `fiscal_alloc_error_at` retry path; that's the documented graceful
degradation. Recommended owner attention only if soak test observes
non-empty `fiscal_alloc_error_at` post-window.

---

## 4 · Regressions detected

| ID | Description | Source heal | Severity | Evidence |
|---|---|---|---|---|
| — | none in code | — | — | — |
| **PRE-EXISTING-#1** | `f004KioskCancelReasonSent.spec.js` 2 failures — regex expects backticked template-literal change-status URL with reason key | NONE (last touched `b8f05e609`, pre-heal range) | LOW (sentinel false-negative — implementation may actually be correct, regex is stale) | git log d601fdd34..HEAD = 0 commits on this test or its source files; cross-corroborated by `phase-n/N-SWEEP-findings.json:62-69` |
| **PRE-EXISTING-#2** | `TpeSimulationDepthSentinelTest::reconcile_path_amount_echo_still_fires_under_pos_simulation_hardware` — test POSTs `/api/frontend/payment-reconcile`, real route is `/api/frontend/reconcile-pending` (405 method not allowed at unknown URL) | NONE (string `payment-reconcile` exists in exactly 1 file — the test) | LOW (test bug, not behavior bug — F-002 amount-echo enforcement on the REAL route is covered by other sentinels) | grep `payment-reconcile` = 1 match (the test); routes/api.php:1275 confirms real URL is `reconcile-pending` |
| **NOTE** | `TpeSimulationDepth::exact_amount_still_accepted_under_pos_simulation_hardware` | PRE-EXISTING risky (no assertions) | trivial | Same suite as above |

**0 new regressions introduced by the d601fdd34..af92035b8 heal range.**

---

## 5 · Other observations (NON-regression, informational)

1. **`fiscal_alloc_error_at` 10-min window risk** (Z-loop). Tracked
   above under Probe F. Not a regression — graceful-degradation path
   pre-dates the cron.
2. **Sentinel that POSTs to a wrong route URL has been around long
   enough to span the heal range**. Recommend renaming the test file
   route call to `reconcile-pending` (1 LOC fix) as V1.0.X cleanup —
   it currently provides false confidence.
3. **F-004 sentinel regex is brittle**. Recommend tighter inspection
   to confirm the kiosk cancel surfaces actually DO send a reason in
   the request payload (the implementations may have evolved to a
   different rendering style). V1.0.X test-debt cleanup.
4. **Phase N → P convergence reports** (`phase-n/N-SWEEP-findings.json`
   + `phase-p/P-SYNTH-consensus.json`) **independently confirm** these
   findings (0 NEW regressions, 8 NEW P1 BACKLOG items from deeper
   architectural audit, V1 LOCAL ship status UNCHANGED).
5. **Stripe `charge.refunded` cascade** — fully covered. The new code
   path goes through `WebhookEvent` idempotency + already-refunded
   skip + sealed-parent observability log. Triple-defense.

---

## 6 · Verdict

**ZERO REGRESSIONS** introduced by the d601fdd34..af92035b8 heal range.

- **Sentinels**: 669/672 pass+fail (330/332 vitest + 339/340 phpunit;
  convention: pass+fail counted, risky/skipped excluded — PHPUnit raw
  output was 339 passed, 1 failed, 1 risky, 2 skipped). 3 failures,
  all PRE-EXISTING and confirmed unrelated to the heals
  (git log proof + phase-N N-SWEEP corroboration).
- **Cross-system probes**: Probes A, B, C, E, F → PASS. Probe D →
  PASS for Sanctum (re-scoped: heal touches loyalty token format, not
  Sanctum auth).
- **Frozen-zone**: 0 LOC diff (validated by phase-N).
- **NF525 chain**: BIT-IDENTICAL (validated by phase-N).
- **Phase P consensus**: 0 P0, 0 RED post-heal — confirmed by an
  INDEPENDENT 12-zone deep audit run between phase-N and HEAD.

### Most-at-risk system (forward-looking)

**Z-loop window 23:55-00:05 Paris** — graceful-degradation via
`fiscal_alloc_error_at` retry is present, but real-world soak should
verify NO fiscal_alloc_error_at orders accumulate. This is the only
materially new operational behavior with edge-case timing.

Second-most-at-risk: the **KDS overflow chip + PosComponent
self-recursive polling** (N-HEAL-01 + N-HEAL-04) — both are new code
patterns added to user-facing surfaces. Already sentinel-covered
(KdsV2GridOverflowChipSentinel.spec.js 6 cases PASS +
posKioskPollingCadenceSentinel.spec.js 20 cases PASS), but UI behavior
under real chef-rush warrants observability during owner soak.

### UNVERIFIABLE in this READ-ONLY session

- Playwright E2E browser-driven smoke (would need running Laravel app + 
  browser + seed DB — explicitly out of scope per task constraints).
- Live `charge.refunded` Stripe replay (would need Stripe CLI replay 
  fixture + queue worker).
- Actual `fiscal:close-all-active-branches` / `fiscal:open-all-active-branches`
  cron invocation behavior over a day-boundary in clock-tampered test env.

These are appropriate next checks for Super.6 (chef-rush stress) and 
owner soak test G3.
