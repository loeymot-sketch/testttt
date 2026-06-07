# FORENSICS — Root-cause of the 4 full-suite "OSS empty-feed" failures

**Agent:** FORENSICS (round-5, goal-100pct) · **Date:** 2026-06-08
**HEAD:** `6b56e0b5d` (branch `heal/pre-cloud-exec-2026-06-05`)
**DB under test:** sqlite `:memory:` (phpunit.xml, the CLEAN path) — NOT the operating NF525 DB.

---

## VERDICT (one line)

The 4 failures are a **wall-clock midnight-boundary flake intrinsic to the OSS tests'
own relative-time seeding** (`order_datetime = now()->subMinutes(5)`) vs the
**today-scoped** OSS feed — **NOT** caused by the 6 heals, and **NOT** caused by any
clock-polluter test. **There is no polluter.** Fixed by anchoring the two OSS test
classes' clock to local noon in `setUp` (test-only, additive, non-frozen).

| Question (from brief) | Answer |
|---|---|
| (a) Pre-existing & independent of the heals? | **YES** — proven (isolation green; mechanism touches neither Carbon nor the heals; same code as the "4-fail" docs commit). |
| (b) The exact polluter? | **NONE.** The clock-pollution hypothesis is **disproven** (see Exp 1 + Exp 3 + the `hasTestNow=false` measurement). Real cause = midnight-boundary flake. |
| (c) Fixed or documented? | **FIXED** — `Carbon::setTestNow(today()->addHours(12))` in both OSS test `setUp`. Red-before / green-after proven. Full suite 3046/0. CHAIN OK. |

---

## The mechanism (airtight)

`OrderStatusScreenOrderService::list()` / `::listForBranch()` scope the feed to TODAY:

```php
$todayStart = Carbon::today($appTz);                 // app/Services/OrderStatusScreenOrderService.php:87 / :235
$todayEnd   = Carbon::today($appTz)->endOfDay();     // :88 / :236
$query->...->whereBetween('order_datetime', [$todayStart, $todayEnd])->where('is_advance_order', NO)
      ->where('order_datetime', '>=', now($appTz)->subHours(8));   // :132 / :256 (8h stale floor)
```

Both OSS tests seed the eligible order with a **relative** datetime:
- `OssPublicNoPiiTest::seedOrderWithPiiCustomer()` → `order_datetime => now()->subMinutes(5)` (line 91)
- `OssCustomerScreenFilterTest` (takeaway/kiosk/delivered tests) → `now()->subMinutes(5)` / `subMinutes(15)`

A **consistent frozen clock does NOT break this** (order-creation and the query read the
same `now()` inside one method; `now-5min` is always after `now-8h`). The **only**
exclusion vector is `now()-5min` landing on a *different calendar day* than `Carbon::today()`
— which happens only when the **real wall-clock** is within the first ~5–15 min after local
midnight: `now()->subMinutes(5)` = yesterday 23:55..23:5x → falls outside `[today 00:00,
today 23:59]` → feed returns `{"data":[]}` → every "the order IS present" assertion fails.

The 2026-06-07 attestation run that reported the 4 failures executed inside the 00:00–00:05
Paris window; my baseline run cleared it (executed at 00:36, see Exp 2 measurement).

---

## Experiments

### Exp 1 — ISOLATION (proves source is not defective)
```
$ vendor/bin/phpunit --filter "OssCustomerScreenFilterTest|OssPublicNoPiiTest"
OK (9 tests, 33 assertions)
```
GREEN in isolation → the service/source is correct; the failure is environmental/ordering, not a source defect.

