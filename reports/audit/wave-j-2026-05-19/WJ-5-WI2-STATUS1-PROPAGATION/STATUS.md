# WJ-5 — WI-2 P1 `where('status', 1)` Propagation Gap — STATUS

**Date** : 2026-05-19
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Scope** : SloEvaluatorJob + StockScanRupture status-filter propagation gap heal
**Class** : Mirror of Wave 2d FISCAL-ADV3C-01 (commits `7da06d641` + `c07acb16a`)
**Discipline** : TDD-first (RED → fix → GREEN), 0 frozen-zone touch, 0 DIRTY-file touch

---

## 1. Bug Summary

The `branches` table currently straddles two "active" sentinels in this codebase:

- legacy literal `1` (`BranchFactory` default + pre-enum prod seed)
- canonical `App\Enums\Status::ACTIVE = 5` (post-enum services + controllers)

The owner-flagged data migration `UPDATE branches SET status=5 WHERE status=1`
is pending (cf. `BranchFactory.php:28-39` + `Kernel::activeBranchIds()` PHPDoc).

**Wave 2d FISCAL-ADV3C-01** (commits `7da06d641` + `c07acb16a`) retrofitted the
two fiscal cron lanes (chain monitor + daily archive) to use
`Kernel::activeBranchIds()` so that the migration would not silently no-op them.

**WI-2 audit (R1)** caught two **non-fiscal** cron lanes that still used the
literal `where('status', 1)` filter — the same bug class survived the fiscal
heal because the helper was scoped to `Kernel.php` callers:

| Site | Line | Cron cadence | Post-migration outcome (pre-heal) |
|------|------|--------------|-----------------------------------|
| `app/Jobs/Observability/SloEvaluatorJob.php` | 48 | every 5 min | empty branch set → zero `ActionLog`, zero `observability.slo_breach`, zero Slack alert |
| `app/Console/Commands/StockScanRupture.php::targetBranches()` | 168 | every 5 min (when flag on) | empty branch set → preventive auto-86 stops flipping rupture rows |

Both cron lanes would have returned SUCCESS (silent) once the data migration ran
— recreating the exact "silent skip" class FISCAL-ADV3B-01 was opened to close.

**Evidence**: `reports/audit/wave-i-final-validation-2026-05-19/WI-2-CRON-BACKGROUND-JOBS/round-1/STATUS.md`
(L22-L60 + L149 + L169-L170) + Architect + RED-team agreement (WI-2-RED-01).

---

## 2. Heal Applied

### 2.1 SloEvaluatorJob

`app/Jobs/Observability/SloEvaluatorJob.php` — added `use App\Enums\Status;` import
+ replaced `where('status', 1)` with `whereIn('status', [Status::ACTIVE, 1])` +
inline rationale comment cross-referencing Wave 2d.

### 2.2 StockScanRupture

`app/Console/Commands/StockScanRupture.php::targetBranches()` — replaced
`where('status', 1)` with `whereIn('status', [Status::ACTIVE, 1])` (`Status`
was already imported). Added inline rationale comment cross-referencing Wave 2d.

### 2.3 Sentinel

`tests/Feature/Sentinels/StatusPropagationGapSentinelTest.php` (NEW, 5 tests):

1. `test_slo_evaluator_iterates_canonical_status_active_branch` — locks the heal
   for the SLO lane (was RED pre-heal).
2. `test_slo_evaluator_iterates_legacy_status_one_branch` — backward-compat
   guard against over-eager `where=Status::ACTIVE` fixes.
3. `test_stock_scan_rupture_iterates_canonical_status_active_branch` — locks
   the heal for the auto-86 lane (was RED pre-heal).
4. `test_stock_scan_rupture_iterates_legacy_status_one_branch` — backward-compat
   guard for the auto-86 lane.
5. `test_inactive_branch_is_excluded_by_both_lanes` — guards against
   over-eager fix that drops the status filter entirely.

Pattern mirrors `FiscalVerifyChainCommandTest::test_active_branch_ids_includes_both_legacy_and_canonical_status`
(Wave 2d sibling sentinel) so a future ADV finding lands once, not three times.

---

## 3. Discipline Compliance

