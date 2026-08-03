# Agent 5 — QA / Testing Maturity Audit

**Scope** : Coverage, test quality, E2E maturity, visual gate effectiveness, frozen-zones discipline, regression suites, CI integration, test data discipline, sentinel design, performance/stress.
**Date** : 2026-05-16
**Method** : Read-only review of config, sample tests, CI workflows, recent run reports, PHPUnit result cache, scripts/hooks.

---

## Verdict

- **Test maturity score : 46 / 100**
- **Gate effectiveness score : 27 / 100**

The codebase has impressive test *volume* (443 PHPUnit files + ~220 Vitest specs + 127 Playwright specs + 47 named sentinels + ~4 575 captured artifacts). Architecture is more mature than typical Laravel SaaS at this stage — there is a deliberate sentinel discipline, an outbox-pipeline real-Pusher harness, HMAC chain attacker tests, multi-branch isolation suites, and a visual-capture infrastructure. **But the gates leak in load-bearing places** : E2E is opt-in by PR label, frozen-zones aren't enforced by any hook, the stress suite self-documents that it doesn't actually stress, dozens of "sentinels" are source-string regex (not behavioural), and 23 `assertTrue(true)` exist on fiscal / payment / state-machine paths. The 2026-05-16 Wave Z audit found 17 P0 + 24 P1 on a branch where PHPUnit (1880 passed) and filtered Playwright (16/16) were reported "green" — the smoking-gun disconnect between green status and production correctness.

---

## Inventory (verified)

| Surface | Files | Notes |
|---|---|---|
| `tests/Feature/*Test.php` | 425 | Including 47 sentinels, 38 Fiscal, 4 Isolation, 6 Payment |
| `tests/Unit/*Test.php` | 17 | Very thin for a codebase this size (≈ 1 unit per 25 feature) |
| `tests/load/*Test.php` | 1 | `RushMidiSimulationTest.php` — see P0-2 |
| `tests/e2e/*.spec.js` | 127 | Playwright (root config) |
| `tests/js/*.spec.js` | 220 | Vitest (happy-dom) |
| `tests/mobile-e2e/` | dedicated config | iPhone-13 viewport, baseURL :8081, separate from root config |
| Sentinels | 47 (Feature) + 3 (JS) + 2 (Playwright) | Mixed quality — see P1-2 |
| Visual captures stored | 4 575 files (`tests/e2e/__screenshots__/`) | Mostly raw artifacts, no comparison baseline lock |
| `markTestSkipped` / `markTestIncomplete` call-sites | 36 (15-20 real skips after deduping the helper) | See P1-3 |
| `assertTrue(true)` vacuous assertions | 23 | See P0-3 |
| Mockery / shouldReceive call-sites | 0 in `tests/`, mocking is **almost never used** | Positive : prefers real DB/RefreshDatabase |
| `Bus::fake / Event::fake / Queue::fake / Notification::fake` files | 75 | Common, mostly justified |

PHPUnit 9.6.29. The most recent CI-style run captured in repo logs (`reports/audit/ultra-goal-2026-05-13/phpunit-after-wave4.log:tail`) : **1880 passed, 3 failed, 2 incomplete, 29 skipped, 232 s**. The 3 failures are a baseline PHP 8.3 vendor issue (Doctrine Instantiator typed-const) — known and not a regression.

The on-disk `.phpunit.result.cache` (last modified 2026-05-16 18:00) lists 1232 defects (1=SKIPPED 42, 2=INCOMPLETE 2, 3=FAILURE 291, 4=ERROR 895, 5=RISKY 2). PHPUnit 9 only removes entries on subsequent pass, so most of these are stale historic, not live regressions, but the cache being inconsistent with the headline "1880 passed" means the cache is partially stale — a minor hygiene issue, not a blocker.

---

## P0 findings