### Exp 2 — FULL SUITE *with* the 3 heal test files present (baseline) + instrumentation
Ran the full suite (HEAD `6b56e0b5d`, heal files present) with a temporary diagnostic
in `OssPublicNoPiiTest`. **Result: EXIT=0, 3046 tests, 0 failures** (only the 1 known
pre-existing risky `TpeSimulationDepthSentinelTest`). Diagnostic captured at OSS-time:
```
[FORENSICS-OSS] hasTestNow=false now=2026-06-08T00:36:24+02:00 appTz=Europe/Paris
  todayStart=2026-06-08T00:00:00 todayEnd=2026-06-08T23:59:59 staleFloor=2026-06-07T16:36:24
  order_datetime=2026-06-08 00:31:24 order_branch=1 test_branch=1 order_status=7
[FORENSICS-OSS] service listForBranch ids=[1] orderId=1 present=true
```
Two decisive facts:
1. **`hasTestNow=false`** at OSS-time → the clock is **clean**; no leaked `setTestNow`. The clock-polluter hypothesis is dead.
2. The run was at **00:36** — `now()-5min = 00:31` still fell inside today → passed *by 31 minutes of margin*. This is the midnight-boundary signature.

**Experiment-2 (move-my-tests-aside) is MOOT and was not run as a second 8-min cycle:**
the full suite is already GREEN *with* the heal files present, and the mechanism
(wall-clock vs today-window) touches neither Carbon nor OSS — the 3 heal files
(`DiscountTicketTvaNettingTest`, `SetBranchLegalCommandTest`, `KioskAutoLoginIpSpoofTest`)
do not call `setTestNow`/`travel` (confirmed by Exp 3 grep) and do not touch the OSS feed.
The heal files remain present and intact (verified). Note: the "4-fail" claim lives in a
**docs-only** commit `9df1a67b8` stacked on identical test code — same code, different
result = the non-determinism IS the proof.

### Exp 3 — POLLUTER ID (clock-pollution hypothesis disproven)
`grep -rln "setTestNow|travelTo|travel("` → 29 test files. For each:
- Every file that **overrides `tearDown`** calls **`parent::tearDown()`** (verified) → Carbon is reset.
- Every file that does **NOT** override `tearDown` inherits
  `Illuminate\Foundation\Testing\TestCase::tearDown()`, which calls `Carbon::setTestNow()`
  (reset) after every test. (Base `Tests\TestCase` does not override tearDown.)
- The Fiscal suspects (`XReportTest`, `FiscalArchiveTest`, `NF525ComplianceE2ETest`, …)
  freeze at **09:00–14:00** and reset to `null` at the end of each method — nowhere near
  midnight, and reset regardless.
**Conclusion: no test leaves a dirty clock to OSS-time → no polluter exists.**

### Exp 3bis — DETERMINISTIC MIDNIGHT REPRODUCTION (root-cause proof)
Froze both OSS test `setUp` to **00:02** (`Carbon::setTestNow(Carbon::today()->addMinutes(2))`)
and ran the filtered pair:
```
$ vendor/bin/phpunit --filter "OssCustomerScreenFilterTest|OssPublicNoPiiTest"
There were 4 failures:
1) OssCustomerScreenFilterTest::test_takeaway_with_queue_number_still_appears  (line 88)
2) OssCustomerScreenFilterTest::test_kiosk_sur_place_still_appears            (line 112)
3) OssCustomerScreenFilterTest::test_delivered_transition_removes_from_oss    (line 164, "must be on the wall before delivery")
4) OssPublicNoPiiTest::test_public_oss_feed_exposes_no_customer_pii           (line 141, '{"data":[]}' contains "ORD-1406-VT")
Tests: 9, Assertions: 19, Failures: 4.
```
**Exactly the 4 failures, identical signatures to the brief** (incl. the `{"data":[]}`
empty-feed and the two named tests). The 5 exclusion-asserting tests (DELIVERY/POS/
DINING/PENDING/ACCEPT must be ABSENT) passed regardless — they don't depend on the window.

### Exp 4 — FISCAL CHAIN (failures are harness-only, no fiscal break)
```
$ APP_ENV=e2e php artisan fiscal:verify-chain --all
  + branch=1 CHAIN OK
SWEEP COMPLETE — CHAIN OK on every active branch (1 total)
```

---

## The fix (applied)

**Files (test-only, NON-frozen, additive):**
- `tests/Feature/OSS/OssCustomerScreenFilterTest.php` — `setUp` +1 line
- `tests/Feature/OrderStatusScreen/OssPublicNoPiiTest.php` — `setUp` +1 line

