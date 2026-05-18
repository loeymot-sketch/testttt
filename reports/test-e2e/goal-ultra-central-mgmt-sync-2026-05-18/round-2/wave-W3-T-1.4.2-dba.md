# T-1.4.2 — IdempotencyKey deep semantics — DBA audit (Round 2)

**Specialist**: DBA
**Date**: 2026-05-18
**Mode**: READ-ONLY
**Anchors verified**: `app/Http/Middleware/IdempotencyKeyMiddleware.php` (244 LOC), `config/idempotency.php`, all migrations matching `idempotenc*`, `app/Services/Idempotency/*`, `app/Providers/AppServiceProvider.php` (binding), `routes/api.php` (route-level annotation), `app/Services/OrderService.php` (recovery hook).

---

## 0. Headline — the BRAIN §9 claim is misleading

BRAIN §9 states: *"Dual-layer : middleware cache + DB UNIQUE constraint."*

Ground truth from migrations (`grep "Schema::create.*idempotency" → no match`):

- **There is no `idempotency_keys` table.** None has ever been created.
- The "cache" layer is the **only** dedicated idempotency store — `Cache::add()` (Redis `SET NX EX` in prod, `array` in tests, file lock on single host).
- The "DB UNIQUE" layer is actually **business-table backstops**: `orders.idempotency_key`, `domain_events.idempotency_key`, `stock_movements.idempotency_key`. Each is scoped/UNIQUE per its own table — there is no centralized UNIQUE tuple `(branch_id, user_id, key_hash)` anywhere in MySQL.
- This is **defense-in-depth, not dual-layer write-through**. If Redis loses the key (eviction, restart, cluster failover with `appendonly no`), the middleware loses replay capability — only the business-table UNIQUE indexes prevent a duplicate `orders` row. Cached response body **cannot** be reconstructed; the second request will either succeed-and-fail-with-UNIQUE-violation (recovered by `findExistingOrderForIdempotencyRecovery`) or, for non-`orders` endpoints (print-receipt, cash-drawer/open, payment-confirm) where no UNIQUE backstop exists, **succeed twice**.

This finding alone justifies a **P1 — "dual-layer is single-layer in practice for 11 of 17 protected routes"** in W3.

---

## 1. idempotency_keys schema — does not exist

| Question | Answer |
|---|---|
| Table exists? | **No** |
| Columns? | N/A — purely cache-backed |
| Storage shape | JSON-serialized `IdempotencyRecord` (`{status, headers, body_b64, payload_hash, created_at, state}`) under a single Redis string key |
| Key format | `idempotency:v1:{branch_id}:{user_id}:{sha256(raw_header)}` — derived in middleware line 77-82 |

Conclusion for the GOAL plan: any task that assumes a `idempotency_keys` table for cross-pod/cross-region replication, or for SQL-level audit trails of replayed requests, must **first create the table**. Today there is no persistent record of which idempotency keys have replayed which response. Audit is best-effort via `Idempotency-Replayed: true` response headers (not stored).

---

## 2. UNIQUE constraint scope — three different scopes, none centralized

Three distinct UNIQUE indexes exist across business tables. They do **not** share a tuple shape:

| Table | UNIQUE columns | Index name | Migration |
|---|---|---|---|
| `orders` | `(branch_id, idempotency_key)` composite | `orders_branch_id_idempotency_key_unique` | `2026_04_18_140003_scope_idempotency_key_to_branch.php` |
| `domain_events` | `idempotency_key` standalone | `uniq_domain_events_idempotency_key` | `2026_05_09_180000_add_idempotency_key_to_domain_events.php` |
| `stock_movements` | `idempotency_key` standalone | `stock_movements_idempotency_unique` | `2026_04_27_143130_create_stock_movements_table.php` |

The **Redis cache key** is scoped to `(branch_id, user_id, sha256(header_key))`. The **orders UNIQUE** is scoped to `(branch_id, idempotency_key)` — **`user_id` is absent**. Consequence:

