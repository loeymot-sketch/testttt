# BACKLOG C10 — Counter-collect fiscal allocation has NO deadlock-retry / no `fiscal_alloc_error_at` self-heal
**Class:** cloud-prep robustness (Track B / multi-instance) — **sibling of UNI-03 (C1)** · **NOT a V1 blocker**
**Filed:** 2026-06-08 · Source: GOAL-100pct round-5 `ABUSE-EDGE.md` SUB-1 + FINDING-P2 · Owner anti-drift mandate: *"cloud/scale/multi-tenant = futur, JAMAIS un blocker V1."*
**Severity:** P2 (availability/robustness). **No fiscal invariant broke** — gap-free + no-dup held under true OS-process concurrency (16-process `pcntl_fork`, fiscal 2030→2067, DUPS=0 GAPS=0, CHAIN OK).

---

## Why this is filed, not healed
The advisor verdict and owner mandate are aligned and dominant: this is a **cloud-concurrency-class** finding. The trigger requires two *simultaneous* writers contending on the branch-1 fiscal aggregate lock. V1 LOCAL Le Cayenne runs **one register on a single-worker `php -S` server** (`PHP_CLI_SERVER_WORKERS` unset, verified `ps aux`) — HTTP requests *serialize*, so the HTTP burst of 12+10 confirms produced **0 deadlocks**. Healing it now would touch the money path (`PaymentService.php`) to defend a topology V1 does not have, *adding* risk to remove a non-V1 one. Deferred by design.

## Exact finding (proven, driven — not asserted)
- **Reproduction:** 16 real OS processes (`pcntl_fork`), shared start-barrier, each calls the full `PaymentService::confirmCounterPayment()` on a distinct order at the same instant → genuine contention on `Cache::lock('fiscal_seq_b1')` + the inner `SELECT MAX(fiscal_sequence_no) ... FOR UPDATE`. **12 succeeded** (fiscal 2055-2066, strictly consecutive, 0 gap, 0 dup). **4 deadlock-aborted** (`Illuminate\Database\DeadlockException`, SQLSTATE 40001/1213) → allocated **NO fiscal**, rolled back atomically (orders left `fiscal=NULL`, still PENDING-collectable). Full gap scan after: max=2066, DUPS=0, **GAPS=0**, CHAIN OK.
- **Over HTTP** the route closure (`routes/api.php:876-877`) catches generic `\Exception` → returns **422** to the cashier with **no auto-retry**. The cashier would simply re-press "Encaisser".
- **Recoverability PROVEN (driven):** re-collected a deadlocked order (257) → **HTTP 200, fiscal 2067, PAID**. No money lost, no fiscal corruption, no gap. The system is self-correcting *with a manual re-press*; the gap is the absence of *automatic* recovery.

## Root cause — lock-ordering inversion
`confirmCounterPayment` acquires a **per-order row lock** (`PaymentService.php:220-223`, inside `DB::transaction`) *before* calling `FiscalSequenceService::next()`, whose inner `lockForUpdate()->max()` needs an **aggregate FOR-UPDATE across ALL branch-1 orders** (`FiscalSequenceService.php:97-101`). The Redis `Cache::lock` serializes the *inner* fiscal region, but the *outer* per-order row locks are already held across processes when they reach the inner region → lock cycle → MySQL kills one as deadlock victim. The Redis lock cannot prevent it because the conflicting resource (the order row) is acquired **outside** the Redis-guarded section.

## Realistic 2-process V1-adjacent trigger (documented, not just "16 cashiers")
`foodking:fiscal:retry-alloc` runs **`everyMinute()`** (`app/Console/Kernel.php:266-267`) and calls `next()` for branch 1 to retry kiosk orders flagged `fiscal_alloc_error_at`. **BUT** that cron is gated by `if ($orders->isEmpty()) return self::SUCCESS;` (`RetryFiscalAllocCommand.php:18`) which sits **BEFORE** the `foreach` that calls `next()` (`:26`). So the cron only acquires the aggregate FOR-UPDATE **when kiosk orders are already flagged as failed** — i.e. the only realistic 2-process collision is *a deadlock during the retry of a previous deadlock*. This collapses the V1 reachability to "a deadlock has already happened", confirming the cloud-concurrency class.

