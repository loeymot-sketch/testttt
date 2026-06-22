# AUDIT — Lot 1.C: KDS Adaptive Polling Fallback
**Date:** 2026-04-23
**Auditor:** Claude Code CLI (independent — not the implementer)
**Sources read:** plan §2, mission input.json, `KdsSyncController.php`, `KdsSyncService.php`, `KdsSyncService.js`, `KitchenDisplaySystemComponent.vue` (F-03 markers), `KdsSyncControllerTest.php`, 4 vitest specs, `routes/api.php:808–814`, `{en,fr,ar}.json`

---

## A. Branch Isolation

**A1. PASS** — Non-admin users supplying a `?branch_id` that differs from `auth()->user()->branch_id` receive a hard 403 (controller lines 60–65). Matching or absent `?branch_id` always resolves to the auth user's own branch; the query value is never trusted for scope.

**A2. PASS** — Cache key is `kds.sync.{cacheBranchKey}.{minuteBucket}.{md5(since|includeDeleted)}` where `cacheBranchKey = branchId === 0 ? 'all' : (string) $branchId` (service lines 41–46). Different branch_ids produce disjoint cache namespaces; multi-tenant poisoning is not possible.

**A3. PASS** — When `$userBranchId === 0` (admin), the controller accepts an explicit `?branch_id=N` override (lines 55–59). Absent `?branch_id` with admin user resolves to `0`, omitting the `WHERE branch_id` clause — correct global view.

---

## B. Idempotency / Safety

**B1. PASS** — `KdsSyncService::sync()` performs only `SELECT` queries and one `Cache::remember` read-through. No `save()`, `update()`, `event()`, or `dispatch()` calls exist anywhere in the controller/service chain.

**B2. PASS** — `start()` guards with `if (this._started) { return; }` as its first statement (JS line 69). Repeated calls without an intervening `stop()` are strict no-ops; no second timer is registered.

**B3. PASS** — `stop()` calls `this._abortController.abort()` and nulls the reference (lines 90–93). `forceSync()` checks `signal.aborted || !this._started` after every `await` before emitting a `sync` event; stale responses cannot fire post-stop.

---

## C. WS / Poller Coordination

**C1. PASS** — `_baseCadence()` returns `{ interval: Infinity, reason: 'ws_connected' }` for `WS_CONNECTED` (JS lines 225–229). `_schedule()` installs a 60 s `_driftTimer` only; no polling fetch timer is set.

**C2. PASS** — `RECONNECTING`/`DEGRADED` → 5 000 + jitter(0–2 000) ms; `DISCONNECTED`/`SESSION_INVALID` → 10 000 + jitter(0–3 000) ms; high-activity override drops to 3 000 ms when ≥5 fresh orders seen in the last 60 s. Cadence reads `wsService.state` live on every `_baseCadence()` call.

**C3. PASS** — `_handleHttpError()` doubles `_currentIntervalMs` and caps at 30 000 ms on 5xx. After a 2xx `forceSync()` re-enters ACTIVE and calls `_recomputeCadence('reset_after_200')`, restoring the WS-state-driven base cadence.

---

## D. Version-Gate / Dedupe

**D1. PASS** — Gate condition is `version <= previousVersion` (JS line 153): both equal and regressed versions are blocked. Gated orders get `versionGated: true`; their `id` is pushed into `gatedIds`.

**D2. PASS** — `_maxVersionEntries = 256`. `_rememberVersion()` evicts via a `while (_versionOrder.length > 256)` FIFO loop (lines 322–335). Verified by `kdsDedupeByIdVersion.spec.js` with 300 distinct ids.

---

## E. Test Sentinels

**E1. PASS** — `test_sync_cache_key_is_isolated_per_branch()` creates an order in each of two branches, polls as each user with the same `since`, then asserts each response contains only its own order (lines 168–188).

**E2. PASS** — `test_sync_branch_isolation_non_admin_cannot_cross_branch()` authenticates as a branch-1 user, passes `?branch_id={other.id}`, and asserts HTTP 403 (lines 120–130).

**E3. PASS** — All four vitest spec files call `vi.useFakeTimers()` in `beforeEach` and `vi.useRealTimers()` in `afterEach`. No real `setTimeout` executes during test runs.

---

## F. Repo Conventions

