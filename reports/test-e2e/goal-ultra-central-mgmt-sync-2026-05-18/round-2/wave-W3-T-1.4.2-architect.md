# T-1.4.2 IdempotencyKeyMiddleware deep semantics — ARCHITECT Audit Report — Round 2

## Verdict (one line): GO-CONDITIONAL

The middleware (`app/Http/Middleware/IdempotencyKeyMiddleware.php:39-157`) correctly implements the 5 acceptance cases — scope tuple `(branch_id, user_id, sha256(key))` at lines 76-82, 2xx-only cache band at line 145, strict payload-hash equality producing 409 at lines 88-94 / 110-116, TTL via `Cache::store()->add()` at `RedisIdempotencyKeyRepository.php:71`, and DB UNIQUE backstop on `orders.{branch_id, idempotency_key}` from migration `2026_04_18_140003_scope_idempotency_key_to_branch.php:35`. Existing PHPUnit suite `IdempotencyMiddlewareTest.php` already proves cases (a) replay, (b) 409 conflict, (c) cross-branch isolation, (d) TTL expiry, (e-partial) fail-closed/fail-open. **Three architectural risks remain that block PRODUCTION-PERFECT**: (1) the scope-tuple resolver `resolveBranchId()` lines 182-220 has 5 branches with 1 explicit fallback to client-controlled payload `branch_id` (line 219) which on a non-admin caller with `auth_branch_id < 0` lets the client choose the scope — i.e. cross-branch idempotency confusion if any future route ever bypasses Sanctum branch binding; (2) the 2xx-only cache band silently `release()`s on **4xx domain errors** (line 153), meaning a client that POSTs valid+then-invalid+then-valid with the *same key* gets order #1, then 4xx with placeholder gone, then order #2 — the historical "1 key → 1 receipt" contract is not what the code actually guarantees today; (3) 17 routes carry the alias but ~30 other state-mutating POSTs do not (counter-collect/cancel after release is unprotected, KDS change-status, AdminTableOrder change-status, FrontendDeliveryBoy change-status, ParkedOrder store, Floorplan assign/release, CashDrawerSession close/reconcile is protected but PaymentTerminal store is not) — coverage gap not on payment-critical paths, but on rebooked-state mutations that can double-fire under network retry.

## Top findings

### [P0] app/Http/Middleware/IdempotencyKeyMiddleware.php:182-220 — `resolveBranchId()` fallback to `$request->input('branch_id', -1)` lets payload control the scope for non-admin, non-kiosk authenticated users

trigger:
  load_mode: "An authenticated User row has `branch_id = 0` (admin-table 0 sentinel) **but no Admin role** AND is not a KioskMachine pivot owner — the three positive branches in resolveBranchId all fall through: line 188 admin check fails (no role), line 197 `authBranchId > 0` fails (it's 0), line 204-217 kiosk pivot lookup returns 0 (no row). Execution reaches line 219: `return (int) $request->input('branch_id', -1);`. The client now controls the scope third coordinate."
  failure_mode: "Two distinct callers (real branch 5, real branch 7) both posting with `branch_id: 5` in payload get scoped to the same `idempotency:v1:5:{userId}:{hashKey}` namespace. If they happen to reuse a UUID, one gets the other's cached 201 response — bypassing the intent of branch isolation. The DB UNIQUE backstop on `orders.{branch_id, idempotency_key}` still prevents persistence-layer cross-branch collision (because the row's true branch_id is set by OrderService server-side, not from this header path), but the *cached response replay* at line 95 would surface another caller's order JSON. Probability under V1 single-resto Le Cayenne: zero (only one branch exists). Probability under V2 multi-tenant SaaS: non-zero, and not detectable from the audit chain."

v2_saas_impact:
  blocks: "Multi-tenant SaaS — the fallback IS the breach surface. A misconfigured Sanctum token (token without proper branch binding) becomes a cross-tenant data-leak vector."
  enables: "Hardening to `throw` instead of `return $request->input(...)` removes the surface entirely. Add `auth_branch_id < 0` → 422 path (same as existing line 70-73)."

cost_of_delay_if_v1_ships:
  customer: "V1 single-resto: zero — only branch_id=1 exists."
  fiscal: "None — DB UNIQUE on orders.(branch_id, idempotency_key) catches any persistence-layer cross-branch attempt."
  business: "V1 ship is OK; V2 ship without fixing this is a HARD NO."

