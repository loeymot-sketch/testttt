# T-1.4.2 SECURITY findings — IdempotencyKeyMiddleware semantics (replay attack + collision + scope leak)
**Agent**: SECURITY (read-only)
**Round**: 2
**Date**: 2026-05-18
**Threat model**: attacker holds **legitimate FoodKing credentials** (`kiosk:order` token, cashier session, or branch-manager Sanctum token) for a single branch. Goal: trigger **double-charge, double-order, double-Z, double-refund, cross-tenant cache pollution, or cache poisoning DoS** by abusing the HTTP-level idempotency layer. Hostile framing — every assumption questioned.

---

## Cross-reference to existing PASS coverage (`tests/Feature/Idempotency/IdempotencyMiddlewareTest.php`)

| Attack vector | Defended by PASS test | Verified |
|---|---|---|
| Identical replay returns cached body, handler invoked once | `test_two_identical_posts_create_only_once_and_replay_second` | YES |
| Required-route enforcement (422 on missing header) | `test_post_without_header_on_required_route_returns_422` | YES |
| Opt-in pass-through (unrequired routes) | `test_post_without_header_on_unprotected_route_passes_through` | YES |
| Cross-branch isolation (same key, different branch → distinct executes) | `test_cross_branch_same_key_results_in_distinct_executions` | YES |
| TTL expiry → re-execute | `test_replay_after_ttl_expired_executes_anew` | YES |
| Same key + different payload → 409 conflict | `test_same_key_different_payload_returns_409` | YES |
| Storage down + fail-closed → 503 | `test_redis_unavailable_fail_closed_returns_503` | YES |
| Storage down + fail-open → pass-through (relies on DB UNIQUE) | `test_redis_unavailable_fail_open_passes_through` | YES |

**Coverage gaps the existing suite does NOT test (each tied to a finding below):**

- Cross-**user** same-branch collision (`scope = branch+user+key` claim) → only cross-**branch** is tested.
- `PENDING → release() → retry` race when handler throws.
- `name:` pattern vs `path:` pattern in `required_routes` config matching.
- Header-omission bypass on `idempotency`-decorated but `required_routes`-absent routes.
- `concurrent acquire → 425` followed by client retry on an endpoint **without** DB UNIQUE backstop (Z-close, refund-with-counter-entry, change-status, cash-drawer).
- 5xx no-cache amplification on a downstream-flaky endpoint.

---

## Finding S-1 — Header-omission bypass on opt-in routes mounted with `idempotency` middleware (P0)

