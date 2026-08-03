# HTTP Idempotency Contract — FoodKing V1

**Status** : production-ready, flag-gated (default OFF) — F-VERIFY-09-02 RESOLVED.
**Owner** : platform team. Sentinels live in `tests/Feature/Sentinels/IdempotencyMiddlewareSentinelTest.php` and `tests/Feature/Idempotency/IdempotencyMiddlewareTest.php`.

---

## 1. Why

Before V1, FoodKing relied **only on application-level idempotency** for POS order creation:

- a pre-check in `OrderService::posOrderStore()` against `Order::where('idempotency_key')`,
- a UNIQUE composite `(branch_id, idempotency_key)` on `orders` (and `frontend_orders`),
- a 23000-MySQL-error catch as a final backstop.

This protects `POST /api/admin/pos` and `POST /api/frontend/order` reasonably well — but it does **NOT** protect the side-effecting payment / dispatch routes (`change-payment-status`, `select-delivery-boy`, `payment-confirm`). A misconfigured terminal or a network retry could double-charge or double-dispatch a single order. F-VERIFY-09-02 (`docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md` §3.4) flagged this as a P0.

The `IdempotencyKeyMiddleware` adds an HTTP-level guard **in addition to** the existing applicative one (defense-in-depth, no replacement) — see §6.

---

## 2. Header contract

| Header | Direction | Required | Format | Example |
|---|---|---|---|---|
| `X-Idempotency-Key` | client → server | YES on opt-in routes | `^[A-Za-z0-9._\-]{8,64}$` | `pos-2026-05-06-7e1c5b2c1f8a4d61` |
| `Idempotency-Replayed` | server → client | response only | `true` on cached replays, absent otherwise | `true` |
| `Idempotency-Stored-At` | server → client | response on replay only | ISO-8601 UTC | `2026-05-06T11:42:08+00:00` |
| `Idempotency-Key-Conflict` | server → client | response on 409 only | `true` | `true` |

UUID-v4 keys are valid. Generate one key per **logical** order intent, not per HTTP attempt — retries MUST reuse the original key.

---

## 3. Routes opt-in

The middleware is a no-op on routes that are NOT listed in `config('idempotency.required_routes')`. As of V1:

| Route | Flag-on behaviour |
|---|---|
| `POST /api/admin/pos` | header required, replay on match |
| `POST /api/admin/pos-order/change-payment-status/{order}` | header required, replay on match |
| `POST /api/admin/pos-order/select-delivery-boy/{order}` | header required, replay on match |
| `POST /api/admin/online-order/change-payment-status/{order}` | header required, replay on match |
| `POST /api/admin/online-order/select-delivery-boy/{order}` | header required, replay on match |
| `POST /api/admin/table-order/change-payment-status/{order}` | header required, replay on match |
| `POST /api/frontend/order` | header required, replay on match |
| `POST /api/frontend/order/{frontendOrder}/payment-confirm` | header required, replay on match |

Routes **explicitly excluded** (read-only or pricing-preview, no side effect):

- `POST /api/admin/pos/quote`
- `POST /api/admin/pos/walk-in-customer`
- `POST /api/admin/pos/counter-collect/*`
- `POST /api/frontend/coupon/coupon-checking`
- any GET / PUT / PATCH / DELETE — middleware skips non-POST methods.

---

## 4. Status code matrix

| Situation | HTTP | JSON `code` | Headers |
|---|---|---|---|
| First call, header present + valid | original 2xx | (controller) | none added |
| Identical key + payload, within TTL | original 2xx (replayed) | (controller body, base64-stored) | `Idempotency-Replayed: true`, `Idempotency-Stored-At: <ISO8601>` |
| Same key, **different** payload hash | `409` | `IDEMPOTENCY_KEY_CONFLICT` | `Idempotency-Key-Conflict: true` |
| Header missing on opt-in route | `422` | `MISSING_IDEMPOTENCY_KEY` | — |
| Header malformed (regex fail) | `422` | `MISSING_IDEMPOTENCY_KEY` | message details the constraint |
| Authenticated user has unresolvable `branch_id` | `422` | `MISSING_IDEMPOTENCY_KEY` | non-Admin guard |
| Twin request in flight, no completion in `race_wait_ms` | `425` | `IDEMPOTENCY_IN_FLIGHT` | client MAY retry shortly |
| Storage unavailable, `fail_open=false` | `503` | `IDEMPOTENCY_STORAGE_UNAVAILABLE` | ops alert |
| Storage unavailable, `fail_open=true` | passthrough | (controller) | relies on app-layer UNIQUE |