### P0-1 — E2E suite does NOT block by default ; ship gate is missing
**Severity** : P0 — single biggest gate gap.
**Files** : `.github/workflows/playwright.yml:36-41`.
```
if:
  github.event_name == 'workflow_dispatch'
  || github.event_name == 'push'
  || (github.event_name == 'pull_request'
      && contains(github.event.pull_request.labels.*.name, 'e2e-required'))
```
PRs without the `e2e-required` label do **not** trigger Playwright. The workflow is opt-in, justified historically by "5+ runs consécutifs en échec total" — i.e. the flakiness was managed by silencing the gate rather than fixing the cause. The same workflow file admits the suite uses `continue-on-error: true` on the Playwright step (line 171) — failures only manifest in a separate "Fail the job" step after artefact upload, with no auto-required check. Result : a PR can ship green-on-paper while the actual surfaces are broken. This is precisely what happened with the 2026-05-16 ultra-review : 17 P0 + 24 P1 surfaced on a branch the team had been calling "all green."

**Impact** : tests-passing ≠ system works has a structural explanation, not a perception issue. Combine with the frozen-zones gap (P0-4) and the stress theatre (P0-2), and the gate-effectiveness score collapses.

### P0-2 — Stress / Load suite is structurally theatrical
**Severity** : P0 NF525 + UX correctness under rush.
**Files** :
- `tests/load/RushMidiSimulationTest.php:48-58` (self-documented disclaimer):
  > « Tests stress en sqlite-memory ne sont PAS du vrai concurrent (RefreshDatabase + SQLite serialise tout, lockForUpdate no-op). »
- `tests/load/RushMidiSimulationTest.php:76-79` — `STRESS_N = 10`, `STRESS_MIX_N = 6`, `STRESS_BRANCHES = 3`, `STRESS_PER_BRANCH = 4`.
- `tests/load/RushMidiSimulationTest.php:282` and `:332` — `markTestIncomplete(...)` on S7.2 (kiosk-card monotonic) and S7.3 (POS+Kiosk shared monotonic). `markTestIncomplete` does **not** fail the suite ; it counts as non-failure in CI.
- `tests/e2e/concurrent-orders.spec.js:28-65` — the Playwright "concurrent" companion opens 5 browser contexts, waits 3 s, and asserts only that no `pageerror` was emitted. It is "SPA survives 5 tabs," not "5 orders racing against fiscal_sequence + UNIQUE constraints."

The artisan command `foodking:e2e:stress` is the only real concurrent test — but it's **owner-driven** and **not in CI**. The CI Stress suite (`phpunit.xml:20-22`) therefore reports green without ever exercising the contention path that NF525 monotonic + queue_number uniqueness must survive. Production claim "stress rush 50×50 verified" relies on a 2026-05-10 one-shot adversarial audit (`reports/test-e2e/rush-hour-50x50-2026-05-10/`), not on CI regression.

**Impact** : Fiscal sequence collision / lost-order under rush is the highest-consequence NF525 failure mode. Today there is no daily-run gate that catches its regression.

### P0-3 — 23 `assertTrue(true)` on fiscal / payment / state-machine paths
**Severity** : P0 (placeholder tests on critical paths).
**Files** (sample) :
- `tests/Unit/Domain/Order/PaymentStateMachineExtendedTest.php:63`
- `tests/Unit/Domain/Order/OrderStateMachineTest.php:120`
- `tests/Unit/Services/Payment/SplitPaymentServiceTest.php:54, 87` (`pas d'exception = succès`)
- `tests/Unit/Services/Fiscal/FiscalChainValidatorTest.php:56, 132` (`no throw`)
- `tests/Unit/Domain/Events/EventContractUnitTest.php:62, 181`
- `tests/Feature/CouponSecurityTest.php:11` — `function test_apply_valid_coupon_recalculates_discount() { $this->assertTrue(true); }` — coupon recompute "verified" by tautology.

These are not isolated typos. They form a recognisable pattern : "test passes if no exception thrown," with no behavioural assertion on the outcome. For state machines and HMAC chain validators, "no throw" is not a useful invariant — the failure modes are wrong-transition-allowed and chain-still-verifies-after-tamper, neither captured by `assertTrue(true)`.

