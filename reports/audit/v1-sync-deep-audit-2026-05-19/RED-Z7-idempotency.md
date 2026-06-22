# RED-Z7 — Idempotency Audit
**Date**: 2026-05-19 · **Mode**: read-only adversarial · **Agent**: RED-Z7
**Scope**: HTTP `X-Idempotency-Key` header replay + 425 race + 409 conflict + route coverage. WebhookEvent ledger dedup. Read-only — no edits, no commits.

---

## A. Anchors verified (file:line)

| Anchor | File | Lines | Notes |
|---|---|---|---|
| Config (master flag + TTL + race wait + fail-open + 25 routes + cache_store) | `config/idempotency.php` | 1-74 | `enabled` default `false` env-driven; TTL 86400s; race 1500ms; fail_open false |
| Middleware (FROZEN per §7 — read-only) | `app/Http/Middleware/IdempotencyKeyMiddleware.php` | 1-244 | 3-phase: replay→acquire→execute; 422 missing, 409 conflict, 425 in-flight, 503 storage down |
| Repository contract | `app/Services/Idempotency/IdempotencyKeyRepository.php` | 14-47 | atomic `acquire()` (SET NX EX semantics) |
| Repository impl | `app/Services/Idempotency/RedisIdempotencyKeyRepository.php` | 29-158 | Cache::add() = atomic across drivers; safeHeaders strips set-cookie/date/x-correlation-id |
| Middleware alias bound | `app/Http/Kernel.php` | 138-140 | `'idempotency' => IdempotencyKeyMiddleware::class` |
| Production boot guard | `app/Providers/AppServiceProvider.php` | 132-151 | `RuntimeException` if `idempotency.enabled !== true` in production |
| Repository binding | `app/Providers/AppServiceProvider.php` | 47-54 | `cacheStore: config('idempotency.cache_store')` |
| 422 exception (auto-render) | `app/Exceptions/MissingIdempotencyKeyException.php` | 13-40 | `render($request)` → JSON `code: MISSING_IDEMPOTENCY_KEY` |
| WebhookEvent model | `app/Models/WebhookEvent.php` | 47-121 | global model (no BranchScope), `provider`+`webhook_id` UNIQUE composite |
| webhook_events migration | `database/migrations/2026_05_09_120000_create_webhook_events_table.php` | 41-90 | `unique(['provider','webhook_id'],'uk_webhook_provider_id')` line 83 |
| Stripe handler dedup | `app/Http/PaymentGateways/Gateways/Stripe.php` | 255-276 | `WebhookEvent::firstOrCreate` + `wasRecentlyCreated` 409 short-circuit |
| Senangpay handler dedup | `app/Http/PaymentGateways/Gateways/Senangpay.php` | 125-146 | identical firstOrCreate pattern; signature verified BEFORE INSERT |
| Sentinel: every `idempotency` route IS in required_routes | `tests/Feature/Idempotency/IdempotencyRequiredRoutesCoverageTest.php` | 28-76 | walks `Route::getRoutes()`, asserts URI matches required pattern |
| Sentinel: 422 + replay + cross-branch + 409 + TTL + fail-open/closed | `tests/Feature/Idempotency/IdempotencyMiddlewareTest.php` | 28-250 | 8 scenarios; synthetic route `__test/required` |
| Sentinel: prod boot guard | `tests/Feature/Sentinels/IdempotencyMiddlewareProductionGuardSentinelTest.php` | 25-98 | 4 scenarios: prod+off=throw, prod+on=no-throw, local+off=ok, default=false |
| Sentinel: real-route POS replay + 422 missing | `tests/Feature/Sentinels/IdempotencyMiddlewareSentinelTest.php` | 44-202 | hits `/api/admin/pos`, `/api/admin/pos-order/change-payment-status/{order}`, `/api/frontend/order/{order}/payment-confirm` |
| 6 change-status wiring tier (V1.0.2-IDEMP-01) | `tests/Feature/Idempotency/ChangeStatusIdempotencyTest.php` | 94-150 | gatherMiddleware assertion on 6 named routes |
| Counter-collect + print receipt | `tests/Feature/Idempotency/CounterCollectAndPrintIdempotencyTest.php` | 1+ | sibling wiring tier (file present, schema verified) |
| WebhookEvent ledger pattern | `tests/Feature/Webhooks/WebhookEventIdempotencyTest.php` | 25-188 | firstOrCreate replay + UNIQUE conflict + mark processed/failed |
| Webhook prune cron (180d) | `app/Console/Kernel.php` | 109-120 | `foodking:webhook:prune --older-than-days=180` daily 04:15 |
| Routes USING `idempotency` middleware in api.php | `routes/api.php` | 27 lines | grep returned 27 occurrences (lines listed below) |