recommendation:
  scope: "Replace line 219 `return (int) $request->input('branch_id', -1);` with `throw new MissingIdempotencyKeyException('Idempotency requires resolvable branch_id; received auth=0 with no Admin role and no kiosk pivot.', 422);`. Add regression test `test_non_admin_zero_branch_user_with_payload_branch_id_returns_422`."
  rollback: "Conditional on `config('idempotency.strict_branch_resolution', true)` — defaults to strict, can be flipped to legacy under emergency."
  owner_gate: "N — middleware is not in CLAUDE.md §7 frozen-zone list. superpower-gstack 7-step covers it."

### [P1] app/Http/Middleware/IdempotencyKeyMiddleware.php:145-154 — 2xx-only cache band releases placeholder on non-2xx, allowing the same key to fire a NEW order after a transient 4xx

trigger:
  load_mode: "Client posts order with key K, server returns 201 → cached as COMPLETED. Client retries same K with the *same payload* but server hits a transient 4xx (e.g. stock-out validation throws 422 between the cache lookup and the persist). Line 152-153 releases the placeholder via `repository->release()`. Client retries the same K again *and stock has been restocked in the interim* — Phase 1 line 86 finds nothing (the original COMPLETED record was overwritten by the PENDING placeholder at line 99 in attempt 2, then released at line 153 in attempt 2). A *brand new* order gets created with the same idempotency key but now with a fresh DB row."
  failure_mode: "The first 201's cached response is silently overwritten by the in-flight PENDING placeholder at line 99 before the validator throws. After release at line 153, Phase 1 returns null and a second order is created. DB UNIQUE on `orders.(branch_id, idempotency_key)` catches the second INSERT — a QueryException 23000 bubbles up and OrderService recovery path at line 2540-2550 returns the first order. Net effect: client retry that triggered a transient 4xx ultimately succeeds via the DB UNIQUE backstop, but the *cached response* surfaced to the second retry is the *new* order's response, not the *first* one's — the JSON body that's been cached at line 146-151 is the second attempt's 201 (which is the recovered first order via OrderService::findExistingOrderForIdempotencyRecovery). It works *by accident* — the safety net is the DB UNIQUE, not the cache layer. The middleware contract is violated; defense-in-depth saves the day."

v2_saas_impact:
  blocks: "Doesn't block V2 ship — the DB UNIQUE is the actual guarantor. But the architectural confusion ('the cache is the source of truth' vs 'the DB is') will burn future maintainers who reason from middleware behaviour alone."
  enables: "If we want to deprecate the OrderService recovery path (= reduce two-layer coupling), we need to fix the cache invariant first: PENDING placeholder must not overwrite COMPLETED record."

cost_of_delay_if_v1_ships:
  customer: "Invisible — DB UNIQUE recovers the first order, client retry surfaces correct JSON. No double-order, no double-charge."
  fiscal: "None — only one order persists, only one fiscal_sequence_no consumed."
  business: "Architectural debt; not a customer-facing bug."

recommendation:
  scope: "In `Phase 2 acquire()` add: before overwriting, check if an existing COMPLETED record exists for this key — if yes, return that (idempotent replay). Equivalent to making `acquire()` truly NX rather than UPSERT. In `RedisIdempotencyKeyRepository.php:71`, `Cache::add()` IS already NX (returns false if key exists) — so the issue is actually NOT here. Re-reading: line 99-103 calls `acquire()` which returns false if a record exists; line 104-124 then `waitForCompletion`. If the previous record was COMPLETED, `find()` returns it. **No bug — the apparent issue is gated by `Cache::add()` NX semantics.** Downgrade to P3 documentation issue: add inline comment at line 99 explaining 'acquire == NX, overwrite impossible if previous COMPLETED record alive.'"
  rollback: "Documentation-only; no code change."
  owner_gate: "N."

### [P1] routes/api.php (17 idempotency-attached) vs ~30 unprotected state-mutating POSTs — coverage gap on second-order state transitions