### P0-4 — Frozen-zones enforcement is broken
**Severity** : P0 — CLAUDE.md §7 invariant is documented but not protected.
**Evidence stack** :
- `.cursor/hooks/safety-check.sh:9-12` — `FROZEN_ZONES` array contains only 2 files :
  ```bash
  FROZEN_ZONES=(
    "app/Services/OrderService.php"
    "app/Services/FrontendOrderService.php"
  )
  ```
  CLAUDE.md §7 lists 13+ files including `pos-wizard.js`, `KioskWizardComponent.vue`, `ZReportService.php`, `FiscalSequenceService.php`, `AuditLogService.php`, `BranchScope.php`, `IdempotencyKeyMiddleware.php`, `PricingService.php`, `OrderStateMachine.php`. **The list is wrong** : it omits 11+ frozen files including all fiscal services and the visual frozen zones.
- `.cursor/hooks/safety-check.sh:3-5` self-documents : *"Run manually before every execution phase. Not auto-invoked."* No `.git/hooks/pre-commit` invokes it. `.git/hooks/pre-push` is LFS-only (verified). No GitHub Action calls it.
- `reports/audit/ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md:49` finding **POS-A4** : *"Frozen-zone diff non-LOCK-tracké : `pos-wizard.js` +237 lignes, blade +165 lignes (commits 91a1e1b2c, 5218168ef)."* Drift happened, was not blocked. BRAIN.md notes cumulative +5 000 lines diff.

**Impact** : The frozen-zones doctrine is rhetorical, not enforced. The list is wrong **and** the hook is uninstalled.

---

## P1 findings

### P1-1 — "Sentinels" rely heavily on source-string regex (file_get_contents + match), not behaviour
**Severity** : P1 — false sense of protection.
**Files** :
- `tests/Feature/Sentinels/F001KioskFiscalSequenceInvariantSentinelTest.php:30-58` — reads `FrontendOrderService.php` as text, asserts substrings like `'FiscalSequenceService::class'`, `'fiscal_sequence_no === null'`, `"Log::channel('fiscal')"`. A refactor that preserves the strings but breaks the logic (variable rename, conditional inversion) passes.
- `tests/js/sentinels/PaymentComponentPropMutationSentinelTest.spec.js:12-29` — reads `PaymentComponent.vue` as text, asserts presence of literal substrings `"payment-form:patch"` etc.
- `tests/Feature/Sentinels/ClientTotalWriteForbiddenSentinelTest.php:12-52` — shells out to a bash lint script and asserts its `[OK]` output.
- Counter-example (genuinely behavioural) : `tests/Feature/Fiscal/AuditLogHashChainTest.php` — drops the DB immutability trigger, tampers a payload, reinstalls trigger, asserts `verifyChain()` points at the tampered row. This is the *right* shape. The proportion of sentinels matching this rigour vs the regex-string shape is roughly 1 in 3.

**Impact** : Sentinel naming connotes load-bearing invariant ; the regex form gives anti-rename protection only. They should be relabelled "source pin" / "anti-removal lint," distinct from a true behavioural sentinel like `AuditLogHashChainTest`.

### P1-2 — Behavioural coverage of cash-trail / NF525 is incomplete — proved by 2026-05-16 audit
**Severity** : P1 — coverage gap on the highest-risk surface.
**Evidence** :
- `reports/audit/ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md:43-46` — POS-A1, POS-A2 (cross-validated by Wave F) : `OrderService::posOrderStore` (`app/Services/OrderService.php:563-925`) and `SplitPaymentService::persistTranches` (`app/Services/Payments/SplitPaymentService.php:143-end`) **never call `CashDrawerService::recordMovement`** when paid cash. There is no test today that fails for this. Z-report cash variance is therefore mathematically wrong on every POS direct cash and on every cash tranche of a split — but every PHPUnit fiscal test still passes (because `ZReportCashEnrichmentService` is decorated separately, see F003 sentinel).
- `tests/Feature/Sentinels/F003CashReconciliationSentinelTest.php:65-91` validates **schema columns** — not the wiring from POS direct cash to a CashMovement row. INV-2 ("PAID cash → linked movement in") tests the lifecycle via `CashDrawerService::openSession` + `PaymentService::confirmCounterPayment` only (the kiosk-deferred path). The POS direct cash path is untested.
- `reports/audit/ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md:104-110` — webhook idempotency (`Stripe.php:46-72`, `Senangpay.php:33-46`) is unwrapped from `WebhookEvent::firstOrCreate` and has 0 hits in `app/`. No test fails for this.

