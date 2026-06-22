# Adversarial RED — Fiscal Heal Wave 2c — Wave 3c dispute

**Branch:** `v1-0-1-hardening-2026-05-17`
**Commit under review:** `0f49258dd` — *fix(fiscal): verify-chain covers z_reports + cron iterates all active branches (Wave 3b 2xP0)*
**Posture:** hostile, read-only, NF525 mandatory, NO cloud, NO frozen-zone modification suggested.
**Verdict:** **PARTIAL-GREEN.** Two declared P0s land structurally, but the heal entrenches one false-negative class flagged in 3b and silently imports a system-wide status-drift pattern that re-creates the "silently skipped branch" vulnerability the heal was supposed to close.

---

## 2. Heal 2c-2 verify-chain + cron — what closed, what didn't

### Closed clean

- **ADV3B-02 (z_reports lane absent)** — closed at `FiscalVerifyChainCommand.php:82`: `$zResult = $zService->verifyChain($branchId, false)` invokes the frozen public API. `strict=false` choice is correct: per `ZReportService.php:561-568` strict=true throws `RuntimeException` for the same tamper classes already accumulated in `errors[]`, which would collapse exit-1 (TAMPER) into exit-3 (exec error) per command:83-90. Heal owns exit-code semantics; service owns tamper detection.
  - Frozen-zone discipline: the new `callServiceSignature` reflection helper in `tests/Feature/Fiscal/FiscalVerifyChainCommandTest.php:329-336` is legitimate read-only access to private `ZReportService::computeSignature` (`ZReportService.php:670`); does not modify frozen code.

- **ADV3B-01 (`--branch=1` hardcoded)** — substantively closed at `Kernel.php:174-211`: `$schedule->call(closure)` plucks `Branch::query()->where('status',1)->whereNull('deleted_at')`. Closure executes on each schedule fire (Laravel `CallbackEvent::run()`), so attack-surface vector 7 (stale capture) does not apply. Per-branch `try/catch` at `Kernel.php:181-198` logs non-zero exits with `event=fiscal.chain.monitor.failure` + `branch_id` + `exit_code` — no silent swallow. **But** the iteration *filter* re-introduces a silent-miss class — FISCAL-ADV3C-01 below.

---

## 3. P0/P1 findings (NEW — non-empty; heal not convergent)

### `FISCAL-ADV3C-01` — **P1 — Branch status drift: `where('status', 1)` misses `Status::ACTIVE = 5` rows; heal inherited a system-wide fragility without flagging**

`app/Console/Kernel.php:177` filters `Branch::query()->where('status', 1)`. Canonical "active" sentinel is **`App\Enums\Status::ACTIVE = 5`** (`app/Enums/Status.php:7`). The codebase straddles two conventions:

- `app/Services/BranchService.php:138-139,204` — `where('status', Status::ACTIVE)` (= 5).
- `app/Http/Controllers/Admin/OrderStatusScreenController.php:80,121` — `Status::ACTIVE`.
- `app/Listeners/PersistCatalogChangedToOutbox.php:39` — `whereIn('status', [Status::ACTIVE, 1])` plus comment line 38 telegraphing "Owner action: data migration `UPDATE branches SET status=5 WHERE status=1`."
- `database/factories/BranchFactory.php:39` — hardcodes `'status' => 1` with explicit drift commentary lines 28-38.
- `app/Http/Controllers/Admin/StockRuptureDashboardController.php:135` — comment "Branches use the canonical Status enum (ACTIVE=5). Hard-coding `status=1`..."

Heal's defence at `Kernel.php:168` ("Pattern now mirrors fiscal:archive") — true, `Kernel.php:219` (fiscal-archive scheduler) also uses `where('status', 1)`. **That parity is the bug, not the strength.** ADV3B-01's complaint was singular hardcoding silently dropping branches; the heal replaced "hardcoded ID" with "hardcoded legacy enum literal" — the silent-miss class survives, relocated.

**Concrete attack:** operator runs the owner-flagged migration (`UPDATE branches SET status = 5 WHERE status = 1`) telegraphed at three sites listed above. Branches flip to `status=5`. Next 03:30 cron: `Branch::where('status', 1)->pluck('id')` returns empty; `each` runs zero times; outer `try/catch` at `Kernel.php:175,200` succeeds with no error. **Cron silently no-ops for every NF525 chain forever**, no alert, until an operator notices the absence of success log lines. Detection-window-for-tamper goes back to unbounded — rebuilds the exact failure ADV3B-01 was opened to close.

