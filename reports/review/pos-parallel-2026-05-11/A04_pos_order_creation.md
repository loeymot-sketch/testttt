# A04 — POS Order Creation Flow (Idempotency, Validation, Race) — 2026-05-11

> Sub-agent : **A04** of 20 (parallel adversarial POS audit).
> HEAD : `a220b9bd8` — branch `feature/mobile-app-le-cayenne-2026-05-10`.
> Scope (strict) : `Admin/PosController::store`, `Http/Requests/PosOrderRequest`, `IdempotencyKeyMiddleware`, `App/Services/Idempotency/*`, `Http/Kernel.php`, `routes/api.php` POS POST group, `config/idempotency.php`, idempotency-flavored tests. READ-ONLY, file:line verified.
> Frozen zones (referenced only, never modified) : `OrderService.php` (esp. `posOrderStore` lines 563-1065).

---

## §0 P0-05 Verification (past-audit retraction confirmed)

**Past audit (`reports/review/pos-ultra-audit-2026-05-09/99_CORRIGENDUM.md`) RETRACTED P0-05** which had claimed `config/idempotency.php` was a "fabricated" file. **A04 confirms the retraction is correct.** Fresh evidence at HEAD `a220b9bd8` :

- `config/idempotency.php` exists — 38 lines, real (not fabricated). Verified by Read tool.
- `config/idempotency.php:21` : `'enabled' => env('IDEMPOTENCY_MIDDLEWARE_ENABLED', false)`.
- `config/idempotency.php:26-35` `required_routes` includes `api/admin/pos` (line 28) and 6 sibling state-mutating POST routes.
- `app/Http/Kernel.php:98` : `'idempotency' => \App\Http\Middleware\IdempotencyKeyMiddleware::class` alias registered.
- `routes/api.php:728` : `Route::post('/', [PosController::class, 'store'])->middleware(['throttle:pos-order-create', 'idempotency']);` — middleware actually wired.
- `.env:92` : `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` — runtime enabled on this checkout.
- Service wiring : `app/Providers/AppServiceProvider.php:50-51` binds `IdempotencyKeyRepository::class` → `RedisIdempotencyKeyRepository` (cache-backed, atomic `Cache::add()` SET-NX-equivalent at `RedisIdempotencyKeyRepository.php:71`).
- Sentinel coverage of the real wiring : `tests/Feature/Sentinels/IdempotencyMiddlewareSentinelTest.php:44-66` posts to live `/api/admin/pos` without header, asserts 422 + zero rows.

**Verdict** : the past P0-05 claim was the fabrication — not the file. P0-05 stays retracted. **No P0 finding to add for this point.**

---

## §1 Findings

### P0 — none in this slice

Order-create path is defended in depth :
1. `IdempotencyKeyMiddleware` (HTTP layer, flag-on).
2. `Cache::lock('pos_order_idempotency_' . sha1(branch|key), 10)` + `->block(5)` (`OrderService.php:587-591`).
3. `findExistingOrderForIdempotencyRecovery` pre-check (`OrderService.php:592-596`, helper at `:2363-2375`, branch-scoped on `idempotency_key` + `branch_id`).
4. DB UNIQUE composite `(branch_id, idempotency_key)` (migration `2026_04_18_140003_scope_idempotency_key_to_branch.php`).
5. `catch QueryException 23000` recovery (`OrderService.php:1046-1058`) re-resolving via the same helper.

This 5-layer defense is sentinel-locked (`F006PosIdempotencyParitySentinelTest.php:39-95`) so a future refactor cannot silently weaken any of them.

### P1 — coverage & wiring weaknesses