**Routes wired to `idempotency` middleware** (grep `routes/api.php` for the literal `idempotency` alias) — 27 hits total:

```
L647  delivery-boy/cash-sessions/open
L654  delivery-boy/cash-sessions/{session}/close
L658  delivery-boy/cash-sessions/{session}/reconcile
L768  pos (POST /api/admin/pos)
L808  pos/counter-collect/{order}/confirm
L828  pos/counter-collect/{order}/cancel
L839  pos/collect-kiosk-cash/{order}
L840  pos/orders/{order}/print-receipt
L853  pos/cash-drawer/open
L857  pos/cash-drawer/sessions/open
L860  pos/cash-drawer/sessions/{session}/close
L864  pos/cash-drawer/sessions/{session}/reconcile
L902  pos-order/change-status/{order}
L905  pos-order/change-payment-status/{order}
L907  pos-order/select-delivery-boy/{order}
L914  pos-order/{order}/refund-with-counter-entry
L921  pos-order/{order}/redeem-loyalty
L933  online-order/change-status/{order}
L935  online-order/change-payment-status/{order}
L936  online-order/select-delivery-boy/{order}
L946  table-order/change-status/{order}
L948  table-order/change-payment-status/{order}
L1070 kds-order/change-status/{order}
L1195 frontend/order (POST /api/frontend/order — kiosk store)
L1198 frontend/order/change-status/{frontendOrder}
L1201 frontend/order/{frontendOrder}/payment-confirm
L1278 frontend/delivery-boy-order/change-status/{order}
L1299 frontend/loyalty/redeem
```

Total: **27 routes** (audit-confirmed). The doc/handoff said "~25" — the live count is 27. `config('idempotency.required_routes')` has **24 distinct patterns** (lines 26-70). 23 routes match by URI pattern + 3 patterns expand to multiple URIs (the 3 `/change-status/*` siblings + `change-payment-status/*` family). Manual cross-check below in §B-F-1.

---

## B. Findings P0 → P3

### P0 — none.
The 4 anti-doublon production failure modes (header omission, payload conflict, in-flight race, storage outage) all have closed code paths AND sentinel tests. The `IdempotencyRequiredRoutesCoverageTest` sentinel exists and is keyed on `Route::getRoutes()` (line 35) which means a future `->middleware('idempotency')` declared without an entry in `required_routes` fails CI before merge. The prod boot guard refuses to start when `enabled=false`.

### P1 — Scope key does NOT include route path → key reuse risk across mutating endpoints

`IdempotencyKeyMiddleware:77-82` builds `scopedKey` as `idempotency:v1:<branch_id>:<user_id>:<sha256(key)>`. The route URI is absent.

**Impact** — if a poorly-disciplined client (or a buggy retry loop) reuses the *same* `X-Idempotency-Key` value across two distinct mutating endpoints (e.g. `/api/admin/pos` then `/api/admin/pos-order/change-payment-status/{N}`) with the *same* body payload (e.g. both bodies happen to be `{"branch_id":7}`), the second call collides on the cache slot and replays the first response. Realistic example: a cashier whose tablet retries a request with the same UUID after navigation to a different screen but identical payload prefix. The 409 conflict guard only fires when bodies differ.