Heal should have either (a) used `whereIn('status', [Status::ACTIVE, 1])` like `PersistCatalogChangedToOutbox.php:39`, or (b) flagged the inherited fragility in the commit message. It did neither. Commit message line 5 presents fiscal:archive parity as a strength.

**No test.** `test_schedule_registers_daily_fiscal_chain_monitor_for_all_branches` at `FiscalVerifyChainCommandTest.php:210-232` asserts only the schedule name `fiscal-chain-monitor-all-branches`. Never executes the closure, never verifies pluck against `Status::ACTIVE`. Data migration will pass tests and silently break prod monitoring.

---

### `FISCAL-ADV3C-02` — **P1 — `errors[0]` reporting drops all subsequent breaches; staged tamper requires N cron runs to fully surface**

`FiscalVerifyChainCommand.php:104-109` extracts only `$zResult['errors'][0]['z_id']`. Per `ZReportService.php:486-544`, `verifyChain` accumulates **every** breach into `errors[]` — three kinds: `chain_break` (line 511), `sequence_gap` (line 521), `signature_mismatch` (line 532). A coordinated tamper hitting multiple rows returns e.g. `errors=[{id:42,signature_mismatch},{id:55,signature_mismatch},{id:67,chain_break}]`. Command names only `z_reports.id=42`.

Operator pulls Z42, re-imports archive, re-runs verify-chain manually — still exit 1, names Z55. Then Z67. **N-1 cron runs of partial visibility** when one pass was sufficient. Worse: between fixes the operator may re-open the daily Z cycle assuming chain validated, opening a window where new signatures sign over corrupted prev_hash. Compounding instead of containing.

`ZReportService::verifyChain` log line at `ZReportService.php:548-556` does dump full `errors`, but to `storage/logs/fiscal.log` only — not command stdout that operations parses. **Fix in scope, ~4 LOC, no frozen-zone touch:** loop `$zResult['errors']` into `$tamperFragments` at command:104.

No test: `test_tampered_z_report_chain_returns_failure_and_prints_z_report_id` (test:241-321) seeds exactly one tamper (`total_ttc` on `z2`), so single-error reporting passes by accident.

---

### `FISCAL-ADV3C-03` — **P1 — `--branch=0` special case ENTRENCHED, not closed (regression-of-class from ADV3B-03)**

Wave 2c added `if ($branchId !== 0 && ! Branch::where('id', $branchId)->exists())` at `FiscalVerifyChainCommand.php:64` — the `!== 0` bypass survived the heal. ADV3B-03 flagged docstring lines 60-62 lying about cross-branch sweep. Heal **codified** the lie in flow control without behavioural fix:

- `AuditLogService::verifyChain(0)` at `AuditLogService.php:199-231` — line 202 enters `if ($branchId !== null) { $query->where('branch_id', $branchId); }`, filters to `branch_id = 0` rows only (admin/global writes). Not a sweep.
- `ZReportService::verifyChain(0, false)` at `ZReportService.php:480-484` — `where('branch_id', 0)`. Returns `['valid'=>true, 'first_z_id'=>null,...]` because `branch_id=0` z_reports do not exist; initial state `'valid'=>true` at line 487 is never flipped if the loop body executes zero times.

Operator who reads docstring lines 60-62 and runs `php artisan fiscal:verify-chain --branch=0` after a suspected breach gets: **exit 0, CHAIN OK (audit_logs + z_reports) (branch=0)**. False-negative for any real tamper in `branch_id >= 1` rows. Identical class to ADV3-01 (Wave 3 P1, supposedly closed).

No test for `--branch=0`. Test file covers branch ids `>= 920_601`. The `!== 0` clause at command:64 is untested and undocumented.

---

### Negative findings (probed, do NOT apply)

- **Vector 1** (strict=false under-protects): false — `ZReportService.php:561-568` strict=true only differs by throwing for same classes already in `errors[]`. Heal correct.
- **Vector 4** (audit throw short-circuits z): confirmed at command:80-90 — DB outage during audit verify silently delays z monitoring 24h. **`FISCAL-ADV3C-04` (P2)**, below P1 line.
- **Vector 5** (ambiguous exit when both tampered): both fragments concat into one line + exit 1. Operator parses stdout. Acceptable V1, P2.
- **Vector 7** (closure stale capture): false — `$schedule->call(fn)` evaluates per fire.
- **Vector 10** (test 8 matches name not behaviour): test matches `event->description`. `Illuminate\Console\Scheduling\Event::name()` at `vendor/laravel/framework/src/Illuminate/Console/Scheduling/Event.php:875-877` delegates to `description($description)`. Structurally valid match — but negative space (no behavioural assertion on iteration) folded into ADV3C-01.