**P1-A04-001 — `.env.example` does NOT set `IDEMPOTENCY_MIDDLEWARE_ENABLED`.**
- File : `.env.example` (verified : no match for `IDEMPOTENCY_MIDDLEWARE_ENABLED` or `IDEMPOTENCY_*`).
- File : `config/idempotency.php:21` defaults to `false` when env var absent.
- Defect : fresh deployments inheriting `.env.example` are **silently fail-open** at the HTTP layer. The middleware short-circuits at `IdempotencyKeyMiddleware.php:41` (`if (! config('idempotency.enabled', false)) return $next($request);`) — controller is reached without idempotency enforcement. Defense-in-depth survives (helper + 23000 catch still active), but the headline guarantee from `IdempotencyMiddlewareSentinelTest::test_pos_store_without_idempotency_header_is_rejected_422` does NOT hold on a fresh prod deployment.
- Impact : new branches / staging clones lose the 422-on-missing-header contract, which is the only path that *prevents* duplicate handler execution. Cross-validate with A13 (deploy/SRE) and A19 (env hygiene).
- Suggested remedy : add `IDEMPOTENCY_MIDDLEWARE_ENABLED=true` to `.env.example` (and `IDEMPOTENCY_FAIL_OPEN=false`).

**P1-A04-002 — `PosOrderRequest::authorize() { return true; }` (`app/Http/Requests/PosOrderRequest.php:44-47`).**
- File : `app/Http/Requests/PosOrderRequest.php` line 44-47.
- Defect : the FormRequest gate is unconditional. Defense currently relies entirely on `PosController.php:39` `$this->middleware(['permission:pos'])->only('store')`. If a future route file moves the `store` mapping (or someone calls `PosOrderRequest` from a different controller, eg a queue replay job), authorization silently disappears. Best-practice Laravel is to mirror the permission inside the request : `return $this->user()?->can('pos') ?? false;`.
- Impact : low *today* (defended by controller middleware), but the project's own audit doctrine (CLAUDE.md §10 "validation evidence quality") asks for defense-in-depth. Cross-validate with A14 (RBAC).
- Suggested remedy : `return $this->user()?->can('pos') ?? false;` in `authorize()`.

**P1-A04-003 — `IdempotencyMiddlewareTest` exercises synthetic `__test/required` routes, not `/api/admin/pos`.**
- File : `tests/Feature/Idempotency/IdempotencyMiddlewareTest.php:53-69` registers a `Route::post('/__test/required', …)` to exercise the middleware in isolation.
- Defect : 7 of the 8 scenarios (replay, 422-missing, 409-conflict, cross-branch, TTL expiry, fail-open, fail-closed) never touch the real POS controller stack. Only `IdempotencyMiddlewareSentinelTest::test_pos_store_without_idempotency_header_is_rejected_422` actually posts to `/api/admin/pos` — and even that variant short-circuits before the controller logic (the test asserts the controller never ran). There is **no test that** verifies a successful order create + cached replay against the real `OrderService::posOrderStore` path (the cached body would then need to include the `OrderDetailsResource` JSON, not a stub).
- Impact : gap in production wiring confidence. The middleware → controller seam (auth:sanctum / permission:pos / throttle:pos-order-create → idempotency → `posOrderStore`) is not E2E-tested. A regression that, eg, places `idempotency` *before* `auth:sanctum` (so the middleware sees `userId=0` at `IdempotencyKeyMiddleware.php:68-74` and throws `MissingIdempotencyKeyException`) would slip past CI.
- Suggested remedy : add a Feature test that posts to `/api/admin/pos` with a complete payload, sees a 201 + `OrderDetailsResource`, then re-posts the same key + payload and asserts `Idempotency-Replayed: true` plus the **same** `order_id`.