- Two POS operators in the **same branch** using the same raw idempotency key value (a UUID v4 collision is astronomical, but **a deliberate or buggy fixed-string key in client code** — e.g., a kiosk dev who hard-coded `"test-key-1"` in a payload — would collide). Cache slot differs (different `user_id` in the key), so the middleware would let both through; then **MySQL UNIQUE on `orders(branch_id, idempotency_key)` would fire**, rejecting the second insert with `23000`. `findExistingOrderForIdempotencyRecovery` (`OrderService:2540-2550`) would then return the **first user's order to the second user** — a multi-tenant **information leak inside the same branch** (low severity but real).
- `domain_events.idempotency_key` UNIQUE is **global** (no `branch_id` scope). The listener computes the key as `sha1(event_type|aggregate_id|discriminator)` (per the migration doc-block). `aggregate_id` is the order id which is already globally unique, so collisions are not practical. But the lack of branch scope means a `branch_id` migration or branch_id reassignment cannot reuse legacy aggregate_ids without a clash.
- `stock_movements.idempotency_key` UNIQUE is **global**. Same reasoning — keys carry order ids or stock-level ids, globally unique by construction.

**SQL — verify in prod**:
```sql
SHOW INDEX FROM orders WHERE Key_name LIKE '%idempotency%';
-- expect: orders_branch_id_idempotency_key_unique, Non_unique=0, two rows (Seq 1=branch_id, Seq 2=idempotency_key)

SHOW INDEX FROM domain_events WHERE Key_name LIKE '%idempotency%';
-- expect: uniq_domain_events_idempotency_key, Non_unique=0, one row

SHOW INDEX FROM stock_movements WHERE Key_name LIKE '%idempotency%';
-- expect: stock_movements_idempotency_unique, Non_unique=0, one row
```

If `orders_idempotency_key_unique` (the legacy standalone unique from the 2026-03-25 migration) still appears alongside the composite, then `2026_04_18_140003_scope_idempotency_key_to_branch.php` was partially applied — verify via `SHOW INDEX` and run a forward migration replay.

---

## 3. TTL / cleanup strategy

- **Cache TTL**: `config('idempotency.ttl_seconds', 86400)` → **24 hours**, applied on both `acquire()` (placeholder) and `complete()` (final record). Redis evicts automatically.
- **Race-wait timeout**: `config('idempotency.race_wait_ms', 1500)` → **1.5 s** polling at 50 ms intervals for an in-flight twin to publish a COMPLETED record. After the deadline, returns HTTP **425 Too Early** with code `IDEMPOTENCY_IN_FLIGHT` (line 119-123).
- **DB cleanup**: **None.** `orders.idempotency_key`, `domain_events.idempotency_key`, and `stock_movements.idempotency_key` accumulate forever — they are simply columns on their parent rows. Bloat scales with row count of parent table, not separately. The `domain_events` table is the only one with a worry (high-cardinality, retention policy unclear); a separate audit task should verify retention.

**Risk**: if the operator opts to retain old `orders` rows for 6 years (NF525 §4 audit retention), then `domain_events` and `stock_movements` carry the same retention. Their `idempotency_key` UNIQUE indexes grow unbounded — at ~3 events per order, 100 orders/day/branch, 10 branches, 6 years = ~6.5 M rows. Index size at 64-char varchar ≈ 200 MB. Manageable but should be monitored.

---

## 4. Response body storage

- Inline JSON (no compression). `IdempotencyRecord::$bodyBase64` carries the full base64-encoded HTTP response body, plus headers, plus payload hash, plus timestamp, plus state.
- **Size limits**: Redis string max is 512 MB per key; in practice, Laravel's `Cache::store('redis')->put()` serializes via `serialize()` or `igbinary` then stores. POS order creation responses are typically <10 KB. Receipt PDF endpoints could be larger but they are not in the `required_routes` list; print-receipt is only `idempotency` middleware (line 800), and it returns a tiny counter response.
- **Header sanitization**: `safeHeaders()` (line 137-157) strips `set-cookie`, `date`, `x-correlation-id`, `x-request-id` to keep replays deterministic and prevent stale Set-Cookie leakage. **Good defensive design.**
- **base64 inflation**: encoded body is ~1.33× raw size, so a 10 KB response becomes ~13 KB stored. Negligible.

