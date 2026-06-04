# Adversarial RED — Fiscal Heal Wave 2b — Wave 3b dispute

- Branch: `v1-0-1-hardening-2026-05-17`
- Commit under attack: `335b98134` — fiscal:verify-chain branch validation + distinct exit codes + daily cron
- Files reviewed: `app/Console/Commands/FiscalVerifyChainCommand.php`, `app/Console/Kernel.php`, `tests/Feature/Fiscal/FiscalVerifyChainCommandTest.php`. Frozen, read-only context: `app/Services/Fiscal/AuditLogService.php:199`, `app/Services/Fiscal/ZReportService.php:463`, `config/logging.php:182`.
- Posture: hostile. Frozen services NOT in scope; findings target wrapper + cron + test contract only.

---

## 2. Heal 2b-3 fiscal:verify-chain — verdict + findings

**Verdict: PARTIAL-GREEN. Heal commit overstates scope.**

The commit message claims it closes `FISCAL-ADV3-03 — daily NF525 audit chain monitor`. What it actually delivers is "daily monitor for branch=1 audit_logs chain." Two undisclosed scope narrowings (single branch hardcoded, z_reports HMAC chain not covered) leave the ADV3-03 objective open. The exit-code disambiguation (ADV3-02) and branch existence guard (ADV3-01) are technically clean — but ADV3-03 must be re-opened.

Frozen-zone preservation holds: no edit to `AuditLogService`, `ZReportService`, triggers, or migrations. Wrapper + Kernel + tests only.

---

## 3. P0 / P1 findings (new)

### `FISCAL-ADV3B-01` — **P0 — Daily cron monitors a single hardcoded branch**

`Kernel.php:174` schedules `fiscal:verify-chain --branch=1`. The same Kernel at `Kernel.php:189-201` iterates every active branch for the fiscal archive (`Branch::query()->where('status', 1)->whereNull('deleted_at')->pluck('id')->each(...)`). The new chain monitor does **not** mirror that pattern — it pins branch 1 forever.

Consequences: (1) any second branch (multi-resto rollout — CLAUDE.md §8 says "monotonic per branch") is **silently unmonitored**; NF525 detection window for branches ≥ 2 is unbounded — the same gap ADV3-03 was opened to close. (2) If branch 1 is renamed, soft-deleted, or its PK reassigned, the cron emits exit 2 daily (`FiscalVerifyChainCommand.php:55-59`) and `onFailure` fires forever with misleading `event=fiscal.chain.monitor.failure` — alert fatigue masking real tamper alerts. (3) Commit message "daily cron 03:30 via Kernel::schedule" hides the single-branch reality.

**Required fix:** replicate archive's branch-iteration, or drop `--branch` flag and iterate inside the command. ADV3-02 added exit 2 (INVALID) but cron then hardcodes a branch that may not exist post-admin-op — self-inflicted alert source. Most damning Wave 3b finding. **Re-open FISCAL-ADV3-03.**

---

### `FISCAL-ADV3B-02` — **P0 — z_reports HMAC chain not verified by daily monitor**

NF525 has two HMAC chains (CLAUDE.md §8): `audit_logs` re-walked by `AuditLogService::verifyChain()` (frozen, line 199), and `z_reports` re-walked by `ZReportService::verifyChain(int $branchId, ?bool $strict)` at `ZReportService.php:463` — separate signature, separate genesis hash via `fiscal.genesis_prev_hash`, sequence_no gap detection at lines 519-526.

The new wrapper only re-walks audit_logs (`FiscalVerifyChainCommand.php:65`). The daily cron at `Kernel.php:174` therefore covers one of the two NF525-critical chains. If z_reports rows are tampered (chain_break or sequence_gap surfaced by `ZReportService::verifyChain` lines 510-526), cron prints CHAIN OK exit 0 every morning. Worse: `ZReportService::open()` and `close()` already self-verify on every Z transition, so a tamper sitting between two Z events stays invisible until the next Z opens — 24h+ on a single-resto install. The whole point of the new daily monitor is to shorten that window; skipping z_reports halves the value.