**P1-A04-004 — `IdempotencyKeyMiddleware::isRouteRequired()` does NOT trim trailing slashes in the matcher.**
- File : `app/Http/Middleware/IdempotencyKeyMiddleware.php:175` : `if ($request->is(ltrim($pattern, '/'))) return true;`.
- Defect : `Request::is()` uses `Str::is()` glob matching against the *resolved* request path (no trailing slash). If `config/idempotency.php` author ever adds a pattern like `api/admin/pos/` (trailing slash) it will silently miss. There is no normalization. Less hypothetical : the URL `/api/admin/pos/` is identical to `/api/admin/pos` in routing (Laravel collapses) — but the config uses `api/admin/pos` (no slash), so today this is fine. Still, the `'name:pos.store'` route-name branch (lines 169-173) is **never used** because the POS POST has no `->name('store')` chain (the surrounding group is `pos.` but the route itself has no `name`). So all matching falls back to path-glob.
- Impact : maintenance fragility — a typo would silently downgrade `/api/admin/pos` to "optional" and the route would accept missing headers. Worth a sentinel.
- Suggested remedy : add a unit test `IdempotencyMiddlewareSentinelTest::test_required_routes_config_matches_actual_pos_post_path` that asserts `$request->is('api/admin/pos')` returns true against the live route URL.

### P2 — minor

**P2-A04-005 — `RedisIdempotencyKeyRepository::release()` swallows all exceptions silently.**
- File : `app/Services/Idempotency/RedisIdempotencyKeyRepository.php:98-105` — `try { $this->store()->forget($scopedKey); } catch (\Throwable) { }`.
- Defect : `release()` is called on handler exception (`IdempotencyKeyMiddleware.php:141`). If the cache store is unreachable at that moment, the placeholder will hang for full TTL (default 86400s = 24h), blocking legitimate retries with `425 IDEMPOTENCY_IN_FLIGHT`. The placeholder expires eventually, but a Redis flap during a peak shift could mean 24h of "stuck" keys per session.
- Suggested remedy : log a warning even on Throwable (mirror the `complete()` path lines 90-94).

**P2-A04-006 — `IdempotencyKeyMiddleware::resolveBranchId()` falls back to `request->input('branch_id', -1)` for non-Admin non-Kiosk users (`IdempotencyKeyMiddleware.php:219`).**
- File : `app/Http/Middleware/IdempotencyKeyMiddleware.php:182-220`.
- Defect : the fallback trusts the **client payload** for branch scoping when the authenticated user has `branch_id=0` AND is not an Admin AND no KioskMachine row matches. This is a narrow path (it requires a non-Admin user with `branch_id=0` — typically misconfigured staff), but if it triggers, the idempotency scope becomes attacker-controlled : eg, an attacker can spoof `branch_id=99` in the JSON and bypass replay for a key already cached against a different branch. The `branchId < 0` guard at line 70 rejects -1 (i.e., no branch_id in payload), which softens this, but `branch_id=1` in payload would *not* reject. Net : the middleware will scope `idempotency:v1:1:<userId>:<keyHash>` to whatever branch the client claims — replay collision attack window.
- Impact : low because non-Admin + branch_id=0 should never happen in production (Kiosk path is handled), but worth tightening. Cross-validate with A13 (branch isolation).
- Suggested remedy : if not Admin, only trust `Auth::user()->branch_id`; refuse the request with 401/403 if it cannot resolve, rather than reading from payload.

**P2-A04-007 — `IdempotencyKeyMiddleware` does NOT verify response Content-Length / streaming responses.**
- File : `app/Http/Middleware/IdempotencyKeyMiddleware.php:138-156` — calls `$response->getContent()` to cache body. For a `StreamedResponse` this returns `false` and the replay body is empty. POS `store` returns `OrderDetailsResource` (regular `JsonResponse`) so today OK, but a future export-style endpoint added to `required_routes` would silently cache an empty replay. Add a guard `if ($response instanceof StreamedResponse) skip cache;` or fail loud.

### P3 — minor / cleanup

**P3-A04-008 — `PosController::store` catches `Exception` (line 51) and rewraps as 422.**
- File : `app/Http/Controllers/Admin/PosController.php:51-53`.
- Defect : same blanket-`Exception` pattern flagged by A02-P2-A02-008. Worth nothing further here besides cross-reference.