## The substance of the P2 = an asymmetry, not a correctness break
The **kiosk-paid path** wraps allocation in try/catch and on failure sets `fiscal_alloc_error_at` → the every-minute cron self-heals (the CLAUDE.md §8 documented *"alloc fail → flag + retry cron, pas de gap"* resilience; wired across `FrontendOrderService.php`, `Order.php`, `RetryFiscalAllocCommand`, `Console\Kernel`). **`confirmCounterPayment` does NOT** flag or retry — it propagates the exception. Counter-collect simply lacks the documented fiscal-alloc-failure resilience that its sibling path already has.

---

## Recommended fix APPROACH (for the eventual cloud implementer — do NOT apply in V1)
All edits land in the **non-frozen** `app/Services/PaymentService.php`. **DO NOT touch the frozen `FiscalSequenceService` / `ZReportService` / `AuditLogService`.** Pick ONE of the two safe approaches below — **NOT** option (C).

**(A) Kill the inversion (preferred, addresses root cause).** Acquire the `fiscal_seq_b{branch}` Redis lock (or the aggregate fiscal region) **before** taking the per-order row lock, so every writer grabs the global fiscal resource first and the per-order lock second — uniform lock ordering removes the cycle entirely. Verify against the frozen `FiscalSequenceService` internal lock so you don't double-acquire/deadlock on the same Redis key.

**(B) Adopt the kiosk self-heal pattern (idempotent by construction).** On `DeadlockException` during alloc, set `fiscal_alloc_error_at` on the order and return a "payment recorded, fiscal pending" success to the cashier; let the every-minute `retry-alloc` cron complete the allocation. This reuses a path already proven gap-free and **single-fire by design** (the cron's `next()` is idempotent per order via the `fiscal_sequence_no IS NULL` guard).

### ⚠️ HAZARD TO FLAG — do NOT use a blind whole-transaction retry (option C)
The naive fix — wrapping the existing closure in `DB::transaction($cb, $attempts=3)` — is **unsafe here** and is explicitly rejected. **Be precise about *why*:** DB writes performed inside the closure (the `order_payments`/`cash_movements` rows, the fiscal-sequence row, the audit-chain rows) are within the transaction's rollback envelope — on a deadlock abort they roll back and re-execute cleanly on retry, so they are **NOT** a true double-fire. The genuine hazard is **non-transactional** side effects that escape the rollback: **domain events dispatched WITHOUT `afterCommit` (fire on dispatch, not on commit), queued jobs pushed mid-closure, external notifications/webhooks, and any `Cache`/Redis mutation outside the fiscal lock.** Laravel re-runs the **entire closure** on deadlock retry, so those non-transactional effects fire **once per attempt** → duplicate event/job/notification (e.g. a KDS/print event fired on the aborted attempt AND again on the successful retry). The safe fixes (A) and (B) are narrow and single-fire by construction; the blind retry trades a clean deadlock-abort for a potential double-non-transactional-effect. The eventual implementer **must** enumerate the closure's *non-transactional* effects (search for `event(`/`dispatch(`/`Notification::`/`->notify(`/non-`afterCommit` listeners) and prove each idempotent before considering any retry-the-closure approach — and should prefer (A) or (B) instead.

## Acceptance criteria (when this is eventually picked up)
- Re-run the round-5 16-process fork burst (`/tmp/abuse-edge/fork_race.php` harness; rebuild on the e2e clone) → **0 deadlock-422 to the cashier** OR every deadlocked collect auto-recovers (fiscal allocated within ≤1 cron tick) — AND fiscal still **gap-free, 0-dup, CHAIN OK**.
- New regression test (TO BE CREATED at `tests/Feature/Pos/CounterCollectFiscalDeadlockRecoveryTest.php`) asserting: a simulated alloc deadlock leaves the order recoverable AND no double side-effect (single audit row, single OrderPayment, single fiscal number).
- `FrozenZoneSha256BaselineSentinel` 1/1 (no frozen drift). `php artisan fiscal:verify-chain --all` CHAIN OK.

## Cross-references
- `reports/test-e2e/goal-100pct-2026-06-07/round-5/ABUSE-EDGE.md` (full driven evidence + harness)
- `reports/cloud-readiness/CLOUD_MIGRATION_DOSSIER_2026-06-04.md` cloud-delta table (C10 row) — sibling **C1=UNI-03** (cache-driver guard) and **B5** (single primary DB writer constraint)
- CLAUDE.md §8 (kiosk-paid `fiscal_alloc_error_at` resilience this path lacks) · §7 (frozen `FiscalSequenceService` — out of bounds)