```yaml
finding_id: S-1
severity: P0
category: idempotency_coverage_gap
attack_name: "Header-Omission Bypass — opt-in routes with no required-list entry are silent-pass"
attacker_capability_required: |
  Any authenticated cashier (`Branch Manager`, `POS Operator`, etc.) able to hit
  the route legitimately + ability to omit / mis-name the `X-Idempotency-Key` header.
file_evidence:
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:49-59  (header == '' && !isRequired → silent next($request))
  - config/idempotency.php:25-34  (required_routes list — only 8 patterns; the other 9 of the 17 wired routes are NOT in the list)
  - routes/api.php:768  counter-collect.confirm  → middleware('idempotency')  but NOT in required_routes
  - routes/api.php:788  counter-collect.cancel   → middleware('idempotency')  but NOT in required_routes
  - routes/api.php:799  collect-kiosk-cash       → middleware('idempotency')  but NOT in required_routes
  - routes/api.php:800  orders.print-receipt     → middleware('idempotency')  but NOT in required_routes
  - routes/api.php:813  cash-drawer.open         → middleware('idempotency')  but NOT in required_routes
  - routes/api.php:817  cash-drawer.sessions.open → middleware('idempotency')  but NOT in required_routes
  - routes/api.php:820  cash-drawer.sessions.close → middleware('idempotency')  but NOT in required_routes
  - routes/api.php:824  cash-drawer.sessions.reconcile → middleware('idempotency')  but NOT in required_routes
  - routes/api.php:867  posOrder.refundWithCounterEntry → middleware('idempotency')  but NOT in required_routes (only the `*/refund-with-counter-entry` glob is missing)
trigger:
  failure_mode: |
    The middleware logic at line 52-59 says: if the header is empty AND the
    route is NOT in `required_routes`, **pass through silently** (`return $next($request)`).
    Attacker omits `X-Idempotency-Key`. Middleware skips the entire replay
    machinery. Two POSTs to `counter-collect/{order}/confirm` within the
    payment lock window can land in `PaymentService::confirmCounterPayment`
    twice. There is **no DB-UNIQUE backstop** equivalent to
    `orders.idempotency_key` for these routes — payment-confirm depends on
    `Order.payment_status` transition which IS guarded, but the **counter-
    entry refund** at line 867 creates a NEW Order (NF525 mirror) and the
    UNIQUE backstop on `orders.idempotency_key` is **only populated when
    the client sends the header** (cf. `OrderService::posOrderStore:611`
    `$validated['idempotency_key'] = substr($idempotencyKey, 0, 64);`).
    Omit header → no key persisted → no DB UNIQUE protection → **double
    refund = double NF525 mirror sequence consumed + double TPE void**.
  reproduction: |
    1. Auth as cashier (POS Operator).
    2. `curl -X POST /api/admin/pos-order/123/refund-with-counter-entry` WITHOUT `X-Idempotency-Key`.
    3. Replay immediately (network retry).
    4. Two orders created with `idempotency_key = NULL`. Both pass UNIQUE
       index check (NULL ≠ NULL in MySQL). Both consume a fresh fiscal
       sequence_no.
v2_saas_impact: |
  Per-tenant amplifier — every tenant suffers identically. NF525
  monotonic sequence will show two refund mirrors with consecutive
  sequence numbers for the same source order, which a DGFiP auditor
  can detect but cannot reverse.
business_impact:
  - double refund posted to cash drawer / TPE = ~revenue leak
  - NF525 audit anomaly (two mirror orders, same parent)
  - kiosk customer charged twice on counter-collect.confirm flaky retry
nf525_impact:
  - fiscal_sequence_no inflated by 1 per duplicate refund (gap-free invariant violated in spirit if not in DB)
recommendation: |
  Add EVERY route currently mounted with `middleware('idempotency')` to
  `config/idempotency.php` `required_routes`. The list as-shipped covers
  the 8 highest-impact routes; the other 9 routes that wire the middleware
  are effectively decorative because clients can opt out by omitting the
  header. Either:
    (a) make `required_routes` the union of "all `idempotency`-wired routes"
        and warn on boot if a wired route is missing from the list, OR
    (b) flip the semantic: header-required becomes the default; routes
        explicitly tagged as "optional" must opt out. Safer-by-default.
  Recommended (b) — least risk of forgetting a new route.
owner_gate_required: N (middleware is in CLAUDE.md §7 frozen-zones list **but** the proposed fix is in `config/idempotency.php` — not frozen — and `routes/api.php` — not frozen)
heal_effort: ~2h (config patch + 9 sentinel tests asserting each route is in `required_routes`)
verification_test: |
  tests/Feature/Idempotency/IdempotencyRequiredRoutesCoverageTest.php
  asserts every route declaring middleware('idempotency') has a matching
  entry in config('idempotency.required_routes') — fails on every new
  wired route until the operator adds it.
```

---

## Finding S-2 — PENDING placeholder release + 425 → client retry → double-execute (P0)