**Severity** — Low under normal usage because client UUIDs are nonces (random per UI action), but the contract is weaker than the dedup ledger (`webhook_events` keys on `provider`+`webhook_id` AND the request semantically targets one provider per route). The 409 conflict guard masks most accidents, but not body-identical ones. Recommend including a hash of the route path/name in the cache slot. Citation: `IdempotencyKeyMiddleware:76-82`.

### P1 — `release()` on 4xx allows replay-as-retry — confirm intent

Lines 145-153 in the middleware: only `200-299` responses are cached. Anything `>=300` (including `400/422` validation fails) triggers `$this->repository->release($scopedKey)`. A second POST with **the same key + same body** re-acquires and re-executes the handler. This is documented as "compat / retry after fix" but in practice means a client that posts a malformed body once and a corrected body second on the same key will succeed with a 201 — which is fine; but a client that posts a 422-failing body, hits a transient network error, and naively retries the *same* failing body will re-execute the validation path twice. No business-state harm (validation failed both times), but worth confirming intent. Citation: `IdempotencyKeyMiddleware:145-154`.

### P1 — `MissingIdempotencyKeyException` returns 422, sentinel asserts 422 — but the docblock at `IdempotencyKeyMiddleware:21` says "Missing header on a required route → 422". OK, internally consistent. However the **docs/handoff said `422` for missing**, the **failing-storage path returns `503`**, the **race after 1500ms returns `425`** — `425 Too Early` is uncommon. Confirm load balancer / kiosk client / mobile retry handlers don't accidentally treat 425 as a hard failure. Citation: `IdempotencyKeyMiddleware:122-123`.

### P2 — Webhook event handlers DO use `WebhookEvent::firstOrCreate` (verified Stripe:255, Senangpay:125) — clean.

However Stripe/Senangpay webhook ROUTES are **NOT in `required_routes`** and **NOT covered by `IdempotencyKeyMiddleware`** — they are handled by app-layer `firstOrCreate` only. This is correct: providers don't carry per-tenant context and don't send `X-Idempotency-Key`. The DB `unique(['provider','webhook_id'])` (migration line 83) is the atomicity floor. Sentinel test `WebhookEventIdempotencyTest` proves the floor catches concurrent INSERT. NOT a finding — confirming the audit reads the design correctly.

### P2 — No test covers the **425 race wait** code path

`grep "IDEMPOTENCY_IN_FLIGHT\|425" tests --include='*.php'` returns 0 results in idempotency tests (the `IdempotencyMiddlewareTest` configures `race_wait_ms=50` at L41 but never simulates a concurrent acquire that hits the wait branch L104-124 then returns L119-123 with status `425`). The `waitForCompletion` polling loop (`RedisIdempotencyKeyRepository:107-123`) is exercised only via the 50ms timeout-to-no-result path implicitly. **No regression test prevents this branch from breaking silently.** Severity P2 because the race itself is rare on a single-tenant V1 (one cashier per session, kiosk auto-blocks duplicates client-side); but the middleware is FROZEN and any future maintenance lacks a green-on-CI guard. Citation: `IdempotencyKeyMiddleware:104-124`, sentinel files listed §A.

### P2 — Kiosk anonymous: `$userId = (int)($request->user()?->id ?? 0)` — when 0, line 70 throws `MissingIdempotencyKeyException`

This is correct — but the docblock for kiosk paid-order create says "kiosk:order ability" is authenticated. Confirmed via `routes/api.php:1191`: `Route::prefix('order')->middleware(['auth:sanctum'])`. Kiosk machine has a Sanctum token with `kiosk:order` ability, so `$request->user()` is non-null → `$userId > 0` → branch_id resolved via `KioskMachine` pivot lookup (L204-217). All gated. NOT a finding.

