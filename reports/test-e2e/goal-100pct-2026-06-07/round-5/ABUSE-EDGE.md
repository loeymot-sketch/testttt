# ROUND-5 — ABUSE-EDGE agent report
**Date:** 2026-06-08 · Agent: ABUSE-EDGE (concurrency / idempotency / input-boundary)
**Target:** disposable clone `foodking_e2e` @ `http://127.0.0.1:8766` (MySQL + Redis cache/queue). **NEVER touched operating DB `foodking`** (verified: operating max_fiscal_b1=2000, clone=2067 — fully separate).
**Method:** concurrency driven two ways — (1) `curl_multi` over HTTP, and (2) **`pcntl_fork` true OS-process concurrency** calling the full service path with a shared start-barrier (the definitive lock-contention test). `APP_ENV=e2e php artisan tinker` used ONLY for setup/inspection — NO `php artisan test` / RefreshDatabase (DEVDB-GUARD + footgun avoidance).

## VERDICT: ALL 5 AREAS PASS — 0 P0/P1. NF525 gap-free/no-dup invariant held under TRUE concurrency. One P2 cloud-prep robustness finding (deadlock-without-retry; no invariant broke).

Baseline: max_fiscal_b1=**2029**, DUPS=0 GAPS=0, CHAIN OK.
Final: max_fiscal_b1=**2067**, count=2067, unique=2067, **DUPS=0 GAPS=0**, CHAIN OK.
I caused allocation of fiscal **2030→2067** across HTTP bursts + a 16-process fork burst + idempotency/parked/recovery — every number strictly consecutive, 0 gap, 0 dup, even with 4 transactions deadlock-aborted under contention (they rolled back atomically → no orphan, no gap). Transient branch-2 test artifacts cleaned up (branch count back to 1).

---

## SUB-1 — CONCURRENCY / GAP-FREE UNDER RACE — **PASS** (true-concurrency proven)

### 1a. HTTP bursts (curl_multi) — gap-free, but SERIALIZED by the single-worker dev server
- Burst A: 12 CASH confirms, orders 230-242 → fiscal **2030-2041**, 12 unique, 0 dup, consecutive, 0 gap.
- Burst B: 10 mixed-mode (CASH/CARD/MOBILE/TR/OTHER), orders 244-253 → fiscal **2045-2054**, 10 unique, 0 dup, consecutive.
- **Honest caveat:** `:8766` is `php -S` with `PHP_CLI_SERVER_WORKERS` UNSET = single-worker (verified `ps aux`). Near-linear wall-clock (714ms/12, 541ms/10 ≈ 54-59ms each) confirms the server drained the 12 sockets **sequentially**. So this proves **rapid-succession gap-freeness** — which IS the real V1 production topology (one register, single-worker server) — but the Redis `Cache::lock` never had a true competitor here.

### 1b. Fork burst (pcntl) — TRUE simultaneous lock contention — gap-free held
16 real OS processes, each boots the kernel, spins on a shared start-barrier, then all call **the full `confirmCounterPayment()`** at the same instant on distinct orders (254-428), genuinely contending on `Cache::lock('fiscal_seq_b1')` + the inner `SELECT MAX(...) FOR UPDATE`. Overlapping timings (95ms→1838ms, NOT linear) = real parallelism.
- **12 succeeded → fiscal 2055-2066: 12 unique, 0 dup, strictly consecutive, NO GAP.**
- **4 deadlock-aborted** (`Illuminate\Database\DeadlockException` SQLSTATE 40001/1213 on the `... for update`) → allocated **NO fiscal**, rolled back atomically (verified: orders 257/263/265/266 left `fiscal=NULL`, still PENDING-collectable).
- Full-dataset gap scan after: max=2066, DUPS=0, **GAPS=0**, CHAIN OK. **The legally-critical NF525 gap-free invariant was never violated under genuine contention** — the triple-defense prefers aborting a txn over risking a gap, which is correct.
Evidence: `app/Services/Fiscal/FiscalSequenceService.php:57-114`; clone `CACHE_DRIVER=redis` + `DB_CONNECTION=mysql`.