```yaml
finding_id: S-2
severity: P0
category: idempotency_replay_semantic
attack_name: "Throw-and-Release Double-Execute — exception path nukes the placeholder, second caller retries clean"
attacker_capability_required: |
  Any caller that can co-trigger TWO simultaneous requests with the same
  X-Idempotency-Key where the FIRST request raises an exception inside
  the controller AFTER the middleware has acquired the placeholder.
file_evidence:
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:137-143  (try { next() } catch (\Throwable) { release(); throw })
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:99-124   (acquire + waitForCompletion + 425)
  - app/Services/Idempotency/RedisIdempotencyKeyRepository.php:107-123  (waitForCompletion polls find(); find() only returns COMPLETED records — line 53)
trigger:
  failure_mode: |
    1. Caller A and B POST simultaneously with identical key K and identical payload.
    2. A acquires the PENDING placeholder (line 99-104).
    3. B fails to acquire, enters `waitForCompletion` (line 105-108).
    4. A's controller throws (validation in service layer, deadlock, TPE
       timeout, whatever). Middleware line 140-142: `release()` deletes
       the PENDING placeholder, then re-throws.
    5. B's poll loop: `find()` only returns records where `state ===
       'COMPLETED'` (RedisIdempotencyKeyRepository::find:52-53). A
       PENDING record never matches. A's released-and-deleted record
       also never matches. B's poll exits after `race_wait_ms` ms (line
       119-120) returning null.
    6. Middleware line 119-123 returns 425 to B.
    7. **Caller B (typical HTTP retry middleware on the client) sees 425
       Too Early, waits a few hundred ms, retries.** Same key, same payload.
    8. Now the cache is empty (A's placeholder is gone). B's retry
       acquires fresh, executes the handler. **Double-execute happens
       because A's exception was not actually safely undone in the
       business layer — it was undone in the cache layer only.**
  why_existing_db_unique_doesnt_save_you: |
    For `frontend/order` store and `pos/` store, `orders.idempotency_key`
    UNIQUE catches the double INSERT. For:
      - counter-collect.confirm/cancel  (PaymentService::confirmCounterPayment)
      - collect-kiosk-cash  (OrderService::collectKioskCash)
      - cash-drawer.open / .sessions.open / .close / .reconcile  (CashDrawer*)
      - refund-with-counter-entry  (creates a NEW Order mirror — but
        idempotency_key from header is NULL because S-1 lets the header
        be omitted, OR is set but on the NEW mirror row, NOT keyed to
        the parent transaction)
      - online-order/change-payment-status, table-order/change-payment-status
    There is NO equivalent DB UNIQUE on the operation. A double-execute
    posts a second cash movement, opens a second cash session, mints a
    second TPE void, or transitions the same order twice.
  why_the_test_suite_misses_it: |
    The existing `test_two_identical_posts_create_only_once_and_replay_second`
    runs the handler successfully, so the cache holds a COMPLETED record.
    No test exercises the path where the handler throws BETWEEN acquire and
    complete — the exact window S-2 describes.
v2_saas_impact: |
  Per-tenant amplifier. Network retries are universal client behaviour.
  Stripe SDK, axios with retry adapter, mobile React Native sync queue
  all retry on 425. The middleware's 425 path is documented in
  IdempotencyMiddlewareTest.php as the "in-flight twin" response —
  but the absence of a COMPLETED record means the next retry sails
  through.
business_impact:
  - double cash-drawer open → reconciliation drift (cashier signs off twice for one float)
  - double counter-collect.cancel → audit log shows ghost cancellation
  - double receipt print → printer paper waste + duplicate audit row
  - double refund-with-counter-entry → ~revenue leak + NF525 sequence inflation
nf525_impact:
  - per-execution fiscal_sequence_no consumption on refund-with-counter-entry
  - per-execution audit_logs HMAC chain row (chain integrity OK, but ledger semantics polluted)
recommendation: |
  Two options, prefer (a):
    (a) Keep the PENDING placeholder on throw — DO NOT call `release()`.
        Mark the record as FAILED in the cache. `waitForCompletion`
        should learn to surface a FAILED state and rebuild a deterministic
        4xx/5xx response for caller B. This matches Stripe's idempotency
        spec: "Idempotent requests that error are also stored — replays
        return the same error".
    (b) Keep release() but downgrade 425 to 409 with a Retry-After header
        AND make the client retry policy explicit: 425 is NEVER retryable
        by the client. Requires axios interceptor + mobile sync queue
        guard.
  Side-note on the `complete()` path: the cache stores 2xx responses
  exclusively (line 145-154). A 4xx return from the handler (validation
  fail) also `release()`s the placeholder (line 152-154). Same
  double-execute risk applies for any second caller waiting at line
  105. Fix at the same time.
owner_gate_required: Y (CLAUDE.md §7 — IdempotencyKeyMiddleware is frozen)
heal_effort: ~1d (state-machine refactor in IdempotencyRecord + repository + middleware + 4 new tests)
lock_doc_required: LOCK_idempotency_pending_failure_state.md (per FoodKing LOCK doctrine)
verification_test: |
  tests/Feature/Idempotency/IdempotencyExceptionPathTest.php
  - first request throws → second concurrent request must NOT execute the handler twice
  - first request 4xx-validation-fail → second concurrent request returns same 4xx, no second handler invocation
```

---

## Finding S-3 — Fiscal Z-report `open` / `close` carry NO `idempotency` middleware (P1, mitigated by domain logic)

