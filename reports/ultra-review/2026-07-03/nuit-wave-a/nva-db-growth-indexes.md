# NVA — DB Durability under Growth (indexes, N+1, outbox, hot tables)

Slug: `nva-db-growth-indexes` — HEAD `cfc23966a` — DB `foodking_e2e` (MySQL 8, EXPLAIN tree format)
Read-only. No DB/file writes except this report.

## Scope / thesis attacked
"La DB tient à 1 an de données." Current: 3079 orders in ~5 weeks → ~30k/an projected.
Attacked the HOT queries (KDS board, counter-collect queue, KDS history, dashboard reports),
outbox growth, N+1, soft-delete accumulation. EXPLAIN on the real SQL, live row counts.

## Row-count baseline (live)
- orders = 3079 (branch_id=1 → 3074 = single-box, branch filter has ~0 selectivity)
- order_items = 3126, order_payments = 259, audit_logs = 4781
- domain_events = 10011 (9974 dispatched, 37 pending), oldest = 2026-05-28 (35d)
- soft-deleted orders = 272

---

## FINDING 1 (P2, durability) — `/counter-collect/pending` = FULL TABLE SCAN on orders
`routes/api.php:807-853` — caisse "à encaisser" queue, polled (throttle:pos-order-update).

Query filters `payment_status = PENDING_COUNTER(15)` + `status != CANCELED` + branch + OR-block,
`ORDER BY created_at`. **There is NO index on `payment_status`.**

LIVE EXPLAIN (admin, branch_id=0):
```
-> Sort: orders.created_at ...
   -> Filter: payment_status=15 and status<>22 and deleted_at is null (rows=3050)
      -> Table scan on orders (cost=329 rows=3050)
```
LIVE EXPLAIN (branch staff, branch_id=1):
```
-> Index lookup on orders using orders_branch_user_idempotency_unique (branch_id=1) (rows=1525)
   -> Filter: payment_status=15 ...  + filesort on created_at
```
Single-box branch=1 holds 3074/3079 rows → the branch-prefix index gives no selectivity; the
effective work is a scan of ~all orders + a filesort, every poll.

**Time behavior:** PENDING_COUNTER rows stay bounded (~157 today; collected → PAID). But the
COST to FIND them scales with TOTAL orders (30k/an → 30k-row scan + filesort on each caisse poll).
Works today, degrades linearly.

Repro: `php artisan tinker` → `DB::select("EXPLAIN SELECT * FROM orders WHERE payment_status=15 AND status!=22 AND deleted_at IS NULL ORDER BY created_at LIMIT 200")` → "Table scan on orders".

**Fix proposal:** add composite index
`orders (branch_id, payment_status, created_at)` (or `(payment_status, created_at)` for the
admin/branch=0 path). Turns scan+filesort into an index range of ~157 rows, pre-sorted for the
`ORDER BY created_at`. Zero logic change, additive migration. Mirror index also benefits the
`payment_status` filter used by dashboard/status counters.

---

## FINDING 2 (P3, durability) — KDS `historyToday()` scans ALL status-7/8/9 rows regardless of date
`app/Services/KitchenDisplaySystemOrderService.php:221-250` — route `/history-today`
(KDS "Historique du jour" tab).

Query: `status IN (PREPARED, OUT_FOR_DELIVERY, DELIVERED) AND updated_at BETWEEN today..tomorrow
ORDER BY updated_at DESC LIMIT 50`. **No index on `updated_at`.**

LIVE EXPLAIN:
```
-> Sort: updated_at DESC, limit 50
   -> Filter: (updated_at between ...) and (deleted_at is null)  (rows=360)
      -> Index range scan using idx_orders_status over status IN (7,8,9)  (rows=360)
```
MySQL enters via `idx_orders_status` and materialises **every row in those statuses ever**, then
filters `updated_at` in memory + filesort. `DELIVERED(9)` is terminal and accumulates toward the
whole order table over time (in this test DB status 9 = 0 because flow doesn't reach delivery, but
status 7 already = 280). At 30k/an the entry set becomes ~all historical completed orders scanned
on each history poll for 50 rows of "today".

**Fix proposal:** index `orders (updated_at)` — or better `orders (status, updated_at)` so the
range collapses to today's window (~100 rows) before the status IN filter. Comment at L196-207
already flags `updated_at` as the de-facto bump-time axis; it deserves an index.

---

## FINDING 3 (P3, improvement) — `salesSummary()` runs one SUM query PER DAY of the range
`app/Services/DashboardService.php:243-257`. The per-day chart loops each day in
`[first_date..last_date]` and fires a separate `... ->realizedRevenue()->sum('total')`.

Each per-day query is indexed (idx_orders_datetime, ~100 rows/day) so no scan, but the query
COUNT scales with range length: default month = ~30 queries, a 1-year report = **365 round-trips**
to render one chart. Under concurrent report viewers this multiplies connection/latency pressure.

Repro: read `DashboardService.php:237-257` — `for` over `$dateRangeArray` with a query inside.

**Fix proposal:** replace the loop with one grouped query
`SELECT DATE(order_datetime) d, SUM(total) ... WHERE order_datetime >= ? AND < ? [realizedRevenue] GROUP BY d`
then zero-fill missing days in PHP. One query instead of N. Keeps Paris-TZ face-value bounds
(pass the same `$startParis`/`$endParisExclusive`; `DATE()` under session_tz=Paris matches the
existing per-day semantics). Non-frozen file.

---

## Robustness PROVEN (attacks that did NOT land — do not re-report)
- **KDS board `list()` / OSS**: filter on `order_datetime BETWEEN today` → LIVE EXPLAIN shows
  `Index range scan using idx_orders_datetime` narrowing to the day (~1-100 rows). Durable at 30k/an.
- **domain_events growth**: `foodking:outbox:prune --older-than-days=90` IS scheduled
  (`Kernel.php:176`, onOneServer, 04:00) + `foodking:webhook:prune` (180d, L192). Oldest event =
  35d, so nothing qualifies yet (that is why 0 are pruned locally — NOT a leak). Projected steady
  state ≈ 25.7k rows (90d rolling) — bounded. Indexes `idx_pending(dispatched_at,occurred_at)`,
  `idx_aggregate`, `idx_branch(branch_id,occurred_at)` present; the 37 "pending" are undispatched,
  not un-pruned. Prune design is correct.
- **audit_logs / order_payments** (6y retention → future biggest tables): already indexed for the
  date-range reports — `audit_logs (branch_id, created_at)`, `order_payments (branch_id, paid_at)`,
  `order_status_transitions (order_id, order_type, occurred_at)` + `(occurred_at)`. Fiscal reports
  stay sargable.
- **soft-deletes**: 272 soft-deleted orders; all read paths carry `deleted_at IS NULL` (SoftDeletes).
  NF525 forbids hard-deleting fiscal orders, so accumulation is by design, not a leak. No unbounded
  soft-delete table found without a purge counterpart.
- **KDS `orderItems()` merge**: eager-loads `orderItems` and groups in PHP, but the driving order
  set is the `order_datetime`-today range (bounded), so no N+1 explosion under volume.

## Verdict
IMPROVABLE — core hot paths (KDS board, OSS, outbox) are index-durable, but two hot queries
(counter-collect FULL SCAN P2, KDS history P3) and one report loop (P3) degrade with total-row
growth. All fixes are additive indexes / one query rewrite; no logic, no frozen zone, no NF525 touch.