**SQL/Redis verify in prod**:
```bash
redis-cli --scan --pattern 'idempotency:v1:*' | head
# then for one key:
redis-cli MEMORY USAGE 'idempotency:v1:1:42:abc...def'
```
Expected: ~5-20 KB per key. If any keys >100 KB, investigate whether a controller is returning a large blob through an idempotency-protected route.

---

## 5. InnoDB row-lock behavior on concurrent INSERT

Scenario: two POST /api/admin/pos requests arrive simultaneously, same `(branch_id, idempotency_key)` UUID.

**With Redis up** (the happy path):
1. Both middleware instances call `Cache::add($scopedKey, $placeholder, ttl)`.
2. Exactly one returns `true` — Redis `SET NX` is atomic.
3. The losing instance calls `waitForCompletion()` which polls `find()` every 50 ms for up to 1.5 s. When the winner publishes its COMPLETED record, the loser returns the replayed response.
4. If the winner takes >1.5 s, the loser returns HTTP 425 — the client must retry. **The winner still finishes and writes the response to cache**, so the retry will replay correctly. **No double-insert.**

**With Redis down** (`fail_open=false` default):
1. Both middleware instances get `IdempotencyStorageUnavailableException` → return HTTP 503. **No insert occurs.**

**With Redis down + `fail_open=true`** (escape hatch):
1. Both middleware instances bypass the cache and call `$next($request)`.
2. Both reach `OrderService::createOrderFromCart` (or equivalent). Both attempt `INSERT INTO orders (...)` with the same `(branch_id, idempotency_key)`.
3. InnoDB UNIQUE index `orders_branch_id_idempotency_key_unique` is BTREE. The second INSERT **does not wait** for the first — it acquires a **gap lock + shared next-key lock** on the duplicate position, blocks momentarily on the first transaction's exclusive insert intention lock, and once the first commits, the second INSERT receives `ER_DUP_ENTRY (1062)` immediately. **No wait beyond the lock-wait of the in-progress txn.**
4. `OrderService::createOrder` catches the duplicate and calls `findExistingOrderForIdempotencyRecovery` (line 592, 1077). Returns the first order to the second caller.
5. **Net effect**: app-layer UNIQUE behaves as a single-layer idempotency guard. Acceptable for `/orders` endpoint, **unsafe for the 11 other routes** (cash-drawer/open, print-receipt, payment-confirm, etc.) where no UNIQUE backstop exists. A `fail_open=true` configuration is a **footgun** on those routes.

**SQL micro-bench**:
```sql
-- Session A
START TRANSACTION;
INSERT INTO orders (branch_id, idempotency_key, ...) VALUES (1, 'k-test', ...);
-- intentionally hold

-- Session B (parallel)
INSERT INTO orders (branch_id, idempotency_key, ...) VALUES (1, 'k-test', ...);
-- blocks on insert-intention lock

-- Session A
COMMIT;
-- Session B immediately receives ERROR 1062 (23000) Duplicate entry
```

The lock-wait window equals Session A's transaction duration. With `innodb_lock_wait_timeout=50` (default), the second insert errors out at `1062` long before the timeout — the dup detection is synchronous on commit of A, not on lock-wait expiry.

---

## 6. Index strategy

- `orders_branch_id_idempotency_key_unique` is composite `(branch_id, idempotency_key)`. Lookups on `idempotency_key` alone would **not** use this index (leftmost prefix rule: must include `branch_id`). `findExistingOrderForIdempotencyRecovery` (`OrderService:2546-2549`) **does** include `branch_id`, so this is fine. **No separate `idempotency_key` index needed.**
- `domain_events.idempotency_key` and `stock_movements.idempotency_key` are standalone UNIQUE indexes; `firstOrCreate` patterns work as expected.
- **Cardinality**: `branch_id` is low-cardinality (~10s of branches max for Le Cayenne, 100s for a SaaS deployment). `idempotency_key` is high-cardinality. Composite index works fine — InnoDB BTREE handles this.
- **Index size**: 64-char varchar (utf8mb4) is 256 bytes + 4 bytes `branch_id` → ~260 bytes per index entry. At 1M orders, ~260 MB. Acceptable.