```yaml
finding_id: S-3
severity: P1
category: idempotency_coverage_gap_nf525
attack_name: "Z-Report Retry-Storm — sequence inflation via repeated open with no HTTP-layer guard"
attacker_capability_required: |
  Any user with `pos-manage-fiscal` permission + flaky-network client
  (or hostile retry loop). Z-report routes carry `throttle:10,1` (10/min)
  but no `idempotency`.
file_evidence:
  - routes/api.php:1046-1053  fiscal.zReport.open / .close → throttle:10,1 only
  - app/Http/Controllers/Admin/Fiscal/ZReportController.php:37-55  no Cache::lock / no idempotency check
  - app/Services/Fiscal/ZReportService.php:78-137  domain-level Cache::lock('z_report_b{n}') + DB UNIQUE on STATUS_OPEN
  - app/Services/Fiscal/ZReportService.php:191-274  close path also has Cache::lock + lockForUpdate on STATUS_OPEN
trigger:
  failure_mode: |
    Operator clicks "Open Z" twice during a flaky-network blip. Without
    middleware-level idempotency, both clicks land on the controller. The
    Cache::lock at ZReportService.php:79 serialises them but **does not
    deduplicate them**. The second request waits for the lock, then sees
    `STATUS_OPEN` and throws RuntimeException (line 105-109). The HTTP
    response is a 500, **not a 200/201 echo of the first Z**. The frontend
    surfaces this as a misleading error to the operator.

    This is **NOT a double-Z** (the domain logic forbids two open Zs on
    the same branch — sequence monotonic invariant holds). It IS a
    poor UX + potential audit-log noise per retry. The downgrade from
    P0 to P1 is because the chain integrity is preserved.

    The same applies to `close`: second click during flaky network → 500
    with "no open Z report to close" instead of returning the just-closed
    Z. Operator may panic-click again, but the chain stays clean.
  why_p0_would_be_real_if_logic_was_weaker: |
    If ZReportService.open() did not check `existingOpen` first, two
    parallel opens would each compute `max(sequence_no)+1` and both
    INSERT. Without DB-level UNIQUE on (branch_id, sequence_no, status),
    the chain would fork. The existing migration *does* set a unique
    index on (branch_id, sequence_no) — verify in CI that this UNIQUE
    is intact and add a sentinel test (DB-level idempotency on Z is
    the only thing standing between "operator double-clicks" and
    "fiscal chain forks").
v2_saas_impact: |
  Per-tenant. Every tenant runs Z close manually once per day. Cross-
  tenant impact zero. V1.0.2 backlog candidate.
nf525_impact:
  - chain integrity preserved by domain logic
  - audit_logs gets ghost entries for failed Z opens (warnings, not chain rows — verified by AuditLogService append-only contract)
recommendation: |
  - Phase 1 (this round): add `fiscal/z-report/open` + `fiscal/z-report/close`
    to `config/idempotency.php` `required_routes` and mount `idempotency`
    middleware on both. Client UX improves from "Erreur 500 inconnue"
    to deterministic replay of first call's body.
  - Phase 2 (V1.0.2): add a UNIQUE index on `z_reports (branch_id, sequence_no)`
    if not already present. Sentinel test: `ZReportSequenceUniquenessTest.php`
    that asserts the constraint at DB level (covered partially by
    chain integrity tests, but no direct UNIQUE assertion).
owner_gate_required: N (route + config edits, no frozen-zone touch)
heal_effort: ~2h
```

---

## Finding S-4 — `/payment/{paymentGateway:slug}/{order}/success` (web.php:41) accepts POST with NO idempotency (P1, gateway-callback-driven)

```yaml
finding_id: S-4
severity: P1
category: idempotency_coverage_gap_payment_callback
attack_name: "Gateway-Callback Retry Storm — Stripe/SenangPay/Wallee retries land on a no-idempotency endpoint"
attacker_capability_required: |
  None additional — payment gateways themselves retry these callbacks
  on network failures. Stripe webhooks retry up to 3 days. SenangPay
  retries on timeout. Wallee POSTs payment notifications with the same
  payload up to 5 times. NO attacker required — this is a self-inflicted
  amplifier under flaky-network conditions.
file_evidence:
  - routes/web.php:41  Route::match(['get','post'], '/{paymentGateway:slug}/{order}/success', ...)  no middleware('idempotency')
  - routes/web.php:42  same for /fail (acceptable — fail is informational)
  - routes/web.php:43  same for /cancel
  - app/Http/Controllers/Frontend/PaymentController.php:87-93  no cache check, no DB-level dedup, delegates to PaymentManagerService->gateway()->success()
trigger:
  failure_mode: |
    Stripe retries the `payment_intent.succeeded` webhook → second callback
    lands on `/payment/stripe/{order}/success`. The handler delegates to
    PaymentManagerService::gateway('stripe')->success() which:
      1. updates Order.payment_status to PAID (idempotent if already PAID — OK)
      2. updates Order.transaction_id (overwrites previous, less OK)
      3. calls finalizePaidKioskOrder() which allocates a fiscal_sequence_no
         (NF525 — bounded by `fiscal_alloc_error_at` flag, but each call
         consumes a sequence number — gap-free invariant **violated** if
         the second call runs because the first already burnt a number).
    Whether the second call actually burns a new sequence depends on
    whether the gateway implementation re-enters finalizePaidKioskOrder
    on an already-PAID order. PaymentReconcileController (the OTHER
    payment idempotency surface) explicitly short-circuits on PAID
    status (`PaymentReconcileController.php:33-37` doc-comment), but
    PaymentManagerService's per-gateway implementations are NOT
    guaranteed to share this discipline — each gateway carries its
    own logic.
v2_saas_impact: |
  YES — per-tenant. V2 SaaS multiplies gateway count (every tenant picks
  their own). Inconsistent idempotency across gateways = high blast radius.
nf525_impact:
  - fiscal_sequence_no inflation possible per gateway-driven retry
  - z_reports aggregate over-counts revenue if the second callback re-runs
    aggregation
recommendation: |
  - Add `payment.{slug}.success` / `payment.{slug}.fail` / `payment.{slug}.cancel`
    routes to `config/idempotency.php` `required_routes` IF a stable key
    can be derived. PROBLEM: gateway callbacks rarely send `X-Idempotency-Key`
    — they send their own `event_id` / `tx_id` / `webhook_id`. The
    middleware would need to extract that per-gateway and synthesize a
    key. OPTION B (simpler): each gateway implementation must consult a
    `webhook_events` table keyed on `(provider, webhook_id)` BEFORE
    touching the Order. The `webhook_events` UNIQUE index is mentioned
    in `CLAUDE.md §9 Idempotency — webhook_events UNIQUE(provider, webhook_id)
    post iter11` — verify that EVERY gateway impl uses it.
  - This finding overlaps with T-3.3.1 in the Round 1 verdict §7 ("Webhook
    idempotency by provider"). Defer to that task scope.
owner_gate_required: N
heal_effort: ~1d (audit all PaymentManagerService gateway impls + add webhook_events check to any missing it)
```