**Coverage measurement (no `phpunit --coverage` artefact present in repo)** : there is no recent coverage HTML / clover XML committed. The `phpunit.xml:24-28` declares `<coverage>` but no CI workflow generates a report or gates on threshold.

### P1-3 — Skipped & incomplete tests on NF525-critical paths
**Severity** : P1.
**Sample** :
- `tests/Feature/Cash/CashMovementsDeleteForbiddenTest.php:51` — DELETE trigger not installed for `sqlite` driver → skipped. MySQL behaviour only validated when MySQL CI runs (it does run). But the in-memory PHPUnit run silently skips the trigger validation.
- `tests/Feature/Composer/ProfilePublishMidCartRejectionTest.php:334, 339` — `markTestSkipped('Pending plan task 2.2')`.
- `tests/Feature/Catalog/CategoryRenameSyncTest.php:39` — `markTestSkipped('Production gap: CategoryUpdated event is not emitted automatically — see audit Axe 1.')` → known production bug **kept skipped** rather than failing.
- `tests/Feature/Isolation/MultiBranchIsolationE2ETest.php:322` — S6 (broadcast wiring) skipped : *"routes/channels.php absent — broadcast wiring not auditable here."* Branch-isolation across broadcast channels therefore has no PHPUnit gate.

The 29-skipped-tests count in the headline includes legitimate driver-conditional skips (MySQL-only triggers), but the *content* of what is skipped includes known production gaps left as TODOs rather than xfail with fix-by date.

### P1-4 — Visual gate captures 4 575 artefacts but does not lock baselines
**Severity** : P1.
**Evidence** :
- `grep toHaveScreenshot` in `tests/e2e/` → **40** occurrences (out of 127 specs). The standard Playwright visual-comparison primitive is used in <1/3 of specs.
- `page.screenshot(...)` is used in **518** call-sites — but most produce diagnostic captures (saved under `__screenshots__/<test-id>/01-step-name.png`), not committed baselines used for regression diff.
- `playwright.config.js:67-68` — `screenshot: 'only-on-failure', video: 'off', trace: 'on-first-retry'`. There is no baseline-locked visual-regression project.
- The "visual mandate" in `CLAUDE.md §6` requires Claude to **Read** screenshots and analyse — this is a runtime discipline depending on the agent, not a CI gate. If the agent skips or hallucinates the analysis (per BRAIN.md note "Claude has invented fictional menu items"), nothing in CI catches it.

The captures are excellent for forensic debugging post-fact. They are not a regression gate.

### P1-5 — Lint guards have correct intent but limited scope
**Severity** : P1.
**Files** :
- `tools/lint/pos_pricing_guard.mjs:1-50` — scans `resources/js/components/{admin/pos,admin/kitchenDisplaySystem,frontend/kiosk}/**/*.vue` for `total = subtotal + ...`-style arithmetic. Wired in `.github/workflows/vitest.yml:29` so it blocks on PR.
- `tools/lint/pos_orderstatus_guard.mjs:1-50` — scans same Vue surfaces for magic integer literals (1,4,7,8,10,13,16,19,22) on `order_status` context.
- `scripts/check-invariants.sh:1-246` — 6-grep set on PHP services (PricingService, OrderService, etc.), wired into `.github/workflows/phpunit.yml:30-31`.