---

## 4. Negative space + deferred-from-3b status

| ID | 3b verdict | 3c status | Evidence |
|---|---|---|---|
| `ADV3B-03` | P1 deferred | **STILL OPEN + entrenched** | `FiscalVerifyChainCommand.php:64` codified misleading semantic without test or behavioural fix. Re-opened as `ADV3C-03`. |
| `ADV3B-04` | P1 deferred | **STILL OPEN** | Heal removed `->onFailure(...)` and replaced with inline `Log::channel('fiscal')->error(...)` at `Kernel.php:186-190`. Functionally equivalent — file-only, no pager/mail/Slack. Commit phrase "pages compliance" (carried from ADV3-03 wording) remains false. |
| `ADV3B-05` | P1 deferred | **STILL OPEN** | `FiscalVerifyChainCommand.php:83` still `catch (\Throwable $e)`. `TypeError`/`AssertionError`/engine fatals still masquerade as exit-3. Unchanged. |
| `ADV3B-06` | P1 deferred | **STILL OPEN + worse** | `Kernel.php:210` `->withoutOverlapping()` no argument — Laravel default 1440-min cap. Heal now iterates *multiple* branches per run, increasing the chance of crossing the 24h boundary if any branch hangs. |
| `ADV3B-07` | P2 deferred | **STILL OPEN** | `FiscalVerifyChainCommandTest.php:175-185` anon subclass with no-op `__construct`. Unchanged. |
| `ADV3B-08` | P3 benign | Unchanged | Immutability triggers still protect rows. Benign. |

**4-A.** No test for `--branch=0` semantic (ADV3C-03). Carried from ADV3B-03 §4-A; 2c added behaviour without test.

**4-B.** No test that closure at `Kernel.php:174-207` iterates a `Status::ACTIVE` branch (ADV3C-01). Future data migration passes tests, breaks prod.

**4-C.** No test for multiple-z-tamper output completeness (ADV3C-02).

**4-D.** No test that `fiscal-chain-monitor-all-branches` runs `dailyAt('03:30')`. Test asserts name substring only. A future maintainer can switch to `everyMinute()` (DoS) or `weekly()` (gut detection) and the assertion still passes. (From ADV3B §4-F; 2c did not address.)

**4-E.** No regression that a `\Throwable` from `ZReportService::verifyChain` routes to exit 3, not false-TAMPER. ADV3-02 intent codified for audit_logs only; new z-path untested.

**4-F.** No alert-rate cap, no de-dup. If a branch is soft-deleted between runs, exit 2 fires daily, log fills, on-call habituates. Carried from ADV3B-01 §4-H.

---

## Closing verdict

**Heal `0f49258dd` = PARTIAL-GREEN.** Two declared P0s structurally land (z_reports lane present, multi-branch iteration present, frozen-zone discipline held), but:

- **2 NEW P1s opened by the heal** — ADV3C-01 (status drift inherited without flag), ADV3C-02 (errors[0] dropping subsequent breaches).
- **1 P1 regression-of-class** — ADV3C-03 (`--branch=0` codified, not fixed).
- **4 of 5 deferred-from-3b P1/P2 untouched** — ADV3B-04, -05, -06, -07.

Not convergent. "200/200 Fiscal regression suite green" in commit message is true but uninformative: the suite asserts none of the failure modes above. The new `test_tampered_z_report_chain_*` is correct for single-tamper; cannot stand in for system-level concerns.

**Recommend Wave 2d to land:**

1. `whereIn('status', [Status::ACTIVE, 1])` at `Kernel.php:177` (and mirror at line 219), plus a behaviour test seeding `status=5` and asserting the closure plucks its id. Closes ADV3C-01 without frozen-zone touch.
2. Loop `$zResult['errors']` into `$tamperFragments` at `FiscalVerifyChainCommand.php:104`. Closes ADV3C-02. ~4 LOC.
3. Decide `--branch=0` semantic: either drop bypass at command:64 (operator must pass real id) or implement explicit `--all` flag iterating active branches. Update docstring lines 60-62. Closes ADV3C-03 / ADV3B-03.
4. Address ADV3B-04/05/06/07 explicitly or move to V1.0.2 backlog with rationale. Silent carry-forward across three waves is itself a process finding.

Frozen-zone discipline held. No NF525 chain semantics modified. Le Cayenne V1 single-resto: monitoring works for `branch_id=1` with `status=1` (matches current prod DB) — but the heal's robustness margin is razor-thin and inversely correlated with multi-tenant rollout or the pending status-data migration.