Anchor the clock to local noon **before** any order is seeded:
```php
\Illuminate\Support\Carbon::setTestNow(\Illuminate\Support\Carbon::today(config('app.timezone'))->addHours(12));
```
Now `now()->subMinutes(5)` = **11:55 today**, always inside `[today 00:00, today 23:59]`
and well clear of the 8h stale floor — **midnight-stable by construction**. Base
`tearDown` resets Carbon, so **zero spillover** to sibling tests (neither class overrides
tearDown).

**Why this is safe / not a polluter itself:** the freeze is set/reset within each test's
own lifecycle (set in `setUp`, auto-reset by the framework's `tearDown`). It does not edit
product code, does not touch any frozen file, and changes no assertion semantics — the
exclusion tests still assert absence, the inclusion tests still assert presence.

### Post-fix verification
```
$ vendor/bin/phpunit --filter "OssCustomerScreenFilterTest|OssPublicNoPiiTest"   → OK (9 tests, 33 assertions)
$ vendor/bin/phpunit --filter "Oss"                                              → OK (97 tests, 334 assertions)
$ vendor/bin/phpunit  (FULL)                                                      → EXIT=0, 3046 tests, 13699 assertions, 0 failures
                                                                                    (only pre-existing risky TpeSimulationDepthSentinelTest, documented)
$ APP_ENV=e2e php artisan fiscal:verify-chain --all                               → CHAIN OK
```

---

## Breadth — no sibling latent midnight-flakes (suite is now midnight-stable)

The bug class is: *seed `order_datetime = now()->subMinutes(N)` + assert PRESENCE on a
today-scoped feed + don't freeze the clock.* Verified the whole suite has no other instance:

```
# all PHP feature tests seeding order_datetime with the midnight-risk pattern now()->sub*:
tests/Feature/OrderStatusScreen/OssPublicNoPiiTest.php          freeze=1 (FIXED) todayFeed=2
tests/Feature/OSS/OssCustomerScreenFilterTest.php               freeze=1 (FIXED) todayFeed=20
tests/Feature/CleanupVsConfirmRaceTest.php                      freeze=0 todayFeed=0  → cleanup-staleness, not a today-feed presence assert
tests/Feature/Sentinels/CleanupVsConfirmRaceSentinelTest.php    freeze=0 todayFeed=0  → idem
```
- The two today-scoped feed services are **OSS** and **KDS**. Every KDS today-feed test
  either **freezes the clock** (in the 29-file freeze-list) or **seeds `order_datetime => now()`**
  exactly (e.g. `KdsSnapshotImmutableTest`, `KdsOrderItemsResourceAllergenExposureTest`).
  An exact-`now()` seed is immune: the instant is by definition within `[today, endOfDay]`
  even at 00:00:01. Only `now()->subMinutes(N)` can cross *backward* over midnight.
- The two `freeze=0` `now()->sub` files (`CleanupVsConfirmRace*`) use the past timestamp to
  prove a STALE order is **cleaned up** (absence, intended), and query no today-scoped feed.

**Conclusion: exposure was OSS-only — exactly why precisely 4 tests (not more) failed at
midnight on 06-07.** With both OSS files anchored to noon, the suite is midnight-stable.

## Observation (product — flagged, NOT acted on)

The underlying production behavior — a 23:57 PREPARING order disappearing from the
today-scoped wall at local midnight — is a real but **narrow** edge case and is arguably
**correct by design**: yesterday's orders should not show on today's wall. The
today-window is deliberate, heavily-commented design (TZ-aware Paris bounds, sister of the
KDS service). **The OSS feed was NOT touched.** If the owner wants late-night orders to
persist across midnight on the wall, that is a separate owner-gated product decision, not a
test-harness fix.

## Frozen / NF525 status
- Files changed: **2, both under `tests/`** — never frozen. `git diff --name-only` (excl. report) = the 2 OSS tests only.
- Product code: **untouched.** NF525 chain: **CHAIN OK.**