**Scope gap** : these guards don't cover (a) `public/js/pos-wizard.js` (the Vanilla JS frozen wizard — 296 KB), (b) backend `app/Services/Pricing/*`, (c) the mobile prototype `mobile/screens-*.jsx`. They are necessary, not sufficient.

### P1-6 — CI integration is uneven across suites
**Severity** : P1.
**Map** :

| Suite | Triggered on PR? | Blocking? | DB | Notes |
|---|---|---|---|---|
| `phpunit.yml` | Yes (PR + push main/develop) | Yes | MySQL 8 + Redis | Healthy. Migration drift check runs first. |
| `vitest.yml` | Yes (PR + push main/develop) | Yes | n/a | Healthy. `pos:lint:status` + `pos:lint:pricing` block first. |
| `playwright.yml` | **Opt-in by `e2e-required` label** | No (`continue-on-error: true`) | MySQL 8 + Redis | **Broken gate (P0-1)** |
| `legacy-guards.yml` | Path-filtered (only frontend / route changes) | Yes | n/a | Limited scope by design. |
| `ci-sync-rupture-harness.yml` | Path-filtered (only outbox / events) | Yes | MySQL 8 + Soketi (real Pusher) | **Architecturally excellent** : the only place a real broadcast protocol is exercised in CI. The narrow path-filter means most PRs skip it ; that's deliberate. |
| Stress / Load suite | Yes (in default `Load` testsuite) | Yes | sqlite-memory | Structurally theatrical (P0-2). |

The mature pieces (real-Pusher harness, migration drift check, MySQL-only contract tests) coexist with broken pieces (opt-in E2E, theatrical stress). The architecture is conscious — it has not converged.

---

## P2 findings (briefer)