**Required fix:** wrapper grows a second pass calling `app(ZReportService::class)->verifyChain($branchId)` and surfaces `errors[]`, or a second cron `fiscal:verify-z-chain`. NF525 objective was "monitor the chains," plural. Commit silently delivered singular.

---

### `FISCAL-ADV3B-03` — **P1 — `--branch=0` semantics: comment lies about behaviour**

`FiscalVerifyChainCommand.php:51-55` says "Admin/global branch_id=0 is permitted (cross-branch sweep)." But `AuditLogService.php:201-204` (frozen — observation only) filters `$query->where('branch_id', $branchId)` whenever `$branchId !== null`. True cross-branch sweep requires passing **`null`**, but the option at `FiscalVerifyChainCommand.php:48` is cast `(int) $this->option('branch')` — `0` is a real comparison value that filters the cursor to `branch_id = 0` rows only (admin/global writes, not all branches). Comment is materially wrong.

Naive operator reading docstring `FiscalVerifyChainCommand.php:31-34` runs `php artisan fiscal:verify-chain --branch=0` after a suspected breach to "verify everything" — gets a false-negative CHAIN OK on any compromise in branch ≥ 1 rows. **Same false-negative class** ADV3-01 was opened to close, just relocated.

**Required fix:** drop the special case (no global sweep advertised), or translate `--branch=0` / new `--all` flag into a loop over active branches (plays nicely with ADV3B-01), or reword to "branch=0 → admin-only chain rows." No test asserts `--branch=0` either way (§4-A). Current commit locks in misleading wording.

---

### `FISCAL-ADV3B-04` — **P1 — `onFailure` handler is fire-and-forget; no real-time alert**

`Kernel.php:176-180` logs `Log::channel('fiscal')->error('NF525 chain verify failed', ['event' => 'fiscal.chain.monitor.failure', 'branch_id' => 1])`. The `fiscal` channel (`config/logging.php:182-187`) is a `daily` driver writing to `storage/logs/fiscal.log` — file-only, no mail/SMS/Sentry forwarding. When a tamper lands (exit 1): nobody is paged; entry rotates out after 400 days but is never alerted on within minutes; noise duplicates with ADV3B-01 (exit 2 from a deleted branch) and trains on-call to ignore the channel. NF525 chain-breach detection-to-response should be minutes. Commit message "logs to the fiscal channel so any non-zero exit pages compliance" overstates — logging is not paging.

**Required fix:** add second leg in `onFailure` calling a Notification (mail to compliance + Slack/SMS). Out of scope; commit message wording must be tempered.

---

### `FISCAL-ADV3B-05` — **P1 — `catch (\Throwable $e)` swallows `Error` (PHP-level fatal)**

`FiscalVerifyChainCommand.php:66-72`. `\Throwable` = `\Exception | \Error`. Catching `\Error` masks `TypeError`, `AssertionError`, engine-level fatals that should crash loudly. ADV3-02 intent (docstring line 36-38) is "DB outage, missing fiscal.audit_secret, unexpected throw" — exceptions, not engine errors. Wider catch lets real bugs masquerade as monitoring failures: every PHP fatal in the verification path becomes exit 3 (which monitoring routes to ops as a config issue). **Fix:** catch `\Exception`, or catch `\Error` separately with a louder log + re-throw.

---

### `FISCAL-ADV3B-06` — **P1 — `withoutOverlapping()` default 1440-min cap can stall verification a full day**

`Kernel.php:175`. Laravel's default expiration on `withoutOverlapping()` is **1440 minutes**. If 03:30 hangs (`$query->cursor()` over multi-million-row `audit_logs`; lock starvation), next same-day run is skipped — and if the hang holds the cache lock past 03:30 next day, the *following* run is also skipped. Failure mode: "no verification for N days" with no alert (Laravel logs nothing by default when overlap skips). Comparable Kernel lines use shorter caps when they care: `foodking:fiscal:retry-alloc` at line 144 passes `withoutOverlapping(5)`. New monitor inherits default with no rationale. **Fix:** explicit expiration (e.g. `withoutOverlapping(60)`) + `->before(...)` log so silent skips can be detected by absence.