---

## Finding S-5 — Cross-USER (same-branch) cache pollution NOT covered by tests (P2)

```yaml
finding_id: S-5
severity: P2
category: idempotency_scope_gap
attack_name: "Same-branch user-A keyspace squat on user-B"
attacker_capability_required: |
  Any cashier (User A) on the same branch as a target cashier (User B).
  Both can authenticate. Both can hit `/api/admin/pos`.
file_evidence:
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:77-82  scopedKey = sprintf('idempotency:v1:%d:%d:%s', branchId, userId, sha256(key))
  - tests/Feature/Idempotency/IdempotencyMiddlewareTest.php:124-142  only `test_cross_branch_same_key_results_in_distinct_executions` — does NOT cover same-branch different-user
  - database/migrations/2026_04_18_140003_scope_idempotency_key_to_branch.php:34  UNIQUE(branch_id, idempotency_key)  — DB layer is NOT user-scoped
trigger:
  failure_mode: |
    The middleware scope tuple at line 77 is (branch_id, user_id, key) —
    correct per the doc-comment at line 20. So in the CACHE layer, User A
    using key "K" cannot replay User B's cached response — they'd hit a
    fresh acquire because the scoped cache key differs by user_id.

    HOWEVER — the DB UNIQUE layer at the orders table is
    `(branch_id, idempotency_key)` only, NO user_id. So if User A's POS
    create lands at line 587-596 of OrderService::posOrderStore with
    `$idempotencyKey = "K"` and a fresh branch_id, the DB INSERT
    succeeds. User B then POSTs with key "K" on the same branch:
      - Middleware cache: User B's scopedKey differs → fresh acquire OK
      - DB UNIQUE on orders(branch_id, "K"): **CLASH with User A's row**
      - QueryException 23000 caught at line 1075
      - `findExistingOrderForIdempotencyRecovery(K, branch)` returns
        User A's Order
      - **User B receives User A's Order back as if they created it**
    The Order belongs to branch X — both users are on branch X — so the
    BranchScope is happy. But User B sees User A's lines, items,
    customer, payment details (`PII leak` if customer info is rendered).
    For a fast-food single-branch tenant, this is a UX bug (cashier B
    thinks their order was created). For multi-cashier-shift tenants
    or future SaaS multi-user single-tenant, this is a PII risk.
v2_saas_impact: |
  YES — per-tenant scaling. SaaS-grade tenants will have many cashiers
  per branch. The mismatch between cache scope tuple (B, U, K) and DB
  UNIQUE (B, K) becomes a real bug at the DB-recovery path.
recommendation: |
  - SHORT-TERM (V1): add `user_id` to the `orders.idempotency_key` UNIQUE
    composite index → (branch_id, user_id, idempotency_key). Migration +
    sentinel test. This aligns the two layers.
  - ALTERNATIVE: salt the persisted `idempotency_key` with the user_id
    before writing (e.g. `hash('sha256', user_id . '|' . key)`). Same
    effect, fewer schema changes.
  - Add test `test_same_branch_different_user_with_same_key_distinct_executes()`
    to the middleware suite.
owner_gate_required: N (migration + new test, no frozen-zone touch)
heal_effort: ~2h
verification_test: |
  tests/Feature/Idempotency/IdempotencyCrossUserSameBranchTest.php
  - userA + key K + branch 7 + payload P → 201 Order #N
  - userB + key K + branch 7 + payload P → 201 Order #M (DIFFERENT, NOT recovery)
```