- **P2-1 — Unit-tests are anaemic** : 17 Unit/*Test.php vs 425 Feature/*Test.php. Unit-vs-feature ratio of 1:25 indicates near-zero pure-function isolation. Feature tests are heavy (PHPUnit run ~232 s for 1 880 tests = 123 ms / test wall clock, dominated by RefreshDatabase). Pricing / state-machine logic should have a dense unit layer.
- **P2-2 — Test data discipline mixed** : real seeders exist, `MysqlOnly` trait handles MySQL-conditional skips cleanly, but the 2026-05-14 "menu V3" cycle documents Claude inventing fictional menu items before. There is no automated check that test factories match `database/seeders/menu` snapshot — the recent recovery script `mobile/data/menu.js` was hand-realigned to DB rather than asserted via test.
- **P2-3 — Mobile-e2e is parallel infrastructure** : `tests/mobile-e2e/playwright.config.js` is a separate Playwright config with its own server (PHP `-S` on :8081), its own globalSetup omitted, and a custom `testMatch` glob (`tests/e2e/test-e2e-mobile-design-full-wave-*.spec.js`). It's not wired into `.github/workflows/`. The mobile prototype has no CI gate.
- **P2-4 — Per-cycle audit reports duplicate effort across rounds** : `reports/test-e2e/{rush-100, rush-pos, rush-sync, wave-z}-*` show round-1/2/3/4/5 directories with rebuilt screenshots and round-vs-round set-equality computations. This is the "convergence" gate logic (good), but the harness for it lives partly in markdown + ad-hoc node scripts, not as a reusable PHPUnit / Playwright project. Onboarding cost for the next contributor is high.

---

## What's missing in coverage (concrete file:line)

1. **POS direct-cash → CashMovement wiring** — `app/Services/OrderService.php:563-925` is unprotected. No PHPUnit test asserts that after a POS cash sale, a row exists in `cash_movements` with the order's `branch_id` and direction `in`. Confirmed gap by `reports/audit/ultra-review-2026-05-16/ULTRA_REVIEW_VERDICT.md:43-46` (POS-A1 + Wave F1).
2. **Split-payment cash tranche → CashMovement** — `app/Services/Payments/SplitPaymentService.php:143-end` same gap (POS-A2 + Wave F1).
3. **Webhook idempotency via `WebhookEvent::firstOrCreate`** — `app/Services/PaymentGateways/Stripe.php:46-72` is not exercised by any test that asserts the dedup table is hit. The model + table exist (P1-SYNC-01 in verdict) but have 0 usage hits.
4. **KDS V2 default-flip behaviour** — `KitchenDisplaySystemComponent.vue:1064-1080` `useV2Layout` defaults to `false` ; the V2 fix-set has no PHPUnit / Vitest assertion that the default is `true` on the production-target branch. Verdict KDS-W3-002.
5. **`fiscal_alloc_error_at` retry cron behaviour** — `app/Services/Fiscal/FiscalSequenceService.php` flags `fiscal_alloc_error_at` on lock-fail (CLAUDE.md §8) ; there is `FiscalAllocOrphanRetryTest.php` but no test that combines (a) lock-fail injection (b) retry-cron tick (c) post-retry alloc verified atomically against the chain.
6. **Pos-wizard.js Vanilla JS** — 296 KB of hand-written JS in `public/js/pos-wizard.js` has **no Vitest / Jest unit coverage** and is excluded by `pos_pricing_guard.mjs` scope. The verdict POS-A6 finding ("PosComponent sends client-computed totals — signoff date 2026-05-10 EXPIRÉ") is in the Vue layer, not the Vanilla JS layer, but the latter is the surface owners marked "perfect design protégé" and is now a black box.

---

## Top 3 recommendations

1. **Make E2E a required check, not opt-in** — remove the `e2e-required` label gate in `.github/workflows/playwright.yml:36-41`, drop `continue-on-error: true` (line 171), and stabilise the suite by reducing it to a sub-set of `test:e2e:smoke` (already defined in `package.json` : `01-auth-refresh + 02-pos-cash + 03-kiosk-wizard + 04-kds-status + stock-rupture-sync`). A 5-spec required gate is more valuable than a 127-spec opt-in. Pair with a separate `e2e-full` label for the comprehensive run. This change alone moves the gate-effectiveness score from 27 to ~55.

2. **Replace `markTestIncomplete` in `RushMidiSimulationTest` + add real concurrent CI step** — port `php artisan foodking:e2e:stress` to a CI matrix step on `phpunit.yml` using MySQL service (the harness is already there for `ci-sync-rupture-harness.yml`). Run 50 orders × 2 branches × concurrency=10 against MySQL with real `lockForUpdate`. Until this exists, the NF525 monotonic invariant under contention has no daily gate. Same logic for cash-trail : add a Feature test that exercises `OrderService::posOrderStore` end-to-end with cash and asserts a matching `cash_movements` row. This closes both P0-2 and the most acute coverage gap in P1-2.

3. **Fix frozen-zones enforcement (list + hook + ratchet)** — (a) rewrite `.cursor/hooks/safety-check.sh:9-12` to mirror CLAUDE.md §7 (13+ files including `public/js/pos-wizard.js`, `KioskWizardComponent.vue`, fiscal services, `BranchScope.php`, `OrderStateMachine.php`); (b) install a server-side check via `.github/workflows/legacy-guards.yml` that fails when a PR touches a frozen file without a `LOCK_*.md` in the same commit ; (c) add a "diff ratchet" job that fails if cumulative diff on a frozen file exceeds N lines without an accepted LOCK doc. This stops the +5 000-line drift problem at source.

---

## Closing assessment

The team has reached the level where "more tests" is no longer the answer — the test code quality and gate design are the bottleneck. The discipline that exists (sentinels, isolation suites, real-Pusher harness, multi-round adversarial audits) is above average. The discipline that's missing (E2E required gate, frozen-zone enforcement, real concurrent stress, behavioural-not-string sentinels) is exactly what separates "tests pass" from "system works." Today, the test suite functions as a sophisticated diagnostic-and-debugging instrument, not as a release gate.