---

### `FISCAL-ADV3B-07` — **P2 — Test fragility: anon class with no-op `__construct`**

`FiscalVerifyChainCommandTest.php:173-183` binds an anon subclass with `public function __construct() {}`. Works **only** because real `AuditLogService` ctor has no required state for unrelated methods. The minute the frozen service gains a required dep (injected `Hasher`, `KeyManager`), the stub silently passes by skipping construction; unrelated tests that walk through this binding fail in non-obvious ways. Subclass+no-op-ctor breaks the encapsulation contract. **Fix:** `Mockery::mock(AuditLogService::class)->shouldReceive('verifyChain')->andThrow(...)`.

---

### `FISCAL-ADV3B-08` — **P3 — Race between `Branch::exists()` and `verifyChain()` (note only)**

`FiscalVerifyChainCommand.php:55-65` — two separate SQL round-trips. Concurrent admin deletes the branch between them. Verification then runs against an orphan `branch_id` — but `audit_logs` rows are immutable (DB trigger SIGNAL SQLSTATE '45000' per CLAUDE.md §8) so they persist post-delete and `verifyChain` correctly re-walks them. **Benign, file as note.**

---

## 4. Negative space

**4-A.** No test for `--branch=0`. Docstring `FiscalVerifyChainCommand.php:31-34` advertises admin/global semantics but no assertion locks the meaning. Per ADV3B-03 the misleading comment will survive the next refactor wave.

**4-B.** No test that `onFailure` actually emits on exit 1. `test_schedule_registers_daily_fiscal_chain_monitor` at test:203 only asserts the event exists. It does not assert `->onFailure(...)` was wired, nor that running the handler emits the expected `fiscal.chain.monitor.failure` Log record. A future commit could delete `onFailure` without breaking any test.

**4-C.** No assertion that hardcoded `--branch=1` corresponds to the active Le Cayenne row. Schema drift (resets, reseeds, multi-tenant rollout) silently retires the monitor. A simple `Branch::find(1)->status === 1` precondition test would catch it.

**4-D.** No verification of cache-driver capability behind `onOneServer()`. Laravel requires a shared cache (Redis/memcached). Local env may still be on `file` driver depending on `.env` — verify-chain would then run on every host, defeating de-dup.

**4-E.** No `z_reports` chain verification cron at all. Wave 2b heal added the audit_logs lane only. Per ADV3B-02 this is half the NF525 surface. Companion `fiscal:verify-z-chain` unfiled.

**4-F.** No test that schedule entry uses `dailyAt('03:30')` specifically. Test:203 walks `$schedule->events()` and only checks the *command string* contains `fiscal:verify-chain`. Future maintainer can switch to `everyMinute()` (DoS the DB) or `weekly()` (gut detection window) and the assertion still passes.

**4-G.** No `expectsOutputToContain('TAMPER')` regression with new try/catch in place. Pre-existing `test_tampered_chain_returns_failure_and_prints_tamper_id` (test:80) covers happy tamper path, but no test asserts the catch does **not** swallow a real tamper-caused throw inside `verifyChain` (per ADV3B-05).

**4-H.** No test for concurrent exit-2 → cron noise. If `--branch=1` is deleted, cron emits exit 2 + `onFailure` fires + log channel gets `fiscal.chain.monitor.failure` daily. No integration test, alert-rate cap, or de-dup. Per ADV3B-01 the architecture makes this likely.

---

## Closing verdict

Heal `335b98134` = **PARTIAL-GREEN**. Closed clean: ADV3-01 (branch guard), ADV3-02 (exit disambiguation, modulo P2 mock fragility + P1 `\Throwable` width). **Not closed:** ADV3-03 — single hardcoded branch + z_reports chain absent. Re-open as **ADV3B-01 (P0)** and **ADV3B-02 (P0)**. Two undisclosed scope narrowings in the commit message: "daily NF525 chain monitor" reads as "audit_logs + z_reports across all active branches"; what shipped is "audit_logs for branch=1 only." **Block Wave 2b sign-off on ADV3-03** until the two P0s heal. Frozen-zone discipline correctly held.