## SUB-2 — IDEMPOTENCY — **PASS** (behaved exactly per K2-HEAL-01 design)
Order 243, 4 calls; invariant = single fiscal / single Transaction / single audit / no double side-effect:
| Call | Actor | Result | Correct? |
|------|-------|--------|----------|
| 1 first collect | cashier A, key K1 | 200, fiscal 2042 allocated | ✅ |
| 2 replay SAME key | cashier A, K1 | 200, **same** fiscal 2042 (IdempotencyKeyMiddleware cache replay) | ✅ |
| 3 DIFFERENT key, same cashier | cashier A, K2 | 200 no-op, **same** fiscal 2042 (service PAID short-circuit, `collectorUserId===currentUserId`) | ✅ documented K2-HEAL-01 — NOT a defect |
| 4 DIFFERENT cashier | cashier B, K3 | **409 `payment_already_collected`, collected_by=3** | ✅ |

Post-state order 243: fiscal=**2042 (exactly 1)**, Transactions=**1** (tx#665 counter_cash 2.00), audit rows=**1**, global CashMovement **171→171 (0 new — by design, drawer not inflated)**.
Evidence: `app/Services/PaymentService.php:278-310`; `routes/api.php:858-878` (409 above generic 422; 409 not cached).
Note: same-order *simultaneous*-arrival TOCTOU on the middleware cache was covered sequentially, not truly-parallel (single-worker server). Documented behavior demonstrated; simultaneous arrival not stressed over HTTP.

## SUB-3 — PRICING SSOT + INPUT BOUNDARY — **PASS**
- **Forged total/subtotal=0.01** via full signed quote→store (quote=6.00 for item1×2): server created order 4247 with **total=6.00, subtotal=6.00** — forged values ignored. Evidence: `OrderService.php:899` (`prix TOUJOURS depuis la DB`) + `OrderService.php:1059-1064` `sealForCommit(...,$server->total)` + `OrderQuoteService.php:120-121` (409 if sealed≠server). Double defense: POS commit also 401s without a valid server-signed quote (`OrderQuoteService.php:113-116`).
- Negative qty (-5) → 422; zero qty (0) → 422 (`ValidJsonOrder.php:66`).
- Unknown item_id (100058) → 422 "Article ... introuvable" (`OrderService.php:893-895`).
- Unknown variation/extra → 422 (`PricingService.php:146-150,176-180`).
- **Cross-item variation injection** (variation id=1 belongs to item 22, attached to item 1) → 422 "n'appartient pas à l'article 1" (`PricingService.php:152-156`).
- Negative discount (-100) → 422 (`PosOrderRequest.php:85`).

## SUB-4 — PARKED RACE — **PASS**
- **Park → 2 PARALLEL recalls** of parked #15: recall#0 → 200 payload, recall#1 → **404 not_found**; row consumed **exactly once**. Evidence: `PosParkedOrderService.php:72-103` (`DB::transaction`+`lockForUpdate`+`delete()`).
- **No premature fiscal at park:** `pos_parked_orders` has **NO `fiscal_sequence_no` column** — structurally can't allocate. Fiscal allocated exactly once at the real commit (gap-free); a follow-on collect of an already-fiscal'd order correctly returned 409 (no 2nd allocation).

## SUB-5 — BRANCH ISOLATION ABUSE — **PASS**
Transient branch 2 + order 4249 created on clone; attacked as cashier A (branch_id=1, scoped):
- Counter-collect **CONFIRM** on branch-2 order → **404 ORDER_NOT_FOUND** (BranchScope binding refused; no mutation).
- Counter-collect **CANCEL** → **404 ORDER_NOT_FOUND**.
- `counter-collect/pending` (real scoped JSON): branch-2 order **NOT visible**; no branch-2-tagged order leaked.
- `GET /api/admin/order/4249` → 200 but `Content-Type: text/html` (SPA catchall, known masquerade), **0 matches** of branch-2 serial/token → NOT a JSON leak.
Cleaned up branch-2 artifacts after.

---

## FINDING — P2 (cloud-prep robustness) — counter-collect fiscal allocation has NO deadlock retry / no `fiscal_alloc_error_at` net (diverges from the kiosk-paid §8 pattern)
**No invariant broke** (gap-free/no-dup held; deadlocked txns roll back clean; order stays collectable). This is an **availability/robustness** gap under true contention, not a fiscal-correctness break — same class as convergence-verdict UNI-03 (single-box-safe, needs hardening for cloud/multi-instance).

- **Reproduction:** 16-process fork burst (above) → 4 of 16 `confirmCounterPayment` calls hit `DeadlockException` on `select max(fiscal_sequence_no) ... for update`. Over HTTP the route closure catches generic `\Exception` → **422** to the cashier (`routes/api.php:876-877`), no auto-retry.
- **Recoverability PROVEN (driven, not asserted):** re-collected deadlocked order 257 → **HTTP 200, fiscal 2067, PAID**. No money lost, no fiscal corruption.
- **Root cause = lock-ordering inversion:** `confirmCounterPayment` takes a per-order row lock (`PaymentService.php:220-223`) *before* calling `FiscalSequenceService::next()`, whose inner `lockForUpdate()->max()` needs an aggregate FOR-UPDATE across ALL branch-1 rows (`FiscalSequenceService.php:97-101`). The Redis `Cache::lock` serializes the inner region, but the outer per-order row locks are already held across processes → cycle → MySQL kills one. The Redis lock can't prevent it because the conflicting resource (the order row) is acquired OUTSIDE the Redis-guarded section.
- **Realistic V1 2-process trigger (not just "16 cashiers"):** `foodking:fiscal:retry-alloc` runs **`everyMinute()`** via cron (`app/Console/Kernel.php:266-267`) and calls `next()` for branch 1 to retry kiosk orders flagged `fiscal_alloc_error_at`. If that worker tick coincides with a cashier counter-collect, both contend on the same `MAX...FOR UPDATE` → one can deadlock → the cashier's confirm 422s.
- **Asymmetry = the substance of the P2:** the **kiosk-paid path** wraps allocation in try/catch and on failure sets `fiscal_alloc_error_at` → the every-minute cron self-heals (the §8 documented "alloc fail → flag + retry cron, pas de gap" resilience — wired in `FrontendOrderService.php`, `Order.php`, `RetryFiscalAllocCommand`, `Console\Kernel`). **`confirmCounterPayment` does NOT** flag or retry — it just propagates the exception. Counter-collect diverges from the documented fiscal-alloc-failure resilience that the sibling path has.
- **Recommendation (report-only — I am read-only audit):** in the **non-frozen** `app/Services/PaymentService.php` (NOT the frozen `FiscalSequenceService`), wrap the alloc in a bounded deadlock retry (`causedByConcurrencyError` / `attempts` loop) and/or acquire the Redis `fiscal_seq` lock before the outer order row-lock to remove the inversion, and/or adopt the kiosk `fiscal_alloc_error_at` flag so the every-minute cron self-heals a deadlocked counter-collect. **V1 single-box single-worker topology does not trigger this in normal operation** (HTTP burst had 0 deadlocks because requests serialize) — owner anti-drift mandate keeps cloud concurrency out of V1 blockers, so this is cloud-prep backlog, not a V1 gate.

## NON-BLOCKING (P3)
- **No upper-bound cap on line quantity** — quote for item1 × 999,999,999 computed total_ttc=2,999,999,997 (math correct, no overflow/negative, server-authoritative). A sanity ceiling would be defense-in-depth; no exploit. P3.
- **Clone test-pollution:** campaign added ~30 paid orders + advanced the sequence on disposable `foodking_e2e` (re-cloneable). Already a flagged hygiene item.

## Harness (durable, /tmp/abuse-edge/)
`burst.php` (12-way HTTP), `burst2.php` (mixed-mode HTTP), `fork_race.php` (**16-process true concurrency**), `recall_race.php` (parked race), `pricing_abuse.sh` (input boundary). Tokens: cashier A uid=3/branch1, cashier B uid=6/branch1 (distinct, for the 409 path), admin uid=1. Auth = `x-api-key` (`config('app.api_key')`) + Bearer wildcard sanctum token (wildcard required to pass `block_kiosk_token_admin`).