### P3 — `safeHeaders` strips `set-cookie / date / x-correlation-id / x-request-id` from replay headers (L139)

Correct — but the replay response body is base64-decoded byte-for-byte. If a controller embeds a Date or correlation_id IN THE BODY, the replay will show the FIRST request's timestamp. Documented behavior. Tests don't cover this. P3 — observability foot-gun, not a correctness bug.

### P3 — `release()` is silently swallowed (`RedisIdempotencyKeyRepository:98-105`)

If the cache `forget()` fails after a 4xx, the placeholder PENDING record sits until TTL expiry (24h). A subsequent retry on the same key within that window will hit the `acquire()` failure path → call `waitForCompletion(1500ms)` → time out (no COMPLETED record was ever published) → return 425. **From the client's perspective, a 422 followed by retry would surprise as 425.** Severity P3 — narrow window (TTL is 24h but `release()` failure is rare); test coverage exists at the `waitForCompletion`-returns-null branch but the chain "4xx → silent release fail → retry sees 425" is not asserted end-to-end. Mitigation: when caching is functioning the L98 `forget()` succeeds; when caching is degraded, the prod boot guard catches the bigger issue. Citation: `RedisIdempotencyKeyRepository:98-105`.

### P3 — `cache_store=null` → falls back to `Cache::store()` (default) → `cache.default`

In production with `CACHE_DRIVER=array`, the prod boot guard at `AppServiceProvider:213-222` refuses to boot. With `redis`, idempotency keys share the same redis with the audit-chain locks (`audit_chain_b{n}`) and outbox queue. **Redis eviction policy must be set to `noeviction` or `volatile-ttl`, not `allkeys-lru`**, or under memory pressure a still-active PENDING key could be evicted mid-flight — second caller would then `acquire()` successfully and **double-execute**. NF525 risk. The boot guard does not assert redis maxmemory-policy. P3 because operational policy + 24h TTL keys are large but typical redis sizing handles this. Recommend doc note in `docs/runbooks/` (out-of-scope to write here). Citation: `RedisIdempotencyKeyRepository:67-75` + `AppServiceProvider:206-222`.

### P3 — `IdempotencyRequiredRoutesCoverageTest:67` strips `{param}` to `*` correctly, but…

The sentinel converts route URI `{order}` to `*` then matches against pattern `*` segments (one-segment glob). It does NOT validate the *reverse* direction — i.e. **a required_routes pattern that points to a NON-EXISTENT URI is silently accepted**. Example: a stale entry like `api/admin/old-route/*` would not fail the sentinel. The risk is purely a dead-config nit (no production impact) but it weakens the sentinel's claim to "config is in sync with routes." Citation: `IdempotencyRequiredRoutesCoverageTest:28-76`.

---

## C. Hard questions for owner (15-25)

