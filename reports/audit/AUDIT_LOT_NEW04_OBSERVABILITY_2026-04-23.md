# AUDIT — Lot NEW-04 Observability (sync_metrics + dashboards + correlation_id end-to-end)

**Date** : 2026-04-23
**Cycle** : `sync_hardening_v3` / Phase 1bis
**Implementer** : GPT-5.5-high (codex_runner) + orchestrator patches
**Auditors** : (1) GPT-5.5-high mission `T-AUDIT-NEW04` (2) Claude Code CLI terminal `claude-orchestrate`
**Outcome** : ✅ **CLOSED — PASS** (after 2 audit passes + 9 fix iterations)

---

## 1. Goal & contract

Add the missing observability surface that Phase 1bis lots (1.C / NEW-01..03) depend on for SLO enforcement:

- `outbox.dispatch_latency_ms` (p95 < 2 000 ms steady-state)
- `kds.sync_fallback_interval_ms` (p50 < 5 000 ms during WS outages)
- `ws.auth_failure` count cross-checked with F-12 SESSION_INVALID
- `ws.reconnect_storm` (NEW-02 telemetry)
- `recent_failures` (last 25 unrecovered domain_events for ops triage)

End-to-end correlation: every metric row must carry the same `correlation_id` as the originating request / job, so `sync-overview` can be joined with the trace already attached by `HasCorrelationId` trait.