Original-controller `4xx`/`5xx` responses are **NOT** cached (see `release()` in `RedisIdempotencyKeyRepository`). A failed attempt may be retried with the same key.

---

## 5. Scope and TTL

- **Scope** : `(branch_id, user_id, sha256(key))`. Cross-branch and cross-user reuse of the same key are **independent** scopes — never collide. Mirrors the existing applicative invariant proven by `IdempotencyRecoveryBranchScopedTest`.
- **`branch_id` resolution** : 1) `auth()->user()->branch_id` if `> 0`, else 2) Admin global (`branch_id=0` + role `Admin`) may pass `branch_id` in payload, else 3) `request->input('branch_id')`. Failure to resolve → `422`.
- **TTL** : 24h by default (`IDEMPOTENCY_TTL_SECONDS=86400`). Beyond TTL the key is forgettable; a new POST is treated as a fresh request.
- **Hash** : `sha256(raw request body)`. Used only for conflict detection — NOT stored in plaintext.

---

## 6. Coexistence with applicative idempotency (defense-in-depth)

The middleware does **NOT** remove anything. The existing layers stay:

1. `OrderService::findExistingOrderForIdempotencyRecovery($key, $branchId)` — pre-insert lookup.
2. UNIQUE `(branch_id, idempotency_key)` on `orders` and `frontend_orders` — DB-level final backstop (catch QueryException 23000).
3. `FrontendOrderService::findExistingFrontendOrderForIdempotencyRecovery` — kiosk equivalent.

If Redis is unavailable AND `fail_open=true`, the middleware passes through, and (1)+(2) take over.
If Redis is up but the key TTL expired AND a duplicate slipped through, (2) still rejects it via 23000 → recovery returns the original order.

The sentinel `IdempotencyMiddlewareSentinelTest::test_existing_branch_scoped_recovery_still_works_with_middleware_active` re-asserts this contract on every CI run.

---

## 7. Roll-out + rollback

| Level | Action | When |
|---|---|---|
| 1 | `IDEMPOTENCY_MIDDLEWARE_ENABLED=false` + `php artisan config:clear` | turn the whole feature off, no code revert |
| 2 | `IDEMPOTENCY_FAIL_OPEN=true` + `config:clear` | Redis flapping, prefer correctness over availability dropoff |
| 3 | `git revert <sha>` | last resort |

Recommended sequence: ship with flag OFF → enable in staging for one week → enable in a single production branch → global enable.

---

## 8. Integrator checklist (3rd-party POS / terminal)

- [ ] Generate a **single** UUID-v4 per order intent. Persist it locally before the first POST.
- [ ] On any retry (network timeout, 5xx, 425), reuse the same key. **Do not** regenerate.
- [ ] If you receive `409 IDEMPOTENCY_KEY_CONFLICT`, your local payload diverged — pick a fresh key and re-submit explicitly (this is a client bug, not a transient).
- [ ] If you receive `425 IDEMPOTENCY_IN_FLIGHT`, wait 250ms, retry the same key.
- [ ] If you receive `422 MISSING_IDEMPOTENCY_KEY`, you are calling an opt-in route without a header — fix client code.
- [ ] If you receive `503 IDEMPOTENCY_STORAGE_UNAVAILABLE`, treat as a transient — retry with backoff. No order has been created.

---

## 9. Operational signals

Monitor:
- count of `Idempotency-Replayed: true` responses (replay rate — high = lots of retries upstream).
- 422 / 425 / 503 / 409 counts (each tells a different story).
- `[IdempotencyMiddleware] storage unavailable` log warnings → page on-call.

---

## 10. References

- Plan d'exécution : `docs/audit/plans/PLAN_P11_IDEMPOTENCY_KEY_MIDDLEWARE_2026-05-06.md`
- Audit ouvrant le finding : `docs/audit/POS_AUDIT_FINAL_REPORT_2026-05-06.md` §3.4 (F-VERIFY-09-02)
- IETF draft : <https://datatracker.ietf.org/doc/draft-ietf-httpapi-idempotency-key-header/> (informational alignment, §2.6 conflict semantics)
- Sentinel applicatif : `tests/Feature/Sentinels/IdempotencyRecoveryBranchScopedTest.php`
- Sentinel HTTP : `tests/Feature/Sentinels/IdempotencyMiddlewareSentinelTest.php`
- Test suite : `tests/Feature/Idempotency/IdempotencyMiddlewareTest.php`
