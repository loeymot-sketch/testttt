# A2 — POS Hardening (RED-team Round 1)

- **Branch**: `v1-0-1-hardening-2026-05-17` @ HEAD `1235e3e1a` + uncommitted working tree
- **Auditor**: A2 — POS Hardening (read-only)
- **Date**: 2026-05-18
- **Methodology**: file:line strict, working-tree mods = highest scrutiny (no review applied), trust nothing.

---

## Verdict — **BLOCK MERGE**

Owner claim "13 P0 closed" is partially supported but **two NEW P0 regressions are introduced by uncommitted working-tree mods**, plus **one P0 latent bug in committed code that would silently destroy every queued offline cash sale**. Heal claims for the committed P0s (#6/#7/#9) are credible after verification — but the uncommitted simulation_hardware feature opens a NF525 cash-trail escape hatch with no production-environment guard, and `.env:105` already pre-sets the bypass flag ON.

**Score**: 4 P0 / 3 P1 / 4 P2 / 2 P3.

---

## Heal verification (claims vs evidence)

| Claim | Status | Evidence |
|---|---|---|
| Wave 5F P0-#6 IndexedDB queue + UI integration | **PARTIAL-FAIL** (helpers OK, replay endpoint broken) | `resources/js/composables/usePosOfflineState.js:48` posts to `admin/pos/order` — **route does not exist** (only `admin/pos` at `routes/api.php:728`). See P0-A2-#1. |
| Wave 5F P0-#9 Split-payment phantom CARD | **PASS** | `app/Http/Requests/PosOrderRequest.php:175-211` enforces `required` + `withoutGlobalScopes` + `branch_id` + `STATUS_ACTIVE` check. Defense-in-depth at `app/Services/Payments/SplitPaymentService.php:111-137`. Sentinel `tests/Feature/Sentinels/PosSplitPaymentPhantomCardSentinelTest.php` covers (a) missing terminal_id 422, (b) cross-branch terminal 422, (c) happy path 201. |
| Wave 5F P0-#7 cash-drawer idempotency middleware | **PASS** | `routes/api.php:813` (`cash-drawer/open`), `:817` (`sessions/open`), `:820` (`sessions/close` via `->middleware('idempotency')`), `:822-825` (`sessions/{session}/reconcile`). `app/Http/Middleware/IdempotencyKeyMiddleware.php` exists. |
| Wave 5E + 5I PosOrderController IDOR | **PASS** (`show`) / **P2-INCONSISTENT** (`destroy`/`changeStatus`/etc.) | `show:113-121` has explicit BranchScope bypass + 403 + ModelNotFound→403 unified. `destroy` delegates to `OrderService::destroy:2045-2052` which has explicit branch check. `changeStatus/changePaymentStatus/selectDeliveryBoy` rely on default BranchScope (404 if cross-branch, not 403). See P2-A2-#3. |
| LOCK POS Wizard XSS (5G owner-gate) | **PENDING OWNER COUNTERSIGN** | `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md:362-365` rows for owner pre-patch / post-patch / final approval are **empty**. The 237 LOC frozen-zone diff in `public/js/pos-wizard.js` between `main..HEAD` is from `91a1e1b2c CV1-WIZARD-COMPOSABLE-001` (composer-aware feature flag, predates this cycle), NOT the LOCK XSS heal. Meta-finding P1-A2-#1: frozen-zone has TWO paths in, one LOCK-gated and one not. |

---

## P0 findings (production blockers)

### P0-A2-#1 — Offline replay endpoint 404 → silent cash-sale data loss

- **File**: `resources/js/composables/usePosOfflineState.js:48`
- **Severity**: P0 — NF525 cash-trail loss + customer-facing data loss
- **Evidence**:
  ```javascript
  // usePosOfflineState.js:46-48
  for (const entry of await listPending()) {
      const config = { headers: { 'X-Idempotency-Key': entry.idempotencyKey } };
      try {
          await postFn('admin/pos/order', entry.payload, config);  // ← 404
  ```
  Real route in `routes/api.php:721-728`:
  ```
  Route::prefix('pos')->name('pos.')->group(function () {
      Route::post('/', [PosController::class, 'store'])->middleware([...]);
  ```
  Correct path is `admin/pos` (verified working in `resources/js/store/modules/posOrder.js:196 axios.post("admin/pos", payload, config)`).
- **Failure mode**: every queued offline order POSTs to `/api/admin/pos/order` → 404 (catchall returns HTML in Laravel SPA) → composable hits `catch` → `markFailed` → entry retained. The 30-minute TTL then **purges all queued cash sales without ever replaying them**.
- **Impact**: 50 entries × cash sales lost. NF525 cash-trail invariant broken silently (sales never reach the fiscal sequence allocator).
- **Sentinel gap**: `tests/js/posOfflineQueueImpl.spec.js` mocks `postFn` so the URL is never verified against the real route table.
- **Fix**: `await postFn('admin/pos', entry.payload, config);` + add a Playwright spec that hits the real route.

### P0-A2-#2 — `pos.simulation_hardware` bypass with no production-environment guard

- **Files** (all uncommitted):
  - `app/Http/Controllers/Admin/PosController.php:92-97` (skips `assertCashDrawerSessionOpenIfCashInvolved`)
  - `app/Services/Payments/SplitPaymentService.php:203-207` (`$simulating = config('pos.simulation_hardware') === true`)
  - `app/Services/PaymentService.php:275-280` (`if ($strict && config('pos.simulation_hardware') === true) { $strict = false; }`)
  - `config/pos.php:37` (env-driven, no environment check)
- **Severity**: P0 — NF525 cash-trail invariant escape hatch
- **Evidence**: 4 sites read `config('pos.simulation_hardware')` with **no** `app()->environment('production')` wrapper. `config/pos.php:34` documents the prod behavior as a comment, not enforcement:
  ```
  | When false (default — production):
  |   - All hardware-presence checks fire normally.
  ```
- **Production exposure**: `.env:105 POS_SIMULATION_HARDWARE=true`. `.env` is git-ignored (good), but there is **no CI guard / deploy-time check / production sentinel** ensuring the flag is OFF in production. Sole protection is the "Production day" sentence in `config/pos.php:17` — human-discipline only.
- **Compound risk with NF525**: when bypass is ON, every CASH sale skips the `CashDrawerSession` check. No `cash_movement` is written (`SplitPaymentService.php:277-287` is gated by `$cashSession !== null`). Cash trail is destroyed. Z-report variance gate fires AT BEST after end-of-day, AT WORST silently if variance gate was also disabled.
- **Sentinel claim vs reality**: `tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php` only proves the bypass works when explicitly enabled. There is no `test_simulation_hardware_must_be_false_in_production` guard.
- **Fix**: Either (a) hard-fail on boot when `app()->environment('production') && config('pos.simulation_hardware') === true` (`AppServiceProvider::boot`), or (b) deploy-time sentinel `php artisan pos:assert-production-safe` invoked in deploy script.

### P0-A2-#3 — `.env` pre-seeds `POS_SIMULATION_HARDWARE=true`

- **File**: `.env:105` (not tracked, but local dev preset)
- **Severity**: P0 — operational risk amplifier of P0-A2-#2
- **Evidence**:
  ```
  POS_SIMULATION_HARDWARE=true
  ```
- **Risk class**: a junior ops engineer copying `.env` to staging/prod, or a careless `cp .env.local .env.production`, ships the bypass live. Combined with P0-A2-#2, this is a one-keystroke production breach.
- **Mitigation chain check**:
  - `.env.example` does NOT mention `POS_SIMULATION_HARDWARE` (verified: grep returned 0 hits).
  - No `pre-deploy.sh` hook or CI check found.
- **Fix**: (a) add `POS_SIMULATION_HARDWARE=false` to `.env.example` with explicit warning; (b) add a hook to `composer post-install-cmd` or deploy script that asserts `env('APP_ENV') !== 'production' || env('POS_SIMULATION_HARDWARE') !== 'true'`.

### P0-A2-#4 — Stale docstrings claim composable is "deferred to V1.0.2" while wired in PosComponent

- **Files**:
  - `resources/js/helpers/posOfflineQueue.js:15-16` ("Integration in PosComponent.vue deferred to V1.0.2")
  - `resources/js/composables/usePosOfflineState.js:8-9` ("PosComponent.vue integration is deferred to V1.0.2 — helper ships standalone")
- **Severity**: P0 — **traceability drift**, not a runtime bug per se, but indicates that the offline wire-in path was added (PosComponent.vue:1104, :1148, :1626) **without updating the helper's documented contract**. A future maintainer reading the helpers might believe integration is still deferred and re-wire it, racing with the existing wire.
- **Combined with P0-A2-#1**: stale docs hide the very URL bug that breaks replay.
- **Fix**: 4 LOC docstring update + a `@since 2026-05-17 wired in PosComponent.vue mount()` annotation.

---

## P1 findings (must-fix before merge)

### P1-A2-#1 — Frozen-zone has two diff paths into `pos-wizard.js` — LOCK XSS still pending owner countersign

- **Files**:
  - `public/js/pos-wizard.js` (frozen-zone per CLAUDE.md §7) — 237 LOC diff `main..HEAD` introduced by `91a1e1b2c CV1-WIZARD-COMPOSABLE-001 [T-WC-POS-RUNTIME-01]` (predates the v1-0-1 cycle; composer-aware path gated by `window.foodkingConfig.posWizardComposerAware.enabled`).
  - `resources/views/admin-pos-v4.blade.php` — 165 LOC diff `main..HEAD` (same source commit).
  - `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md:362-365` — owner signature rows empty (LOCK plan filed but NOT countersigned).
- **Severity**: P1 — frozen-zone governance breach
- **Meta-finding**: this cycle filed a LOCK plan for an XSS heal that has not yet executed, while an **earlier commit landed inside the frozen-zone via a feature-flag escape**. Two routes in: one LOCK-gated, one not. Either the frozen-zone policy is enforced or it isn't.
- **Fix**: (a) re-baseline `main` from a state where pos-wizard.js is intentionally locked (retroactive LOCK for 91a1e1b2c); (b) execute the XSS LOCK with owner countersign before V1 ships; (c) tighten CLAUDE.md §7 wording to forbid feature-flag bypasses in addition to direct edits.

### P1-A2-#2 — `terminal_id` not validated to be ACTIVE on legacy single-tender CARD path

- **File**: `app/Http/Requests/PosOrderRequest.php:115-122` (terminal_id rule for `payment_breakdown.*`)
- **Severity**: P1 — Sprint H2 P1-Z7-01 wire-in claims "wire terminal_id from request to OrderPayment (backend stage A)" but the single-tender CARD path (`pos_payment_method = CARD`, no `payment_breakdown`) has no `terminal_id` field at all.
- **Evidence**: rules block 102 enforces `pos_payment_note` (last-4-digits) but does not require a `terminal_id` at the top level. A cashier doing a single-tender CARD sale never specifies the terminal → Z-report falls back to "Sans TPE" bucket and fees_total=0 for that sale.
- **Risk**: under-reporting of TPE fees; loss of payment_terminals attribution; legacy regression on Wave 5F UI Stage A.
- **Fix**: extend `PosOrderRequest` with top-level `terminal_id` rule when `pos_payment_method === CARD`, mirror the same `branch_id` + `ACTIVE` check in `withValidator`.

### P1-A2-#3 — Offline queue race on multi-cashier reconnect (no server-side dedup beyond idempotency-key)

- **Files**:
  - `resources/js/helpers/posOfflineQueue.js:32-41` (UUIDv4 per entry — unique per device)
  - `app/Http/Middleware/IdempotencyKeyMiddleware.php` (per-route)
- **Severity**: P1 — race condition on shared resources at offline-online edge
- **Scenario**: Branch loses internet for 15 min. Cashier A and Cashier B each queue 8 cash sales locally. Network returns. Both devices replay simultaneously. Each entry has a distinct UUIDv4, so idempotency-key dedup does nothing (it's per-key, not per-cash-content). Both writes succeed; **no collision** on fiscal_sequence_no (server-allocated, monotonic). But:
  - `CashDrawerSession` lookup at replay time uses `Auth::id()` — if Cashier A is logged in but Cashier B's queue is replayed under A's auth (e.g. shared terminal), the wrong session gets the cash_movement.
  - The composable does not stamp the original `user_id` into the payload.
- **Fix**: enqueue `cashier_user_id` (current `Auth::id()`) in the entry payload + server validates the replay user matches.

---

## P2 findings (should-fix)

### P2-A2-#1 — `PosOrderController::destroy/changeStatus/changePaymentStatus/selectDeliveryBoy` rely on BranchScope auto-404, not explicit 403

- **File**: `app/Http/Controllers/Admin/PosOrderController.php:130-184`
- **Severity**: P2 — consistency / defense-in-depth
- **Evidence**: `show:113-121` was hardened (RED-team Wave 5I A.1) with explicit BranchScope bypass + `abort_unless(...)` + ModelNotFound→403. The other four endpoints implicitly rely on BranchScope auto-404. This is technically safe today, but inconsistent with the `show` hardening pattern and brittle to future scope changes.
- **Risk**: if `BranchScope` is ever globally weakened (e.g. an Admin bypass for analytics), four endpoints silently leak cross-branch access while `show` remains hardened.
- **Fix**: apply the same `withoutGlobalScope(BranchScope::class)->findOrFail` + explicit branch check pattern across all four endpoints.

### P2-A2-#2 — `posOfflineQueue` `MAX_ENTRIES=50` reject-new without operator alert

- **File**: `resources/js/helpers/posOfflineQueue.js:22, 65`
- **Severity**: P2 — UX / operational
- **Evidence**: when capacity is reached, `enqueueOrder` returns `null`. `PosComponent.vue:2989-2991` toasts a warning, but the queue silently rejects without any local logging or backend telemetry.
- **Risk**: a long outage (>50 sales) silently drops every additional sale. No record exists post-recovery; owner cannot reconcile.
- **Fix**: persist a `dropped_count` in localStorage + emit a sentry/Bugsnag event on every reject.

### P2-A2-#3 — `posOfflineQueue` does not stamp `branch_id` or `cashier_user_id` in the entry

- **File**: `resources/js/helpers/posOfflineQueue.js:66-72`
- **Severity**: P2 — multi-tenant + multi-cashier replay safety
- **Evidence**: entry shape is `{ idempotencyKey, payload, savedAt, attempts, lastFailedAt }`. The payload comes from `this.checkoutProps.form` which carries `branch_id` — but if a cashier switches branch between offline-enqueue and online-replay, the original branch context is lost (server uses the request `branch_id` blindly).
- **Fix**: snapshot `branch_id` + `cashier_user_id` at enqueue, server validates match at replay.

### P2-A2-#4 — Stripe cents-truncation fix is uncommitted (CTO P0-6)

- **File**: `app/Http/PaymentGateways/Gateways/Stripe.php:58`
- **Severity**: P2 (fix is correct, just unmerged) — but for V1 Cloud-Prep merge, it must be committed.
- **Evidence**: `git diff HEAD`:
  ```php
  -    'amount'      => (int) $order->total * 100,
  +    'amount'      => (int) round((float) $order->total * 100),
  ```
  The fix is identical pattern to `OrderController:137`, `PaymentReconcileController:173`, `SplitPaymentService:103/110`. Correct.
- **Action**: commit before merge OR explicitly drop and re-do in V1.0.2.

---

## P3 findings (nice-to-have)

### P3-A2-#1 — `posOfflineQueueDb.js` 5-second timeout is unconfigurable

- **File**: `resources/js/helpers/posOfflineQueueDb.js:12 OP_TIMEOUT_MS = 5000`
- **Severity**: P3 — observability
- **Fix**: extract to `posOfflineConfig.js` so SRE can tune at runtime.

### P3-A2-#2 — `usePosOfflineState` `tryFlush` does not exponential-backoff failed entries

- **File**: `resources/js/composables/usePosOfflineState.js:44-55`
- **Severity**: P3 — operational politeness
- **Evidence**: every 30s opportunistic flush retries all failed entries identically. A flaky backend with one consistently-failing entry will see 60× retries per 30 min.
- **Fix**: respect `entry.attempts` to skew the next retry deadline (e.g. `entry.lastFailedAt + 2^attempts * 5000`).

---

## Uncommitted-mods risk register

| File | Risk | Severity |
|---|---|---|
| `app/Http/Controllers/Admin/PosController.php:92-97` | NF525 bypass on `simulation_hardware === true` | P0-A2-#2 |
| `app/Services/Payments/SplitPaymentService.php:203-207` | Same bypass on split-tender CASH path | P0-A2-#2 |
| `app/Services/PaymentService.php:275-280` | Same bypass on strict→soft downgrade | P0-A2-#2 |
| `app/Http/PaymentGateways/Gateways/Stripe.php:58` | Correct cents-truncation fix, unmerged | P2-A2-#4 |
| `config/pos.php` (full file, new) | Adds env-driven flag with no production-environment guard | P0-A2-#2 |
| `tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php` (new file, untracked or modified) | Proves bypass works but does NOT enforce off-in-prod | P0-A2-#2 |
| `tests/Feature/Pos/PosCashTrailTest.php` (M) | Modified — likely accommodating simulation_hardware; needs review for invariant erosion | P1 |
| `tests/Feature/Pos/SplitPaymentEndToEndTest.php` (M) | Modified — likely accommodating simulation_hardware | P1 |
| `tests/Feature/Pos/TerminalIdWireInTest.php` (M) | Modified — diff scope unclear | P2 |
| `tests/Feature/Sentinels/SplitPaymentSentinelTest.php` (M) | Modified — sentinel changes are highest scrutiny | P1 |
| `tests/Unit/Services/Payment/SplitPaymentServiceTest.php` (M) | Modified | P2 |
| `public/js/pos-app.js`, `public/js/pos-shell.js`, `public/css/app.css`, `public/mix-manifest.json` (M) | Compiled artefacts — re-run mix before commit | P3 |

---

## Convergence recommendation

**Do NOT merge to `main` until**:

1. **P0-A2-#1 fixed**: change `usePosOfflineState.js:48` URL to `admin/pos`, add a Playwright spec that exercises the offline-replay path against the real route.
2. **P0-A2-#2 fixed**: add a `AppServiceProvider::boot` guard that throws `RuntimeException` if `app()->environment('production') && config('pos.simulation_hardware') === true`. Add a sentinel test that asserts the throw.
3. **P0-A2-#3 fixed**: `.env.example` documents `POS_SIMULATION_HARDWARE=false` with an explicit "DO NOT FLIP TO TRUE IN PRODUCTION" comment. Deploy script grep-asserts.
4. **P0-A2-#4 fixed**: stale docstrings updated in `posOfflineQueue.js` + `usePosOfflineState.js`.
5. **P1-A2-#1 owner-decision**: either retroactive LOCK for `91a1e1b2c CV1-WIZARD-COMPOSABLE-001` OR justified frozen-zone exception logged in `plans/`.
6. **P1-A2-#2 fixed OR explicitly deferred**: top-level `terminal_id` rule for single-tender CARD.

**Acceptable for V1 ship with explicit owner waiver**: P1-A2-#3 (multi-cashier replay race), P2-* (consistency/UX/multi-tenant snapshot), P3-* (observability/backoff).

**Tier-1 evidence required pre-merge**:
- `php artisan test --filter="PosSimulationHardware|SplitPaymentPhantomCard|PosSplitPayment|PosCashTrail"` — green
- `npx vitest run tests/js/posOfflineQueueImpl.spec.js` — green
- Playwright spec covering offline-replay path on real `admin/pos` route — green
- Manual visual check on PosComponent banner + queue depth counter — green
- `git grep "admin/pos/order"` returns 0 hits

**Convergence**: refuse to certify "13 P0 closed" until **the 4 NEW P0s above are explicitly addressed**. The cycle delivered real heals but introduced new P0 surface area. Net: 13-close + 4-open = **9 net P0 closed**, not 13.

---

## Annex — files inspected

- `resources/js/helpers/posOfflineQueue.js` (124 LOC)
- `resources/js/helpers/posOfflineQueueDb.js` (94 LOC)
- `resources/js/composables/usePosOfflineState.js` (104 LOC)
- `resources/js/components/admin/pos/PosComponent.vue` (3943 LOC, scope: 13-38 banner, 1101-1149 setup wire, 1607-1632 mounted, 2974-3019 enqueue+flush)
- `app/Http/Requests/PosOrderRequest.php` (227 LOC, scope: 105-122, 175-211)
- `app/Services/Payments/SplitPaymentService.php` (305 LOC, scope: 111-137, 195-218, 240-249)
- `app/Http/Controllers/Admin/PosController.php` (uncommitted diff, scope: 89-97)
- `app/Services/PaymentService.php` (uncommitted diff, scope: 275-280)
- `app/Http/PaymentGateways/Gateways/Stripe.php` (uncommitted diff, scope: 47-58)
- `app/Http/Controllers/Admin/PosOrderController.php` (298 LOC)
- `app/Services/OrderService.php` (scope: 2039-2090)
- `routes/api.php` (scope: 721-870, 813-868)
- `config/pos.php` (39 LOC, new)
- `tests/Feature/Sentinels/PosSplitPaymentPhantomCardSentinelTest.php` (225 LOC)
- `tests/Feature/Pos/PosSimulationHardware4ScenariosTest.php` (255 LOC)
- `tests/js/posOfflineQueueImpl.spec.js` (scope: header + URL absence)
- `plans/LOCK_POS_WIZARD_XSS_ESCAPE_2026-05-17.md` (scope: 350-400 signatures)
- `.env:105`, `.gitignore:8`, `.env.example` (negative grep)