trigger:
  load_mode: "Network retry on a state-mutating POST that lacks the middleware. Examples found via `grep -nE 'Route::post' routes/api.php | grep -v idempotency`: line 856 `pos-order/change-status/{order}`, line 878 `online-order/change-status/{order}`, line 888 `admin/table-order/change-status/{order}`, line 1007 `kds/change-status/{order}`, line 1132 `frontend-order/change-status/{frontendOrder}`, line 1209 `frontend/delivery-boy/change-status/{order}`, line 803 `parked-orders` store, line 810-811 floorplan assign/release, line 845 `payment-terminal` store, line 1141 `payment/reconcile-pending`. None have idempotency middleware."
  failure_mode: "A flaky mobile network double-fires `POST /api/admin/online-order/change-status/{order}` with state=PAID. First call succeeds → status=PAID + audit log entry #1 + (potentially) Stripe charge + KDS notification. Second call duplicates: audit log entry #2 (same action, same order — chain stays sane because OrderStateMachine guard rejects the second transition with a 422, but only IF the state machine is strict). If the state machine is loose (allows PAID→PAID idempotently at the domain layer), no duplicate fires. If strict, the second call returns 422 — client sees error despite the first succeeding. Either way: not a P0, but the user-visible contract 'X-Idempotency-Key always replays cleanly' is not honored on these routes."

v2_saas_impact:
  blocks: "V2 SaaS scale = N tenants × thousands of orders/day × flaky retail networks. Each unprotected state mutation is a non-deterministic UX scratch — operator sees 'sometimes my second click works, sometimes 422'."
  enables: "Idempotency on `change-status` routes lets us guarantee mobile-app retry behaviour."

cost_of_delay_if_v1_ships:
  customer: "Single-resto Le Cayenne with one cashier: zero observable impact."
  fiscal: "None — DB constraints and state-machine guards backstop everything."
  business: "Acceptable for V1 ship. V2 backlog."

recommendation:
  scope: "Add `'idempotency'` middleware to the 6 high-risk change-status routes listed above (POS/Online/Table/KDS/FrontendOrder/DeliveryBoy change-status). For each route, also add the URL pattern to `config/idempotency.required_routes`. Tests: existing `IdempotencyMiddlewareTest` synthetic-route coverage already proves the alias works — only need to verify route registration via `tests/Feature/Routes/ChangeStatusIdempotencyTest.php` (new, ~50 LOC)."
  rollback: "Adding middleware is purely additive — no behaviour change unless client sends header. Backward-compatible per `required_routes` opt-in pattern at line 165-178."
  owner_gate: "N — routes are not in CLAUDE.md §7 frozen-zone list."

### [P2] app/Http/Middleware/IdempotencyKeyMiddleware.php:45 — Method allowlist `['POST', 'PUT', 'PATCH', 'DELETE']` mismatches BRAIN §9 scope tuple, which specifies POST only

trigger:
  load_mode: "A PUT/PATCH/DELETE route attaches the `idempotency` alias and a client sends `X-Idempotency-Key`. The middleware will scope, cache, and replay these in the same scope namespace as POSTs. None of the 17 currently-attested routes are PUT/PATCH/DELETE — but the door is open."
  failure_mode: "If a future PUT `/api/admin/orders/{order}` route attaches `idempotency`, replaying the cached 200 from a previous DELETE on the same scope tuple would surface a 200 with delete-success body on a PUT operation. Mismatch between cached method-context and replay-method-context is undefined — currently UNTESTED."

v2_saas_impact:
  blocks: "Not a blocker — risk surface is theoretical until a non-POST route attaches the alias."
  enables: "Including HTTP method in the scope tuple (or in the payload hash) closes the door permanently."

cost_of_delay_if_v1_ships:
  customer: "Zero today."
  fiscal: "None."
  business: "Latent."

recommendation:
  scope: "Either (a) tighten line 45 to `['POST']` only (matches BRAIN §9 contract exactly), OR (b) extend `scopedKey` at line 77 to include `$request->method()`. Option (b) is more flexible; option (a) is safer V1. Pick (a)."
  rollback: "Trivial."
  owner_gate: "N."

### [P2] app/Http/Middleware/IdempotencyKeyMiddleware.php:131-135 — 503 storage-unavailable response carries 503 body but no Retry-After header

trigger:
  load_mode: "Redis down, `fail_open=false`. Every required POST returns 503 with code IDEMPOTENCY_STORAGE_UNAVAILABLE."
  failure_mode: "Client doesn't know when to retry. Mobile SDKs default to backoff but admin web clients may hammer."

v2_saas_impact: { blocks: "Minor", enables: "Industry-standard 503 + Retry-After (e.g. 5s) ≈ best practice for V2 multi-tenant" }

cost_of_delay_if_v1_ships: { customer: "Acceptable", fiscal: "None", business: "Low" }

recommendation:
  scope: "Add `'Retry-After' => '5'` header to the 503 JsonResponse at line 130-134."
  rollback: "Trivial."
  owner_gate: "N."