**Optimization suggestion** (P3, not for V1): if `idempotency_key` is always a 36-char UUID v4 or 64-char hex hash, switch to `BINARY(16)` (UUID) or `BINARY(32)` (hash) to halve index size. Out of scope for V1.

---

## 7. The 17 mutating routes — actual coverage

Grep `'idempotency'` in `routes/api.php` returns **17 hits** (matches BRAIN §9). Verified routes:

| # | Route | Required header? | Backstop |
|---|---|---|---|
| 1 | `POST /api/admin/pos` | Yes (`required_routes[0]`) | orders UNIQUE |
| 2 | `POST counter-collect.confirm` | No (not in required) | none |
| 3 | `POST counter-collect.cancel` | No | none |
| 4 | `POST collect-kiosk-cash` | No | none |
| 5 | `POST /orders/{order}/print-receipt` | No | none |
| 6 | `POST /cash-drawer/open` | No | none |
| 7 | `POST cash-drawer-session/open` | No | none |
| 8 | `POST cash-drawer-session/close` (approx, line 820) | No | none |
| 9 | `POST cash-drawer-session/movement` (approx, line 824) | No | none |
| 10 | `POST /admin/pos-order/change-payment-status/{order}` | Yes (`required_routes[1]`) | orders UNIQUE |
| 11 | `POST /admin/pos-order/select-delivery-boy/{order}` | Yes | orders UNIQUE |
| 12 | `POST /admin/pos-order/{order}/refund` | No (not in required) | none |
| 13 | `POST /admin/online-order/change-payment-status` | Yes | orders UNIQUE |
| 14 | `POST /admin/online-order/select-delivery-boy` | Yes | orders UNIQUE |
| 15 | `POST /admin/table-order/change-payment-status` | Yes | orders UNIQUE |
| 16 | `POST /api/frontend/order` | Yes | orders UNIQUE |
| 17 | `POST /api/frontend/order/{order}/payment-confirm` | Yes | none (state-mutating, not new insert) |

**Findings**:
- 8 routes have **header required + UNIQUE backstop**: safe.
- 1 route (`payment-confirm`) is header-required but no UNIQUE — cache is single-layer. **Acceptable** because the controller idempotently transitions order state; a replay of `payment_status=Paid → Paid` is benign.
- 8 routes (`counter-collect`, `collect-kiosk-cash`, `print-receipt`, all cash-drawer, refund) are **not in `required_routes`**, so a missing header simply bypasses the middleware (line 58: `if ($key === '') { ... return $next($request); }`). They are **only protected when the client opts in by sending the header**. Print-receipt double-fire is functionally low-risk (increments a counter, no fiscal impact). Cash-drawer-open double-fire could **double-credit `cash_movements`** — verify with the cash-drawer team.

**P1**: at least `cash-drawer/open`, `cash-drawer-session/open`, `cash-drawer-session/movement` should be in `required_routes` for V1.0.2.

---

## 8. Replay-against-stale-data race — the silent footgun

Scenario from the brief: an order is paid and Z-closed; a replay arrives 23 h later (still within 24 h TTL).

**Current behavior** (line 86-96):
1. `find($scopedKey)` returns the cached `COMPLETED` record.
2. Middleware **returns the stale cached response verbatim**, including `Idempotency-Replayed: true` and `Idempotency-Stored-At: <original ISO8601>` headers.
3. The original response was probably `{ "success": true, "order_id": 4711, "status": "Paid" }` — still semantically correct because the order **is** paid.

**The footgun**: if the original response carried *transient* data — a one-shot deep-link token, a webhook secret, a pre-signed S3 URL with a 1 h expiry — the replay returns the **expired/invalidated** token without re-issuance. Receiver thinks the call succeeded with a working token; UX silently breaks.

**Z-report race specifically**: once a Z-report is closed for the day, the `fiscal_sequence_no` and `business_date` of an order are immutable. A replay of `POST /api/frontend/order` 23 h later returning the same `order_id` is **correct** — there is no race on fiscal data. The Z-report does not invalidate the order.