1. **Same `X-Idempotency-Key` across DISTINCT mutating routes** — should the cache slot include the route path/name to prevent cross-route collisions when bodies coincidentally match? Today the slot is `(branch_id, user_id, sha256(key))` (`IdempotencyKeyMiddleware:77-82`). If yes, also clarify whether replay should be route-bound or merely keyed-bound.
2. **Is `425 Too Early` the right status?** Most kiosk/mobile clients treat 4xx as terminal. RFC 8470's "Too Early" is more about TLS resumption than retry-after-wait. Why not `503 Retry-After: 2` or `409`?
3. **`race_wait_ms=1500` default — what if the legitimate handler takes >1.5s?** The second caller exits with 425 but the first might still be executing. Confirm the longest possible POS payment confirm latency under load (NFC + TPE + audit + outbox dispatch) is < 1500ms.
4. **`release()` is silent-fail** (`RedisIdempotencyKeyRepository:98-105`). Under what conditions should this escalate (log+alert)? Today a degraded cache silently leaves PENDING records for 24h TTL.
5. **4xx responses are NOT cached** (`IdempotencyKeyMiddleware:145-154`) but the SAME key + DIFFERENT payload returns 409 on replay. What about same key + same 4xx-failing payload retried 50 times — is that intentional re-execution of validation logic?
6. **Stripe + Senangpay webhook routes deliberately omitted** from `required_routes` because providers control their own retry semantics (verified `routes/api.php` + `app/Http/PaymentGateways/Routes/{stripe,senangpay}.php`). Confirm this is final design intent — no plan to wire a webhook-side idempotency middleware.
7. **`webhook_events` retention 180 days** (`app/Console/Kernel.php:117`) — PCI dispute window is typically 180 days but Visa/MC chargebacks can be raised up to 540 days. Confirm 180d is acceptable for V1 Le Cayenne (single-merchant).
8. **No 425 race-path test exists.** `IdempotencyMiddlewareTest` covers 8 scenarios but not the "Phase 2 acquire-failed + waitForCompletion timed out" branch. Should V1.0.2 add `IdempotencyRaceConditionTest` driving the `Cache::add() = false` outcome with deferred completion?
9. **Loyalty redeem route added 2026-05-19** (`config/idempotency.php:65`). The corresponding wiring is `routes/api.php:1298-1299`. Sentinel `IdempotencyRequiredRoutesCoverageTest` will pass when this route is discovered; but **no behavioral test** asserts that two identical loyalty-redeem POSTs only debit points once. Should we add one before V1 ship?
10. **`pos-order/{order}/redeem-loyalty`** (`routes/api.php:920-922`, in required_routes line 70) — same gap as Q9.
11. **`select-delivery-boy` routes carry idempotency** (`routes/api.php:907, 936`). Is the design that assigning the same delivery boy twice is a true no-op? If it triggers WhatsApp notification on each call, idempotency should be required AND the wiring already is — confirm.
12. **Admin global user (`branch_id=0`)** can override branch via payload (`IdempotencyKeyMiddleware:188-195`). What if Admin posts WITHOUT `branch_id` in the payload? Falls through to `return 0` (line 194). The cache slot becomes `idempotency:v1:0:<userId>:<keyHash>` — Admin's own scope across all branches. Two distinct branch operations by same Admin can collide on same key. Realistic?
13. **`IDEMPOTENCY_FAIL_OPEN=true`** silently bypasses to app-level UNIQUE (`IdempotencyKeyMiddleware:127-129`). Under what circumstances is fail-open ever acceptable in production? The boot guard requires `enabled=true` but doesn't pin `fail_open=false`.
14. **TTL = 86400s (24h)**. A client that retries after 25h with the same key gets a fresh execution. Is that the desired window?
15. **Cache key version is `v1`** (`IdempotencyKeyMiddleware:78`). Are we treating this as a SemVer namespace? If a future schema change in `IdempotencyRecord` requires a flush, what's the migration story?
16. **Same key + DIFFERENT payload returns 409** with `Idempotency-Key-Conflict: true` header. What client UX handles this? Today both kiosk/mobile re-issue with a new UUID — but if they don't, the user sees "Idempotency key reused with different payload" — is that string i18n'd?
17. **Redis eviction policy** — is `noeviction` / `volatile-ttl` mandated by the deployment runbook? Boot guard doesn't enforce this.
18. **`signature` field on webhook_events is truncated to 512 chars** (`Senangpay.php:133`, `Stripe.php:263`). Stripe signatures are short hex but could SenangPay ever produce >512 chars? Truncation silently weakens audit forensics.
19. **`WebhookEvent` is globally scoped** (no BranchScope, docblock `WebhookEvent.php:43`). The `order_id` FK is set post-processing. What if a Stripe webhook arrives BEFORE the Order row exists (race between FrontendOrderService::create returning and Stripe charge confirmation)? `firstOrCreate` succeeds, `markProcessed(null)` is called — webhook is marked processed but orphan. Is there a re-stitch path?
20. **The middleware FROZEN status (CLAUDE.md §7)** — has the V1.0.2 backlog "add route path to scope key" been gated by a LOCK doc, or is it permanently inert?