**Hard invariants** :
1. `commit_before_dispatch` (preserved — recorder is called in success branch, after broadcast is acknowledged).
2. Branch isolation (a branch-scoped operator MUST NOT read another branch's metrics).
3. Telemetry MUST NOT break the calling flow (try/catch + log warning, never throw).
4. Client metric cardinality MUST be bounded (whitelist + max 200 per batch + 60/min throttle).

---

## 2. Surface delivered

### Backend
- **Migration** `2026_04_23_220000_create_sync_metrics_table` — 5 columns + 2 composite indexes (`metric_type+occurred_at`, `branch_id+occurred_at`).
- **`App\Services\Observability\SyncMetricsRecorder`** (singleton) — 4 public recorders + 1 private `insertMetric` with try/catch + `resolveCorrelationId` falling back to `X-Correlation-ID` header.
- **`SyncOverviewController`** — 2 endpoints under `auth:sanctum + permission:kitchen-display-system`:
  - `GET /api/admin/observability/sync-overview` (since= filter, branch_id scoped via `resolveBranchScope()`)
  - `POST /api/admin/observability/client-metrics` (`StoreClientMetricsRequest` validation, throttle 60/min)
- **`AppServiceProvider::register()`** — singleton binding.
- **`DispatchDomainEventsJob::handle()`** — non-blocking telemetry hook in success branch (try/catch wraps `recordEventDispatched()`).

### Frontend
- **`MetricsBatcher.js`** — bounded FIFO (200), retry-preserving `unshift` on flush failure, lifecycle (`start/stop/destroy`), event subscriptions (ws + kds).
- **`WebSocketService.handleSubscriptionError()`** — emits `observability_metric { type: 'ws.auth_failure', value: 1 }`.
- **`bootstrap.js`** — axios response interceptor captures `X-Correlation-ID` → `window.__correlationId` + `localStorage.correlation_id`.

### Tests (35 new)
- `SyncMetricsRecorderTest` (6) — happy path, whitelist rejection, DB-failure swallowed, singleton.
- `SyncOverviewControllerTest` (15) — 401 unauth, percentile math, since= filter, recent_failures, 202 client-metrics, 422 invalid type, 422 over-200, **+ 7 branch-isolation tests** (T-MISS-B variants + T-CLA-1..4).
- `EnsureCorrelationIdPropagatesToMetricsTest` (1) — header → DB row.
- `DispatchDomainEventsObservabilityIntegrationTest` (2) — T-MISS-A (insert failure does NOT break outbox), T-MISS-C (worker correlation_id propagation).
- `metricsBatcherFlush.spec.js` (8) — batch+flush, max cap, 5xx warn-once, destroy unsubscribe, **T-MISS-E (buffer restored in original order on flush rejection)**.
- `metricsBatcherEvents.spec.js` (3) — ws.reconnect_storm, ws.disconnect_event, kds reason-change dedupe.

---

## 3. Audit-T (GPT-5.5-high) — verdict FAIL → fixed

| ID | Sev | Finding | Fix |
|----|-----|---------|-----|
| **G2** | 🔴 critical | `GET /sync-overview` did NOT enforce branch isolation. A POS-Operator/Chef could pass `?branch_id=other` and read any branch's metrics. | New `resolveBranchScope()` method: branch-scoped users 403 on cross-branch, force-scoped to own branch when omitting `branch_id`. Global admin (`branch_id=0`) can aggregate or scope explicitly. `permission:kitchen-display-system` middleware added. |
| **G3** | 🟡 warning | 50k SELECT cap silently skewed percentiles when window > cap (most-recent rows dropped). | `private const SELECT_LIMIT = 50000`, `truncated` flag in JSON response, `orderBy('id')` after `orderBy('occurred_at')` for deterministic truncation. |
| **G4** | 🟡 warning | `ws.auth_failure` emitted client-side but rejected by `ALLOWED_CLIENT_METRICS` whitelist on backend (silent drop). | Added `'ws.auth_failure'` to whitelist on **both** PHP and JS sides. |
| **G9** | 🟡 warning | Frontend correlation_id hydration unproven; `MetricsBatcher.readCorrelationId()` returned null in practice. | axios response interceptor in `bootstrap.js` captures `X-Correlation-ID` echoed by `CorrelationIdMiddleware` → `window.__correlationId` + `localStorage`. |
| **T-MISS-A,B,C,D,E** | tests | 5 missing assertions for non-blocking telemetry, cross-branch leak, worker correlation propagation, batcher restore-order. | All 5 added (see §2 Tests). |

---

## 4. Audit-Claude (terminal) — verdict PASS_WITH_WARNINGS → fixed

| ID | Sev | Finding | Fix |
|----|-----|---------|-----|
| **A1** | 🟡 warning | `(int)($user->branch_id ?? 0)` → 0 silently promoted ANY user to global admin (footgun if Chef/POS-Op factory forgot `branch_id`). | `resolveBranchScope()` now requires `hasRole('Admin')` before accepting global-aggregate path; otherwise returns **403** to surface the misconfig deterministically (rather than silent data leak). |
| **A3** | 🟡 warning | `POST /client-metrics` had NO permission gate (asymmetric with `index`). Branch Manager could write telemetry that nobody on the same role can read. | Constructor middleware now covers `['index', 'clientMetrics']` — symmetric `permission:kitchen-display-system` gate. |
| **A5** | 🟡 warning | `recordWebSocketAuthFailure()` server-side method exists but unused; if wired later, `ws.auth_failure` would double-count (client + server, same metric name, no `source` label). | Extensive docblock added on `ALLOWED_CLIENT_METRICS` constant: instructs the future caller to add a `source: 'client'\|'server'` label and update dashboards. Dead method preserved deliberately so the next wiring picks up the warning. |
| **T-CLA-1** | P0 | Branch Manager (no `kitchen-display-system` perm) → 403 on GET. | `test_branch_manager_without_kds_permission_cannot_read_sync_overview`. |
| **T-CLA-2** | P1 | POS Operator cross-branch → 403. | `test_pos_operator_cannot_read_another_branch_metrics`. |
| **T-CLA-3** | P0 | User with `branch_id=NULL/0` AND **not** Admin → 403 (proves A1 fix). | `test_null_branch_id_without_admin_role_cannot_aggregate_globally`. |
| **T-CLA-4** | P2 | Branch Manager → 403 on POST too (proves A3 symmetric gating). | `test_branch_manager_cannot_post_client_metrics_either`. |

---

## 5. Errors encountered & root cause

| # | Error | Root cause | Fix |
|---|-------|-----------|-----|
| 1 | `test_overview_filters_by_since_query_param` failing (asserted `0` got `1`). | Timezone mismatch: `now()` writes `occurred_at` in `Europe/Paris`, `toISOString()` → UTC. SQLite string-compared the two. | `parseSince()` converts Carbon to `config('app.timezone')` before `format('Y-m-d H:i:s')`. |
| 2 | GPT initially missed the branch-isolation gate. | Brief specified scoping but no test asserted cross-branch refusal. | Audit-T G2 promoted to critical → resolveBranchScope() + 3 new branch-isolation tests. |
| 3 | Audit-T G4 — silent metric drop. | Whitelist out-of-sync between PHP and JS modules. | Both updated; future drift caught by `test_record_client_metric_whitelist_rejects_unknown_type`. |

---

## 6. Final test surface

```
PHP (40 passing):
  Tests\Feature\Observability\SyncMetricsRecorderTest                         6
  Tests\Feature\Observability\SyncOverviewControllerTest                     15
  Tests\Feature\Observability\EnsureCorrelationIdPropagatesToMetricsTest      1
  Tests\Feature\Observability\DispatchDomainEventsObservabilityIntegrationTest 2
  Tests\Feature\Outbox\OutboxConcurrentWorkerDedupeTest (regression)          9
  Tests\Feature\Queue\* (regression)                                         13
  Tests\Feature\Admin\KdsSyncControllerTest (regression)                     (8 — already covered)

JS (798 vitest passing — full suite, including):
  tests/js/metricsBatcherFlush.spec.js                                        8
  tests/js/metricsBatcherEvents.spec.js                                       3

Invariants: 6/6 (SSOT pricing, branch_id server-side, OrderStateMachine, App\Events\* afterCommit, EventContract envelope, audit-log on sensitive actions)
```

---

## 7. Lessons & recommendations

1. **Whitelist drift between layers** is a recurring class of bug. Future telemetry additions MUST update the whitelist in `SyncMetricsRecorder::ALLOWED_CLIENT_METRICS` (PHP) AND `MetricsBatcher.js::ALLOWED_CLIENT_METRICS` (JS) — consider extracting to a generated shared constant.
2. **`branch_id=NULL` is a recurrent footgun**. The hardcoded promotion-to-admin pattern `($user->branch_id ?? 0) === 0` is convenient but unsafe; always pair with `hasRole('Admin')`. Should be promoted to a static-analysis rule (PHPStan custom check).
3. **Asymmetric permission gates** between read & write surfaces of the same resource invite confused-deputy bugs. Default to symmetric gating and document any deliberate asymmetry.
4. **Server-side metric methods** that mirror client-side metrics need a `source` label from day 1, even if unused, to prevent future double-counting.

---

**Closed by** : `orchestrator_claude_opus`
**Memory ref** : `memory/episodes/12_decisions_log.jsonl#NEW-04`