---

## Finding S-6 — Cross-tenant cache key prediction → cache poisoning DoS surface (P2)

```yaml
finding_id: S-6
severity: P2
category: idempotency_cache_poisoning_dos
attack_name: "Predictable Scope Key — pre-fill the cache to deny a future legitimate user"
attacker_capability_required: |
  Cashier with valid session + ability to enumerate cache keys for any
  (branch_id, user_id, key) tuple. The cache backend is Redis in prod
  (`config('cache.default')`), reachable only by app processes — direct
  Redis access is not in the threat model.
file_evidence:
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:77-82  scoped key is `idempotency:v1:%d:%d:%s` with sha256(key)
  - app/Services/Idempotency/RedisIdempotencyKeyRepository.php:55-75  add() with TTL — atomic
trigger:
  failure_mode: |
    The scoped cache key is FULLY DETERMINISTIC given (branch_id,
    user_id, key). An attacker who knows their OWN user_id and
    branch_id (trivial — visible in the user profile) could iterate
    keys "K1", "K2"... and pre-populate the cache with synthesised
    409-payload-hash records. Any future legitimate request with the
    same key + different payload hash receives a 409 IDEMPOTENCY_KEY_CONFLICT
    (line 88-94) → DoS on key reuse.

    Practical exploitability is LOW because:
      (a) The attacker can only DoS their OWN (user_id, branch_id) key
          namespace — the per-user scope prevents cross-user pollution.
          So the attacker DoS's themselves. Self-inflicted DoS = not a
          security finding.
      (b) The TTL is 24h (idempotency.ttl_seconds=86400). Recovery is
          time-bounded.
    The genuine concern is V2 SaaS multi-tenant: if tenants share a
    Redis prefix (no `cache.prefix` per tenant), tenant A could
    enumerate cache keys for tenant B's branch_ids and user_ids and
    pre-poison their namespace. This is a V2 hardening item — V1 is
    single-tenant (Le Cayenne) so the practical risk is zero.
v2_saas_impact: |
  YES — material. V2 SaaS MUST set `CACHE_PREFIX=tenant_<slug>` or
  equivalent before this is exploitable cross-tenant. Document in
  V2 SaaS deployment doctrine.
recommendation: |
  - V1: zero action (single-tenant scope).
  - V2 SaaS prerequisite: enforce per-tenant `cache.prefix` in
    bootstrap; sentinel test asserts the cache key contains the
    tenant prefix.
owner_gate_required: N (V1 = doc only)
heal_effort: 0h V1 / ~2h V2 (config + sentinel)
```

---

## Finding S-7 — 5xx no-cache amplifies a flaky downstream into a retry storm (P2)

```yaml
finding_id: S-7
severity: P2
category: idempotency_dos_amplification
attack_name: "5xx Cache-Skip Amplifier — every retry re-executes the failing handler"
attacker_capability_required: |
  Attacker triggers a controller path that reliably returns 5xx (e.g. by
  passing a payload that hits a TPE-simulator timeout, a downstream
  pricing service outage, or a malformed but auth-valid request that
  reaches a code path with a `throw new \Exception(...)` in the
  controller). Each retry re-executes from scratch.
file_evidence:
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:145-154  if (status >= 200 && < 300) → complete(); else → release()
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:152-154  ANY non-2xx (4xx OR 5xx) calls release() — no cached "failed" response
trigger:
  failure_mode: |
    Caller A POSTs with key K. Handler returns 502 (downstream Stripe
    outage). Middleware releases the placeholder (line 153). Caller A
    retries (typical client). Fresh acquire. Handler returns 502 again.
    Loop continues. EACH retry hits the handler, hits Stripe, gets 502,
    releases.

    For non-attacker scenarios (legit flaky network), this means a
    downstream outage gets MULTIPLIED by client retry counts. For a
    Le Cayenne kiosk fleet of 6 kiosks each retrying every 10s, a
    Stripe outage triggers ~36 calls/min PER ORDER instead of 6.
    Backpressure absent.

    The 4xx case is more subtle: a malformed payload returning 422
    validation fail will also release the placeholder. Replays will
    re-validate — cheaper than 5xx, but pointless extra work and
    audit-log spam.
recommendation: |
  - Cache 4xx responses too (idempotent: same payload always validates
    the same way) — this is what Stripe's idempotency spec does.
  - For 5xx: cache for a SHORT TTL (60-120s) with a marker that
    differentiates from a 2xx replay. Client retry within that window
    sees the cached 5xx without re-hitting the downstream. After the
    short TTL, allow a fresh attempt (recovery).
  - This is a design-quality improvement, not a P0 fix.
owner_gate_required: Y (CLAUDE.md §7 — IdempotencyKeyMiddleware is frozen) — would be part of S-2 heal anyway
heal_effort: included in S-2 ~1d
```

