# A-013 root-cause investigation — round 3

**Date**: 2026-05-11
**Idem key under investigation**: `1778456025793_19vd8ggpy_1_A` (seq=4)
**Investigator**: read-mostly investigation agent (15 min budget)

## Verdict

**`spec_mirage`** — transient audit-instrumentation false-negative on the per-order DB probe; **no real product race**. The order was created and persisted with `id=861` and `fiscal_sequence_no=177`; the audit's `dbConfirmByIdempotencyKey` SELECT returned an empty row set on a single call out of 38, while the middleware's cached 2xx response (which is written **after** the controller's `DB::transaction` commits) holds the full success payload.

The separate "prefix anomaly" (`db_orders_with_prefix_count=3` vs `api_posted=38`) is **also a spec mirage**, caused by an external cleanup of `AUDIT-%` test rows happening between burst end and the post-burst probe — also unrelated to the middleware/product layer.

## Evidence

### Primary evidence (cache record)

Live Redis lookup of the suspect idem key (`cache.default=redis`, `cache.prefix=le_cayenne_cache_`, ttl_seconds=86400) found a `COMPLETED` record under user_id=16 (`pos@lecayenne.fr`, branch_id=1):

- Cache key: `le_cayenne_database_le_cayenne_cache_:idempotency:v1:1:16:96bb52733d89c48cabcef98ec412ab2a3dd98727f8d8ad1d323e2b1c3a7baa86`
- `state`: `COMPLETED`
- `status`: 201
- `created_at`: 2026-05-11T01:33:46+02:00
- Decoded `body_b64` shows: `id=861`, `token=AUDIT-RUSH-A-004-1778455994967`, `fiscal_sequence_no=177`, `status=4` (ACCEPT), full `composition_snapshot` present, full `payments_breakdown` present.

The middleware therefore observed and cached a 2xx response from the controller — the controller did execute and return success. (Round-3 spec change correctly attached one idempotency key per request and used `dbConfirmByIdempotencyKey` to bypass the cluster-1 axios interceptor — the audit channel is sound.)

### Idempotency tables / webhook tables

Schema probes confirm there is **no standalone `idempotency_keys`/`idempotency_records` table** in this app. The middleware uses `Cache::store()` (`RedisIdempotencyKeyRepository::complete()` calls `$this->store()->put(...)`). `webhook_events` returned NULL for the suspect key. The cache row above is the only artifact, and it is COMPLETED — not PENDING.

### Order row state

`SELECT * FROM orders WHERE id=861` and `WHERE token LIKE 'AUDIT-RUSH-A-004-%'` and `WHERE idempotency_key='1778456025793_19vd8ggpy_1_A'` all return zero rows now. This is consistent with `Iter15CleanupTestOrdersCommand` having hard-deleted the row (by token prefix `AUDIT-%`, statuses 1/4/7/8) at some point after creation. There is no `deleted_at` row (cleanup is hard delete, see `app/Console/Commands/Iter15CleanupTestOrdersCommand.php` line 82-83).

### Server log

`grep -i "1778456025793\|19vd8ggpy" storage/logs/*.log` returned zero matches — neither the middleware nor the controller logs at INFO for the success path, so absence is expected; the cache record is the source of truth.

## Middleware behavior

`app/Http/Middleware/IdempotencyKeyMiddleware.php` ordering is correct and **does not exhibit a cache-before-commit race**:

1. `find($scopedKey)` → replay if COMPLETED.
2. `acquire($scopedKey, ...)` → atomic `Cache::add()` PENDING placeholder (Redis `SET NX EX` semantics).
3. `$response = $next($request)` → controller executes; `OrderService::posOrderStore` runs `DB::transaction(...)`; transaction commits **before** `posOrderStore` returns.
4. **Only after** the response is built (status 2xx), `complete($scopedKey, $response, ...)` writes the cached body via `$this->store()->put()`.
5. On `\Throwable` thrown by the controller, `release($scopedKey)` is called and the throwable is re-raised — no 2xx is ever cached on a failed request.

There is no code path that publishes a COMPLETED record before the DB transaction commits. The PENDING placeholder is published before the controller runs, but `find()` filters PENDING records out (returns null when `state !== 'COMPLETED'`), so a concurrent reader cannot see a "phantom 2xx".

Verdict: **middleware has no leak class**. It cannot return a cached 2xx for an idempotency key without the controller having also committed the order row (modulo a process kill between `commit` and `complete()`, which would leave the cache without the record, not the inverse).

## Prefix anomaly

`observations.json` reports `final_counts: ui_posted=3 api_posted=38 total=41` and `db_orders_with_prefix_count=3` (state17 KDS also reports `card_count_matching_prefix=3`). This is **not** a spec query bug — the SQL `WHERE token LIKE 'AUDIT-RUSH-A-%'` is correct, the actual DB tokens (e.g. `AUDIT-RUSH-A-004-1778455994967`) match. The 3 surviving orders are the last 3 of burst-2 (tokens 036/037/038, fseqs 216/217/218); rows 1-35 were created and then hard-deleted.

Plausible mechanism: an external invocation of `php artisan iter15:cleanup-test-orders --apply` between burst end and the post-burst probe. The cleanup hard-deletes orders whose token matches `AUDIT-%` AND status ∈ {1,4,7,8}. POS orders end at status=4 (ACCEPT) by default and are therefore in scope. With `workers=1` in `playwright.config.js`, no other test wave can race in-process; the most likely causes are an orchestrator-side `iter15:cleanup-test-orders --apply` issued mid-test, or a manual sweep. Wave A's own `beforeAll`/`afterAll` hooks invoke the command, but neither runs during the burst window.

Severity: **P2 audit_integrity (spec/test infra)** — does not impact NF525, payment integrity, or product correctness. Suggested mitigation: have Wave A's spec push a sentinel timestamp to a side-channel and assert at the post-burst probe that `db_orders_with_prefix_count >= api_posted - tolerance`, OR rename the wave's tokens to `AUDIT-RUSH-A-IMMUNE-*` and exclude that pattern from `Iter15CleanupTestOrdersCommand::TOKEN_PATTERNS` for the duration of the run.

## Recommendation

1. **Close A-013 as `spec_mirage`** with the cache record as the dispositive artifact (full record reproducible at `Cache::store('redis')->get('idempotency:v1:1:16:'.hash('sha256', '1778456025793_19vd8ggpy_1_A'))`).
2. **Harden `dbConfirmByIdempotencyKey`** so a one-shot artisan probe failure cannot fake a `no_row_for_idempotency_key`: retry once with a 200 ms backoff, and on a second miss attempt a fallback lookup by `token` (also derivable from the spec's payload). The fallback adds a deterministic anchor that does not depend on the idempotency_key column being immediately visible.
3. **Re-classify the 38-vs-3 prefix discrepancy as a separate P2 (audit infra)** — it is a real signal of cross-process cleanup contamination, not a product silent-loss. Resolve by either prefixing audit tokens with an immune sentinel, or adding a `--exclude-prefix` flag to `iter15:cleanup-test-orders`.
4. **Do not spawn a product-fix agent** — the middleware is sound and the order was persisted. Spawning a "fix" agent against the middleware would risk a regression on a working hot path.
5. **Document the investigation finding** in the round-3 reviewer notes so the orchestrator can release the convergence gate on A-013.