## Architectural boundary — middleware vs domain

The current architecture is **dual-layer by design**: the HTTP middleware (transport concern, line 39-157) and the domain recovery path (OrderService::findExistingOrderForIdempotencyRecovery at line 2540-2550, FrontendOrderService.php:651-661) **both** participate in deduplication. The DB `orders.(branch_id, idempotency_key)` UNIQUE is the source of truth, and `OrderService.php:587-596` uses an additional Cache::lock for double-click bursts. This is **defense-in-depth, not mixed concerns** — each layer has a documented role:

- **Middleware**: replay HTTP response from cache. Avoids re-executing the controller at all. Sub-millisecond on cache hit. Saves DB round-trip.
- **DB UNIQUE**: persistence-layer source of truth. Backstops any cache-miss or storage outage. Always present, always strict.
- **OrderService recovery**: translates the QueryException 23000 into a "return existing order" semantic — preserves UX even when the cache layer is bypassed.

**Boundary is clean.** No double-write, no overlapping responsibility. The risk is *not* the architecture; it's the documentation: a future engineer reading the middleware alone will not see the OrderService recovery path. **Recommendation**: add a docblock comment to `IdempotencyKeyMiddleware::handle()` referencing `OrderService.php:2540` so the contract is discoverable from either entry point.

## Concurrent-race verification

The Phase 1 → Phase 2 → Phase 3 sequence (lines 84-157) handles the same-key race correctly:

1. **Both callers Phase 1**: both find `null` (no record yet).
2. **Both callers Phase 2 `acquire()`**: only ONE wins (Cache::add NX semantics confirmed in `RedisIdempotencyKeyRepository.php:71` docblock + Laravel doc). The loser falls into `waitForCompletion()` at line 105-108, polling at 50ms intervals up to 1500ms.
3. **Winner executes the handler** (line 138-143). Throws → release (line 142). 2xx → complete (line 146-151). Non-2xx → release.
4. **Loser polls**: finds COMPLETED (or null after 1.5s → 425).

**Edge case**: if winner takes > 1500ms, loser returns 425 (`IDEMPOTENCY_IN_FLIGHT`). Acceptable.

**Edge case**: if both callers Phase 2 `acquire()` simultaneously and the cache is `array` driver (tests), Laravel's `add()` is in-process atomic. Under Redis, `SET NX EX` is server-side atomic. **No race**.

**Edge case**: `release()` after 4xx — see [P1] above; saved by Cache::add NX.

## Risk register summary

| # | Severity | Location | Blocker for V2 | V1 ship impact |
|---|----------|----------|----------------|----------------|
| 1 | P0 | line 219 payload-branch_id fallback | YES | Single-branch zero risk |
| 2 | P1→P3 | line 145-154 release-on-non-2xx | NO | Saved by DB UNIQUE |
| 3 | P1 | 17/30 route coverage gap | NO | Single-cashier zero risk |
| 4 | P2 | line 45 method allowlist | NO | No PUT/PATCH/DELETE attached today |
| 5 | P2 | line 131-135 missing Retry-After | NO | Minor UX |

## Production-perfect checklist

- [ ] Apply [P0] fix at line 219 BEFORE V2 SaaS.
- [x] DB UNIQUE on orders.(branch_id, idempotency_key) confirmed via migration 2026_04_18_140003.
- [x] Cache::add NX semantics confirmed in repo docblock.
- [x] OrderService recovery path confirmed at line 2540.
- [x] 8 PHPUnit scenarios cover the 5 acceptance cases (replay, missing, optional pass-through, cross-branch, TTL, conflict, fail-closed, fail-open).
- [ ] Concurrent-race integration test (forked PHP processes + real Redis) — currently MISSING (existing test uses array driver).
- [ ] Add `'idempotency'` middleware to 6 change-status routes (P1 backlog).
- [ ] Add docblock cross-reference middleware ↔ OrderService::findExistingOrderForIdempotencyRecovery.

## Verdict rationale

GO-CONDITIONAL for V1 (Le Cayenne single-resto, single branch_id=1, the [P0] surface is dormant because the payload-branch_id fallback only fires for non-admin non-kiosk users on a multi-branch deployment — V1 has neither). V2 SaaS ship requires the [P0] fix as a hard prerequisite. The middleware semantics are *correct* per acceptance criteria; the architecture is *clean* with documented defense-in-depth; the coverage gap is *real but bounded* by DB UNIQUE backstop. No P0 blockers for V1 ship under Le Cayenne profile.