---

## Finding S-8 — Header injection / very-long key / unicode → tightly bounded by regex (PASS, document)

```yaml
finding_id: S-8
severity: PASS
category: input_validation
file_evidence:
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:61-65  preg_match('/^[A-Za-z0-9._\-]{8,64}$/', $key)
trigger:
  attempted: |
    1. `X-Idempotency-Key: ../../etc/passwd` → regex fails (contains `/`) → 422.
    2. `X-Idempotency-Key: \x00\x01\x02\x03` → regex fails (non-ASCII) → 422.
    3. `X-Idempotency-Key: ${jndi:ldap://x}` → regex fails (contains `{`, `:`) → 422.
    4. `X-Idempotency-Key: AAAAAAA...` (65 chars) → regex fails (max 64) → 422.
    5. `X-Idempotency-Key: AAA` (3 chars) → regex fails (min 8) → 422.
verdict: |
  Regex anchored with `^` and `$`, 8-64 chars, ASCII-only, no path
  separators / metachars. preg_match returns false on malformed input
  → MissingIdempotencyKeyException → 422. No crash, no SQL injection
  (key is sha256'd before storage), no log injection (Log::warning at
  line 126 uses structured array not string interpolation).
note: |
  trim((string)$request->header(self::HEADER)) at line 49 strips
  surrounding whitespace; if the header is missing entirely, $key=''
  and the empty-key branch handles it.
```

---

## Finding S-9 — `isRouteRequired` supports both `name:` prefix and path glob (PASS, document)

```yaml
finding_id: S-9
severity: PASS
category: route_matching
file_evidence:
  - app/Http/Middleware/IdempotencyKeyMiddleware.php:159-180
verdict: |
  The matcher supports two forms:
    (a) `name:fiscal.zReport.close` → exact route-name match
    (b) `api/admin/pos` (no prefix) → Laravel's $request->is() glob match
  config('idempotency.required_routes') uses form (b) exclusively. The
  routes in question have explicit `->name()` calls so form (a) could
  be used for hardening (route-name is rename-safe). Recommendation:
  prefer `name:` form for new entries to avoid drift if route URLs
  change. Not a finding — a style hint.
```

---

## Finding S-10 — TTL expiry race: cached 2xx expires mid-replay → re-execute, no UNIQUE backstop for non-order routes (P3)

```yaml
finding_id: S-10
severity: P3
category: idempotency_ttl_boundary
attack_name: "TTL-Boundary Re-Execute — cache record expires 1ms before a legitimate retry"
attacker_capability_required: |
  Network conditions or deliberately-tuned retry interval that lands the
  retry at ~24h ± epsilon after the first call.
file_evidence:
  - app/Services/Idempotency/RedisIdempotencyKeyRepository.php:71  Cache::add($key, $placeholder, $ttlSeconds)
  - config/idempotency.php:21  ttl_seconds = 86400 (24h)
trigger:
  failure_mode: |
    24h is far longer than any reasonable client retry window. The race
    is microscopic. The existing test
    `test_replay_after_ttl_expired_executes_anew` confirms that after
    TTL expiry, a fresh execute happens — by design.

    For order-create + payment-confirm routes, the DB UNIQUE backstop
    (`orders.idempotency_key`) catches the post-TTL double. For other
    routes (counter-collect, cash-drawer, refund), nothing catches it
    — see S-1 and S-2.

    Verdict: this is the same finding family as S-1+S-2, not a new one.
    Document as P3 reminder that TTL > 24h is fiscally unsafe for
    NF525-relevant operations: a kiosk order POSTed at 12:00 day 1
    that fails over → cached → retry at 12:00:01 day 2 → re-executes
    → second fiscal_sequence_no allocated → silent NF525 sequence
    inflation.
recommendation: |
  Differentiate TTL by route family:
    - order-create / payment-confirm: 7 days (long because card networks
      reverse charges late)
    - state-change (status, drawer): 1 hour (operator UX retry window)
    - print-receipt: 1 minute (paper jam retry window)
  ttl_seconds being a global is a P3 design simplification not a bug.
owner_gate_required: N
heal_effort: ~2h (config schema + middleware lookup)
```

---

## §10 — Strong-Reasoning Cost-of-Delay Matrix (Security T-1.4.2 lens)

| Finding | V1 ship blocker? | V2 SaaS blocker? | DGFiP risk? | Cross-tenant risk? | Heal effort |
|---|---|---|---|---|---|
| S-1 Header-omission bypass | YES | YES | Indirect (refund-with-counter-entry NF525 mirror) | NO | ~2h |
| S-2 Throw-and-release double-execute | YES | YES | Indirect (sequence inflation possible) | NO | ~1d + LOCK |
| S-3 Z-report no middleware | NO (domain logic defends) | NO | None | NO | ~2h |
| S-4 Gateway callback no middleware | NO if all gateways use webhook_events; YES if not | YES | YES if gap exists | NO | ~1d (audit + fix gaps) |
| S-5 Same-branch cross-user clash | NO (single-cashier-shift V1) | YES (multi-cashier SaaS) | NO | NO | ~2h |
| S-6 Cache key prediction | NO (single-tenant V1) | YES (V2 prereq) | NO | YES if no `cache.prefix` in V2 | 0h V1 / ~2h V2 |
| S-7 5xx amplifier | NO (UX issue) | NO direct | NO | NO | included in S-2 heal |
| S-8 Header injection | PASS | PASS | PASS | PASS | 0h |
| S-9 Route matcher style | PASS | PASS | PASS | PASS | 0h |
| S-10 TTL global | NO | NO | Indirect (long-tail kiosk retry edge) | NO | ~2h |

**Total V1-blocker P0 heal effort:** ~2h S-1 + ~1d S-2 = ~1.25 days (S-2 needs an OWNER GATE + LOCK doc per CLAUDE.md §7 frozen-zones — IdempotencyKeyMiddleware itself is frozen).

---

## §11 — Cross-Reference to Round 1 Findings

Round 1 listed T-1.4.2 in §7 as a Round-2 proposed task: "Double-charge risk if broken". This Round-2 deep audit confirms the risk and refines it:

- **Double-charge surface is NOT the order-create path** (the DB UNIQUE on `orders.idempotency_key` IS the real backstop, despite the layering disjoint with the middleware cache).
- **Double-charge surface IS** the 9 routes listed in S-1 that are decorated with `middleware('idempotency')` but absent from `required_routes` → header omission lets every retry through.
- **Double-execute** (not strictly double-charge) is real on Z-close (mitigated by domain logic), refund (NOT mitigated), counter-collect (NOT mitigated for state transitions), cash-drawer (NOT mitigated).

The clean-slate fix is **two patches**:
  1. `config/idempotency.php` `required_routes` = union of all `middleware('idempotency')`-wired routes (S-1 fix).
  2. PENDING-state failure semantics (S-2 fix) — requires owner-gated LOCK on the middleware.

---

## §12 — Recommendations for PR Split (binding for /ultrareview cadence)

If this report's findings are bundled into a PR:

**Recommended scope: PR `heal/idempotency-coverage-2026-05-18` (cherry-pick from W3 outcomes)**
- S-1 heal (config + 9 sentinel route-presence tests) — ~2h
- S-3 heal (add z-report routes to `required_routes`) — ~1h
- S-5 heal (migration + sentinel `same_branch_different_user` test) — ~2h
- S-10 heal (per-route-family TTL via config schema) — ~2h
- **DEFER S-2 to a dedicated LOCK-doc PR** (`heal/idempotency-pending-failure-state`) — ~1d + owner sign-off
- **DEFER S-4 to T-3.3.1 scope** (webhook idempotency by provider)

Net diff for the non-LOCK PR: ~400-700 LOC (mostly tests + config + migration). Under 5K cap.

---

## §13 — Notes on Threat-Model Boundaries Honored

- READ-ONLY: no file mutations. All evidence cites `file:line`.
- Did NOT execute any test or attempt a live exploit.
- Did NOT touch `app/Http/Middleware/IdempotencyKeyMiddleware.php` (frozen per CLAUDE.md §7).
- Did NOT alter any seeder / fixture / migration.
- All findings cite the production code path verified at the cited line.

---

**End of T-1.4.2 SECURITY findings (Round 2).** 10 findings total: 2× P0 + 3× P1/P2 + 2× P2 + 1× P3 + 2× PASS (S-8 input validation, S-9 route matcher) + 1 cost-of-delay matrix + 1 PR-split recommendation.