**F1. PASS** — `class KdsSyncController extends AdminController` (controller line 21). No bare `Controller` extension.

**F2. PASS** — `Route::get('/sync', [KdsSyncController::class, 'sync'])` sits at `api.php:813`, physically inside the `Route::prefix('kds-order')->name('kdsOrder.')->group(...)` block (lines 808–814). Sanctum + permission middleware are inherited from the parent group; the redundant `$this->middleware(...)` in the constructor is harmless.

**F3. PASS** — `kds_sync_stamp` and `kds_sync_never` are nested under the `"label"` parent in all three files: `en.json:757–758`, `fr.json:212–213`, `ar.json:724–725`. The Vue component accesses them as `$t("label.kds_sync_stamp", …)` and `$t("label.kds_sync_never")`, consistent with surrounding KDS keys in the same section.

**F4. PASS** — `KdsSyncService.php` uses `OrderStatus::ACCEPT`, `OrderStatus::PREPARING`, `OrderStatus::PREPARED`, `OrderStatus::DELIVERED`, `OrderStatus::CANCELED`, `OrderStatus::REJECTED` throughout (lines 50–51). No magic integers or global `ORDER_*` constants.

**F5. PASS** — Read-only endpoint. No `DispatchableAfterCommit`, `event()`, or `dispatch()` calls in the new code. After-commit dispatch invariant is trivially preserved.

---

## G. Observable Risks (Residual)

**G1. WARN — Network-level errors (non-HTTP) silently halt the poller.**
In `KdsSyncService.js` `forceSync()`, a non-`AbortError` catch block emits `'error'` and **rethrows**. The `_timer` callback's `.catch(() => {})` swallows the rethrow — but `_schedule()` is never called from that path. A DNS failure, TLS timeout, or `net::ERR_NETWORK_CHANGED` stops the poll loop until a WS state-change externally triggers `_recomputeCadence`. In a concurrent WS+HTTP outage the KDS becomes permanently blind with no self-healing. **Orchestrator must resolve before closing Lot 1.C:** add `this._schedule()` at the end of the `catch` block, after the `AbortError` guard.

**G2. WARN — Monotonicity test does not verify strict advancement.**
`test_sync_server_now_advances_monotonically()` passes the same `$since` in both calls and waits only 2 ms (`usleep(2_000)`). Both requests share the same `minuteBucket` and `md5(since|includeDeleted)`, so the second call returns the cached payload (identical `server_now`). `assertGreaterThanOrEqual(strtotime($first), strtotime($second))` passes trivially because `>=` accepts equality — it validates non-regression, not true monotonic advancement. Remediation: flush the cache between calls, use different `$since` values, or assert `assertGreaterThan` with a clearly distinct `$since`.

**G3. WARN — `computeOrderVersion` diverges silently from spec contract.**
The brief specifies `version = max(updated_at_unix, status_changed_at_unix)`. The implementation docblock acknowledges `status_changed_at` is absent from `Order` and falls back to `updated_at` alone. Functionally acceptable today, but if `status_changed_at` is added in a future migration the version computation must be updated or clients may skip valid card refreshes. Add a `TODO(F-03):` comment in `KdsSyncService.php` referencing the planned column, or file a formal backlog item to prevent silent drift.

---

## OVERALL: PASS WITH WARNINGS

All hard invariants pass (branch isolation, idempotency, WS coordination, version-gate/dedupe, test sentinels, repo conventions). **G1 is the most operationally critical** warning (poll loop can permanently halt on network errors); G2–G3 are test-coverage and documentation gaps. No FAIL items were found. Orchestrator must resolve G1 before closing Lot 1.C; G2–G3 may be tracked as follow-up items.

---

## Orchestrator follow-up (post-audit)

- **G1** RESOLVED in commit applying Lot 1.C — see `_schedule()` re-call at end of `forceSync()` catch block + new vitest `kdsBackoffOn5xx.spec.js::it("self-heals after a network-level error")`.
- **G2** RESOLVED — `test_sync_server_now_advances_monotonically` now flushes the cache between calls AND uses a distinct `$since` per call.
- **G3** RESOLVED via TODO(F-03) comment + `BACKLOG: status_changed_at column` entry in `plans/MEGA_PLAN_SYNC_HARDENING_v3_2026-04-23.md` (Phase 3 dette technique).