| Check | Status | Evidence |
|-------|--------|----------|
| TDD-first (RED before GREEN) | OK | Initial run: 2 canonical tests FAIL + 3 backward-compat/inactive tests PASS (see §4.1 below) |
| Frozen-zone touch | 0 | `SloEvaluatorJob` + `StockScanRupture` + new sentinel are NOT listed in CLAUDE.md §7 |
| DIRTY-file touch | 0 | Neither target file appeared in the pre-session `git status` modified list |
| NF525 invariant impact | 0 | Heal does not touch fiscal services, audit chain, or pricing |
| Mirror Wave 2d pattern | OK | Comment cross-refs `[WJ-5 / WI-2 P1 2026-05-19]` + Wave 2d FISCAL-ADV3C-01 + `Kernel::activeBranchIds()` |

---

## 4. Test Evidence

### 4.1 Pre-heal sentinel run (RED, as designed)

```
PASS  Tests\Feature\Sentinels\StatusPropagationGapSentinelTest
  fail  slo evaluator iterates canonical status active branch         (RED — bug)
  pass  slo evaluator iterates legacy status one branch
  fail  stock scan rupture iterates canonical status active branch    (RED — bug)
  pass  stock scan rupture iterates legacy status one branch
  pass  inactive branch is excluded by both lanes

Tests:  2 failed, 3 passed
```

### 4.2 Post-heal sentinel run (GREEN, locking the heal)

```
PASS  Tests\Feature\Sentinels\StatusPropagationGapSentinelTest
  pass  slo evaluator iterates canonical status active branch
  pass  slo evaluator iterates legacy status one branch
  pass  stock scan rupture iterates canonical status active branch
  pass  stock scan rupture iterates legacy status one branch
  pass  inactive branch is excluded by both lanes

Tests:  5 passed (0.82s)
```

### 4.3 Targeted regression (mission spec: `Slo|StockScan|Branch`)

Full `php artisan test --filter "SloEvaluator|StockScan|StatusPropagation|FiscalVerifyChainCommand"`:

```
PASS  Tests\Feature\Fiscal\FiscalVerifyChainCommandTest               12/12
PASS  Tests\Feature\Observability\SloEvaluatorJobTest                  4/4
PASS  Tests\Feature\Sentinels\StatusPropagationGapSentinelTest         5/5
PASS  Tests\Feature\Stock\StockScanRuptureCommandTest                  6/6

Tests:  27 passed (3.99s)
```

### 4.4 Wider regression (`Slo|StockScan|Branch`)

`php artisan test --filter "Slo|StockScan|Branch"` ran 380 tests: **372 passed,
3 failed, 1 incomplete, 1 skipped**. The 3 failures are all in
`Tests\Feature\Composer\ComposerAuthzMinimalTest` (403 vs 404 expectations on
foreign-branch composer profile endpoints) and are **PRE-EXISTING on the branch**
(verified via `git stash` of my heal — same 3 failures reproduce unchanged).
They are unrelated to WJ-5 / WI-2 P1 and out of scope for this heal.

---

## 5. Files Changed (heal scope)

```
 app/Console/Commands/StockScanRupture.php  | 11 ++++++++++-
 app/Jobs/Observability/SloEvaluatorJob.php | 12 +++++++++++-
 tests/Feature/Sentinels/StatusPropagationGapSentinelTest.php  (NEW)
```

---

## 6. Commit

```
fix(cron-WJ-5-P1): status=1 propagation gap SLO+StockScan (mirror Wave 2d FISCAL-ADV3C-01)
```

Co-Authored-By: Claude Opus 4.7 (1M context)

---

## 7. Verdict

**WJ-5 / WI-2 P1: CLOSED.** Both non-fiscal cron lanes (SloEvaluatorJob,
StockScanRupture) now accept both legacy literal `1` AND canonical `Status::ACTIVE`
in the branch filter. The owner-flagged data migration `UPDATE branches SET status=5
WHERE status=1` can run at any time without silently no-op'ing the every-5-min
SLO sweep or the every-5-min auto-86 preventive cron. Pattern is now consistent
across fiscal + observability + stock cron lanes.

**Defense-in-depth backlog (out of scope here)**: the WI-2 audit notes that the
codebase would benefit from a single `Branch::activeIds()` model scope (or
exporting `Kernel::activeBranchIds()` as a Service helper) so that ANY future
caller picks up the multi-status filter by default. That refactor is V1.0.2.