**P3-A04-009 — `PosOrderRequest::prepareForValidation()` reads `app(DeliveryFeeService::class)` (line 27) to compute `delivery_charge` from `delivery_distance_km`.**
- File : `app/Http/Requests/PosOrderRequest.php:24-30`.
- Defect : business computation in a FormRequest. Same logic is also done by `PosController::normalizePosRuntimePayload` (`PosController.php:131-135`). Duplication risk — `DeliveryFeeService` pricing change would need to be remembered in both places.

---

## §2 Cross-validation watch list

- **A02 (architecture/layering)** — already raised the controller-as-validator and `try/catch Exception` patterns. P1-A04-002 (authorize: true) reinforces P1-A02-002 (no PosQuoteRequest).
- **A13 (branch isolation / multi-tenant)** — P2-A04-006 branch fallback ; also confirm that `IdempotencyMiddlewareSentinelTest::test_existing_branch_scoped_recovery_still_works_with_middleware_active` (lines 171-203) remains green.
- **A14 (RBAC)** — P1-A04-002 (`authorize()` gate).
- **A19 (env / SRE)** — P1-A04-001 (.env.example).
- **A11 / A12 (Frontend)** — `tests/js/posOrderIdempotency.spec.js:23-46` proves the Vuex `posOrder.save` action sends `X-Idempotency-Key` aligned with `payload.idempotency_key`. The frontend contract is sentinel-pinned ; A11 should re-check that the wizard re-uses the same UUID across a slow-network retry (not generate a new one).

---

## §3 Suggested Playwright E2E scenarios

1. **Double-tap idempotency (happy path)** — sign in as POS Operator, open `/admin/pos`, fill cart, capture `payload.idempotency_key` from the Vuex store, programmatically POST twice to `/api/admin/pos` with identical body + identical `X-Idempotency-Key`. Assert : both responses 201, both `order_id` identical, second response has header `Idempotency-Replayed: true`. DB has exactly 1 row matching that key. KDS receives 1 ORDER_CREATED event (not 2).
2. **Same key, different payload → 409 conflict** — POST `idempotency_key=K1` with cart A, then POST same key with cart B (different `total_price`). Assert : second is 409 with `code: IDEMPOTENCY_KEY_CONFLICT` and `Idempotency-Key-Conflict: true` header. DB has 1 row (cart A only).
3. **Race two simultaneous tabs** — open the POS in two tabs, click `valider` in both within <100ms. Both should target `/api/admin/pos` with the same `X-Idempotency-Key` (because Vuex regenerates only between checkouts). Assert : exactly one 201 + one of {201 replay, 425 IDEMPOTENCY_IN_FLIGHT}; never two distinct `order_id` ; KDS receives 1 event ; cash drawer drawer-amount delta is single-counted.
4. **Header missing on required route → 422** — disable the frontend's automatic header injection (devtools network override), POST raw to `/api/admin/pos`. Assert : 422 with `code: MISSING_IDEMPOTENCY_KEY`. No order row created.
5. **Auth missing → 401 BEFORE idempotency runs** — log out, POST to `/api/admin/pos` with a valid `X-Idempotency-Key`. Assert : 401 from `auth:sanctum`, NOT 422. This pins the middleware order on the real route (cross-validates P1-A04-003).

---

## §4 Verdict

- P0 : **0** (past P0-05 retraction reaffirmed — file is real, middleware is wired, flag is on in `.env`).
- P1 : **4** (env.example default, FormRequest authorize, missing E2E test on real /api/admin/pos, route-glob brittleness).
- P2 : **3** (release silent failure, branch fallback to payload, streamed response cache).
- P3 : **2** (catch Exception 422 mask, prepareForValidation duplicate compute).

Order creation flow is **production-grade with defense-in-depth**. The middleware is real and wired ; the helper + Cache::lock + DB UNIQUE + 23000 catch chain is sentinel-locked. Main residual risks are *deployment hygiene* (env.example) and *test coverage of the real route* (synthetic-route tests don't pin the wiring). No release blocker from A04's scope.

---

*Evidence verified by file:line at HEAD `a220b9bd8`. Word count ≈ 1480.*