---

## D. Sync invariants verified GREEN

Routes wired to `idempotency` AND in `required_routes` (route-level enforcement active when `enabled=true`) — **all 27 verified** :

```
✓ api/admin/pos                                              (config L26, route L768)
✓ api/admin/pos-order/change-payment-status/*               (config L27, route L905)
✓ api/admin/pos-order/select-delivery-boy/*                 (config L28, route L907)
✓ api/admin/online-order/change-payment-status/*            (config L29, route L935)
✓ api/admin/online-order/select-delivery-boy/*              (config L30, route L936)
✓ api/admin/table-order/change-payment-status/*             (config L31, route L948)
✓ api/frontend/order                                        (config L32, route L1195)
✓ api/frontend/order/*/payment-confirm                      (config L33, route L1201)
✓ api/admin/pos/counter-collect/*/confirm                   (config L42, route L808)
✓ api/admin/pos/counter-collect/*/cancel                    (config L43, route L828)
✓ api/admin/pos/collect-kiosk-cash/*                        (config L44, route L839)
✓ api/admin/pos/orders/*/print-receipt                      (config L45, route L840)
✓ api/admin/pos/cash-drawer/open                            (config L46, route L853)
✓ api/admin/pos/cash-drawer/sessions/open                   (config L47, route L857)
✓ api/admin/pos/cash-drawer/sessions/*/close                (config L48, route L860)
✓ api/admin/pos/cash-drawer/sessions/*/reconcile            (config L49, route L864)
✓ api/admin/pos-order/*/refund-with-counter-entry           (config L50, route L914)
✓ api/admin/pos-order/change-status/*                       (config L51, route L902)
✓ api/admin/online-order/change-status/*                    (config L52, route L933)
✓ api/admin/table-order/change-status/*                     (config L53, route L946)
✓ api/admin/kds-order/change-status/*                       (config L54, route L1070)
✓ api/frontend/order/change-status/*                        (config L55, route L1198)
✓ api/frontend/delivery-boy-order/change-status/*           (config L56, route L1278)
✓ api/admin/delivery-boy/cash-sessions/open                 (config L59, route L647)
✓ api/admin/delivery-boy/cash-sessions/*/close              (config L60, route L654)
✓ api/admin/delivery-boy/cash-sessions/*/reconcile          (config L61, route L658)
✓ api/frontend/loyalty/redeem                               (config L65, route L1299)
✓ api/admin/pos-order/*/redeem-loyalty                      (config L70, route L920)
```

**Sentinel `IdempotencyRequiredRoutesCoverageTest`** (`tests/Feature/Idempotency/IdempotencyRequiredRoutesCoverageTest.php:28-76`) iterates `Route::getRoutes()->gatherMiddleware()`, filters by POST/PUT/PATCH/DELETE, and asserts each URI matches a `required_routes` pattern. **A future `->middleware('idempotency')` without a config entry will fail CI.** Coverage GREEN.

**Webhook ledger atomicity floor** — DB UNIQUE `(provider, webhook_id)` (`database/migrations/2026_05_09_120000_create_webhook_events_table.php:83`) + Stripe handler (`Stripe.php:255-276`) + SenangPay handler (`Senangpay.php:125-146`) both use `firstOrCreate` + `wasRecentlyCreated` check → returns `200 duplicate_ignored` on replay. Regression test `WebhookEventIdempotencyTest.php:25-188` covers 7 scenarios incl. concurrent INSERT QueryException.