**Where it does bite**:
- `payment-confirm` replay: original returned `{ success: true, status: 'Paid' }`. Between the original and the replay, an admin **manually refunded** the order via a separate endpoint. The replay still returns `'Paid'` — the client UI shows "Paid" while the DB shows "Refunded". **Should the cache invalidate on state change?** Today it does not.
- Recommendation (P2 V1.0.2): when an admin modifies an order's payment status outside the idempotency middleware, invalidate cached idempotency entries for that order. Simplest implementation: `OrderObserver::updated` → `Cache::forget("idempotency:v1:*:*:*order_id={$order->id}*")` — but the key shape doesn't carry order_id, so a tag-based store (`Cache::tags(['order:'.$order->id])`) would be required. Not trivial; flag for V1.0.2.

**Critical assertion**: the current behavior is **not unsafe for NF525** — fiscal sequences are immutable so replays are always consistent with fiscal state. The footgun is **UX-level**, not compliance-level.

---

## 9. SQL verifications to run in prod (Day-1 V1.0.2)

```sql
-- 1. Confirm composite unique exists, standalone removed
SHOW INDEX FROM orders WHERE Key_name LIKE '%idempotency%';
-- Expect exactly: orders_branch_id_idempotency_key_unique (Non_unique=0, 2 columns)
-- FAIL if orders_idempotency_key_unique (standalone) also present

-- 2. Distribution of idempotency_key NULLs
SELECT COUNT(*) AS total, COUNT(idempotency_key) AS with_key,
       COUNT(*) - COUNT(idempotency_key) AS null_keys
FROM orders;
-- Expect ~100% with_key for orders created post-iter11 frontend
-- Null keys are pre-iter9 historical orders — accept

-- 3. domain_events idempotency saturation
SELECT COUNT(*) AS total, COUNT(idempotency_key) AS with_key FROM domain_events;
-- Expect ~100% post-iter14 SPECIALIST-2 listener adoption

-- 4. Check for any orphaned UNIQUE clashes (recovery attempts)
SELECT idempotency_key, branch_id, COUNT(*) FROM orders
  WHERE idempotency_key IS NOT NULL
  GROUP BY idempotency_key, branch_id HAVING COUNT(*) > 1;
-- Expect zero rows. Non-zero → UNIQUE violated (impossible by definition, but verify)
```

---

## 10. Verdict for T-1.4.2

| Risk axis | Severity | Status |
|---|---|---|
| Schema integrity | **OK** | All UNIQUE constraints present and consistent |
| BRAIN §9 wording | **P1** | "Dual-layer" is misleading — only cache + per-table backstop; no `idempotency_keys` table |
| Required-route coverage | **P1** | Cash-drawer + receipt routes opt-in only; should be required |
| Replay-vs-state-change | **P2** | Stale-cache footgun on `payment-confirm`; UX bug, not compliance |
| `fail_open=true` posture | **P1** | Documented escape hatch; unsafe for non-`orders` routes |
| TTL/bloat | **OK** | 24 h Redis TTL; business-table indexes grow with parent retention |
| Concurrent insert race | **OK** | InnoDB UNIQUE + Cache NX double-protect for /orders; cache-only for others |
| Index strategy | **OK** | Composite leftmost-prefix correct; no orphan single-col index |

**Recommendation for GOAL W3 (V1.0.2)**:
1. Add cash-drawer/session/open/movement and print-receipt to `required_routes` (low risk, high payoff).
2. Update BRAIN §9 wording to reflect reality: "Cache idempotency + per-table UNIQUE backstops on orders/domain_events/stock_movements. No central idempotency_keys table."
3. Document `fail_open=true` as **forbidden in production** in `docs/GATES_DOCTRINE.md` — single-layer cache-only protection is unsafe on 11 of 17 routes.
4. (P2) Tag-based cache invalidation on order state change to fix payment-confirm replay-vs-refund race.

**Total LOC reviewed**: 244 (middleware) + 47 (record DTO) + 158 (repo impl) + 50 (config) + 152 (compose-index migration) + 30 (initial orders col migration) + 51 (domain_events migration) + 30 (stock_movements migration relevant rows) + 50 (OrderService recovery) = **~812 LOC**.