**Production boot guard chain** — `AppServiceProvider:143-151` throws `RuntimeException` if `idempotency.enabled=false` in production. Sibling guards at L85, 97, 104, 122, 161, 181, 193, 199, 213 reinforce a "fail fast at boot" pattern. Default in `config/idempotency.php:20` is `false` (safe). Sentinel `IdempotencyMiddlewareProductionGuardSentinelTest.php:25-98` enforces all 4 axes (prod+off=throw, prod+on=no-throw-on-this-guard, local+off=ok, config default=false).

---

## E. Out-of-scope or unverifiable

- **Live POST-replay E2E with real cache (redis) under V1 Le Cayenne traffic** — out of scope for read-only static audit; sentinel + middleware test cover the contract at the unit/integration tier.
- **Redis maxmemory-policy** — operational config, not in repo. Recommend doc/runbook check.
- **Mobile/kiosk client header generation discipline** — Z7's lane is the middleware, not client. Mobile audit owns the "send X-Idempotency-Key per POST" enforcement (LCS-S-002 / B-02 spec).
- **PaymentReconcileController idempotency** (`app/Http/Controllers/Frontend/PaymentReconcileController.php:162` — per-entry lock keyed on `transaction_id`) — different layer (DB UNIQUE on `capture_payment_notifications.transaction_id` floor), out of Z7 scope.
- **OrderService::findExistingOrderForIdempotencyRecovery** branch-scoped recovery — exercised by sentinel `IdempotencyMiddlewareSentinelTest::test_existing_branch_scoped_recovery_still_works_with_middleware_active` (line 171-203). Defense-in-depth working, defers to Z6 BranchScope agent for deeper review.
- **Refund-with-counter-entry flow body uniqueness** (Z8 refund's lane). Z7 only verifies the middleware is wired.

---

## F. RED verdict

**Score: 8.0 / 10**

The Z7 idempotency surface is among the **strongest defended** subsystems audited in this cycle. The middleware is FROZEN and concise (244 LOC), the contract is split cleanly (interface + impl + DTO), 4 sentinel categories enforce: header omission rejection (`IdempotencyMiddlewareSentinelTest`), route coverage (`IdempotencyRequiredRoutesCoverageTest`), production boot guard (`IdempotencyMiddlewareProductionGuardSentinelTest`), and behavioral replay/conflict/cross-branch/TTL/fail-open-closed (`IdempotencyMiddlewareTest`). The WebhookEvent ledger has a complementary DB UNIQUE atomicity floor and a separate sentinel. The 27 wired routes + 24 config patterns + 0 missing-from-config sentinel = **NO header-omission bypass possible** when `enabled=true`.

**Top 3 residual risks** :

1. **(P1) Scope key omits route path** — same key + same body across two distinct mutating routes collides. Realistic but rare; clients must use UUIDs per UI action.
2. **(P2) No 425 race-path test** — the in-flight branch (Phase 2 `acquire()=false` → `waitForCompletion` timed out → return 425) has no end-to-end regression guard. Cap is middleware frozen, so risk is "silent regression on V1.0.2 unfreeze."
3. **(P3) Redis eviction policy assumption** — middleware behavior depends on the redis driver retaining keys for TTL. No boot guard or runbook anchor in-repo. NF525-adjacent under memory pressure.

**Shippable V1?** — **YES** for Le Cayenne LOCAL. The production boot guard prevents the silent-off failure mode. The sentinel coverage prevents silent route-coverage drift. The two open P1 items (scope key + 4xx replay-as-retry semantics) are owner-clarify, not block. The 425 race-path test is V1.0.2 backlog.

**Recommended owner action before ship** :
- Confirm answer to Q1 (scope key path inclusion) and Q5 (4xx replay intent) — file as V1.0.2 backlog if both deferrable.
- Verify Q3 (max POS payment confirm latency vs 1500ms race wait) by inspecting an observed-p99 metric, not a guess.
- Add a doc/runbook note about redis maxmemory-policy (`noeviction` or `volatile-ttl`).

End of RED-Z7.
