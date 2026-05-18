# Couche 0 V1.0.X Backlog Quick Wins — Consolidation STATUS

**Date**: 2026-05-19
**Branch**: heal/cms-pr1-quickwins-2026-05-18
**Master sub-agent**: Couche 0 backlog (parallel with 4 other wave D masters; disjoint scope)
**Mode**: TDD-first scope-minimal heals from Foundation 9-system audit reports
**Wall-clock**: ~40 min (RECON 8 min, 5 heals + sentinel reconciliation 30 min, STATUS 2 min)

---

## Verdict

**5 quick wins SHIPPED + 1 sentinel reconciliation.** 0 regressions in the 286-test Feature/Sentinels suite (832 assertions, 2 skipped harness env). 0 frozen-zone touches. 0 dirty-file touches. Each heal is scope-minimal, reversible, and pinned by a fresh sentinel.

| # | ID            | Heal                                                          | LOC | Commit       | Sentinel                                         |
|---|---------------|---------------------------------------------------------------|-----|--------------|--------------------------------------------------|
| 1 | F-2 R1 (P1)   | ForgotPassword `resetPassword` min:6 → min:12                 | 2   | `f210ab7e3`  | PasswordResetMinLengthSentinelTest               |
| 2 | F-9 RED-RED1  | OrderService.store ActionLog.details — drop `Auteur:` PII     | 11  | `269617720`  | ActionLogPiiRedactionSentinelTest                |
| 3 | F-3 P1        | Stripe webhook replay tolerance 300s                          | 1   | `5695fe59f`  | StripeWebhookReplayToleranceSentinelTest         |
| 4 | F-3 P1        | DispatchDomainEventsJob PayloadMismatchException fail-once    | 9   | `5452e556d`  | PayloadMismatchFailOnceSentinelTest              |
| 5 | F-7 D.1       | Sentinel lock-in: no empty-string keys in fr/en/ar.json       | 0   | `521bc7fcc`  | I18nNoEmptyKeySentinelTest                       |
| — | follow-up 1   | Reconcile OutboxPipelineHealthSentinelTest with H4 + refactor | —   | `b14d0f977`  | (test updates only)                              |
| — | follow-up 2   | Reconcile 2 Outbox tests with H4 fail-once                    | —   | `b1a7dc39d`  | (test updates only)                              |
| — | follow-up 3   | Reconcile EventContractTest with H4 fail-once                 | —   | `f3dbf903d`  | (test updates only)                              |

**Net delta**: +23 LOC production + 6 sentinel tests + 1 sentinel update. Each heal carries a behavioural or source-pin sentinel making it regression-proof at PHPUnit time.

---

## Heal-by-heal detail

### H1 — F-2 R1 password reset min:6 → min:12 (commit `f210ab7e3`)

- **Audit source**: `reports/audit/foundation-2026-05-18/round-1/F-2-AUTH/STATUS.md §5.1 R1 (P1)`
- **Why**: a user could reset their password to a 6-character string while staff create/update already enforced min:12 and bcrypt rounds=12 (Wave 5G). Inconsistency silently undercut the rounds-12 hardening.
- **File touched**: `app/Http/Controllers/Auth/ForgotPasswordController.php:123-124`
- **Scope**: 2 LOC + comment block. NOT min:6 for LoginController/KioskMachineLoginController — those validate against existing hashes and have a different security posture.
- **Sentinel**: source-pin regex on the `resetPassword` block; bounds enforced.
- **Regression**: 20 Auth/Login tests GREEN, 286/286 Sentinels GREEN.

### H2 — F-9 RED-RED1 PII redaction in ActionLog.details (commit `269617720`)

- **Audit source**: `reports/audit/foundation-2026-05-18/round-1/F-9-OBS/STATUS.md §HEAL P1 RED-RED1`
- **Why**: `Auth::user()->name` was plaintext-written into `ActionLog.details` (6y retention, audit-adjacent). RGPD article 5(1)(c) minimisation. user_id is on the same row (line 537) so the `Auteur:` segment is redundant.
- **File touched**: `app/Services/OrderService.php:540-547`
- **Scope**: drop the `Auteur:` segment in the sprintf — 1 site only. Report cites 10 similar sites (OrderService:1823, :2008, and 8 in other services) deliberately deferred to V1.0.2 to keep heal reversible.
- **Sentinel**: source-pin regex; asserts neither `Auth::user()->name` nor `Auteur:` survive in the `'Nouvelle commande Web/App'` ActionLog block.
- **Regression**: OrderServiceSecurityTest 5/5 GREEN, 286/286 Sentinels GREEN.
- **Historical rows**: forward-fix only. Owner gate for one-shot redaction (>30j rows) remains open in F-9 STATUS.md §Questions Owner #1.

### H3 — F-3 P1 Stripe webhook replay tolerance 300s (commit `5695fe59f`)

- **Audit source**: `reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md §P1 "Stripe replay tolerance"`
- **Why**: `Webhook::constructEvent` was called WITHOUT the 4th positional `tolerance` argument, so signed payloads older than the WebhookEvent retention horizon (180d) would still verify. 300s is Stripe's canonical production recommendation.
- **File touched**: `app/Http/PaymentGateways/Gateways/Stripe.php:214`
- **Scope**: 1 LOC + comment block.
- **Sentinel**: source-pin regex on the 4th positional argument; bounds [60s, 600s] enforced to catch both removal and accidental over-tightening.
- **Regression**: StripeWebhookIdempotencyTest 6/6 GREEN, 286/286 Sentinels GREEN.

### H4 — F-3 P1 PayloadMismatchException fail-once (commit `5452e556d` + `b14d0f977`)

- **Audit source**: `reports/audit/foundation-2026-05-18/round-1/F-3-SYNC/STATUS.md §P1 "PayloadMismatchException retry loop"`
- **Why**: contract violations are NOT retry-recoverable. The old behaviour rethrew, triggering Laravel's 6-attempt $backoff curve [1,5,15,60,300]s. 1000 bad payloads = 6000 useless 'high' queue messages.
- **File touched**: `app/Jobs/DispatchDomainEventsJob.php:155-180`
- **Scope**: +9 LOC in catch block — `$this->fail($e)` + early return for `PayloadMismatchException`. Generic Throwable still rethrows for transient retry-recoverable failures.
- **Sentinel**: behavioural — drives `handle()` with malformed real payload and real EventContract::assertEnvelopeValid. Two tests pin (a) PayloadMismatch path does NOT rethrow and (b) generic Throwable path DOES rethrow.
- **Follow-up commit `b14d0f977`**: reconciled the pre-existing `OutboxPipelineHealthSentinelTest::test_contract_violation_preserves_pager_grade_prefix` which had been pinning the OLD throw behaviour. Flipped the catch invariant; ALL 3 original contract assertions (prefix preserved, broadcaster bypassed, phase 3b reset) are unchanged — only the implementation detail of "must throw vs must fail()" was updated.
- **Regression**: 4 DispatchDomainEvents tests GREEN, 7 Outbox tests GREEN, 286/286 Sentinels GREEN.

### H5 — F-7 D.1 no-empty-key sentinel (commit `521bc7fcc`)

- **Audit source**: `reports/audit/foundation-2026-05-18/round-1/F-7-I18N/STATUS.md §3 D.1`
- **Heal context**: the 3 actual empty-key fixes in `fr.json` were already shipped in commit `86656f1d1` (`chore(i18n-cleanup): empty-trailing-dot-keys-phase3`). This is a **lock-in sentinel** — no production code change.
- **File added**: `tests/Feature/Sentinels/I18nNoEmptyKeySentinelTest.php`
- **Scope**: 3 tests (fr/en/ar) running a JSON-decoded recursive walk; records parent-path of any `""` entry for diagnostic clarity. bn/de excluded — out-of-V1 per F-7 §D.5.
- **Verified**: regression-injection test (temporarily added `"":"x"` to fr.json) confirmed sentinel correctly fails with structured offender list; restored state bit-identical; sentinel back to GREEN.

---

## Discipline trace

### TDD-first rule (mandate #1) — followed strictly

| Heal | RED first?  | Pass count before / after edit          |
|------|-------------|-----------------------------------------|
| H1   | YES         | 1 fail, 2 assertions → 1 pass, 3 assertions |
| H2   | YES         | 1 fail, 3 assertions → 1 pass, 4 assertions |
| H3   | YES         | 1 fail (constructEvent w/o 4th arg) → 1 pass, 6 assertions |
| H4   | YES         | Test 1 fail (rethrow), Test 2 pass (control) → 2 pass, 7 assertions |
| H5   | N/A (lock-in) | Already-fixed state pinned; regression-injection confirmed catch |

### Scope-minimal rule (mandate #2) — one fix at a time

Each heal touches exactly one production file (H5 touches zero). Each commit is atomic and reversible by `git revert`. No bundling.

### Read-cited file:line (mandate #3) — every commit message + sentinel docblock cites the audit STATUS.md path AND a specific file:line.

### KEEP what works (mandate #4)

- Pre-existing min:6 in `LoginController.php:49` and `KioskMachineLoginController.php:32`: **kept** — login validates against existing hash, different security posture from password creation.
- Pre-existing 10 similar PII sites in OrderService + other services: **kept** — deferred to V1.0.2 with explicit cite in H2 sentinel.
- Pre-existing `Webhook::constructEvent` for non-Stripe gateways: **kept** — out of scope.
- Pre-existing OutboxPipelineHealthSentinelTest test 4 contract assertions (prefix, broadcaster bypass, phase 3b reset): **kept** — only the "must rethrow" implementation pin was updated to "must fail-once".

### No frozen-zone touch (mandate #5) — verified

None of the heals touched the frozen-zone list (FiscalSequenceService / ZReportService / AuditLogService / BranchScope / IdempotencyKeyMiddleware / PricingService / OrderStateMachine / KioskWizardComponent / pos-wizard.js).

### No dirty-file touch (mandate #5b) — verified at heal-time

`git status --porcelain` was clean for every file I edited at the moment I edited it (re-verified after parallel masters committed `90c9c0ee5` between H4 and H5 — they touch the POS loyalty path, disjoint from my files).

### Commit per fix (mandate #6) — 5 atomic heal commits + 1 follow-up reconciliation. Each scope-minimal.

### STOP rule (mandate #7) — observed in spirit

When the suite-wide sentinel run surfaced 2 Mockery errors + 1 conflicting pre-existing sentinel (PR2 #6 of the cycle), I paused, consulted the advisor, and applied a clean reconciliation rather than papering over with `@runInSeparateProcess` annotations. Result: clean sentinel suite, both invariants pinned, no test-side hacks.

---

## Candidates NOT executed (deliberate)

| Candidate                                       | Reason                                                                                                            |
|-------------------------------------------------|-------------------------------------------------------------------------------------------------------------------|
| F-9 /health/ready 503 payload IP-gate           | Risk of breaking unconfigured LB/k8s probes that aren't on HEALTH_IPS_ALLOWED. Sanitize-payload path safer but advisor flagged "what's the production LB contract?" — punted to V1.0.2 owner gate. |
| F-9 320 Log::info($exception) 1-file POC        | Advisor: POC value depends on bucket — Touchable services were the right pick but each carries domain context worth a dedicated wave. V1.0.2 batch. |
| F-7 audit_locale_keys.mjs schedule via Kernel   | Advisor correction: Console/Kernel is runtime, not CI gate. The correct shape is a sentinel PHPUnit test shelling out to the node tool with a threshold assertion. Punted to V1.0.2 dedicated cycle. |
| F-9 outboxRetryFailed/Drain audit emit          | Both files were initially DIRTY (`OutboxRetryFailedCommand.php`, `OutboxWebhookRetryFailedCommand.php` shown in `git status`); skipped per mandate #5b. |
| F-X ReceiptDataService wiring verify            | Out of scope for THIS sub-agent (Wave D parallel master split). |

---

## Final regression evidence

```
$ vendor/bin/phpunit --testsuite=Feature --filter='Sentinel'
  Tests: 286, Assertions: 832, Skipped: 2.   [0 failures, 0 errors]

$ vendor/bin/phpunit --testsuite=Feature --filter='Auth\\|Login\\|Forgot'
  OK (20 tests, 76 assertions)

$ vendor/bin/phpunit tests/Feature/Webhooks/StripeWebhookIdempotencyTest.php
  OK (6 tests, 25 assertions)

$ vendor/bin/phpunit tests/Feature/Outbox/OutboxDeliveryTest.php tests/Feature/OutboxTest.php
  OK (7 tests, 20 assertions)

$ vendor/bin/phpunit tests/Feature/Queue/DispatchDomainEventsFailedCallbackTest.php \
                    tests/Feature/Observability/DispatchDomainEventsObservabilityIntegrationTest.php
  OK (4 tests, 27 assertions)

$ vendor/bin/phpunit tests/Unit/Services/OrderServiceSecurityTest.php
  OK (5 tests, 12 assertions)

$ vendor/bin/phpunit tests/Feature/EventContractTest.php
  OK (9 tests, 30 assertions)

$ vendor/bin/phpunit --testsuite=Feature --filter='Sentinel|Outbox|Webhook|Auth|DispatchDomain|EventContract'
  Tests: 522, Assertions: 1692, Skipped: 2.
  Failures: 3 (all in tests/Feature/Composer/ComposerAuthzMinimalTest — PRE-EXISTING
  test debt unrelated to this batch; verified by grep that the failing tests do NOT
  depend on any file my heals touched).
```

### H4 cross-cutting reconciliation — exhaustive grep verification

```
$ grep -rn "PayloadMismatchException" tests/ | grep "expectException\|catch.*PayloadMismatch"
# 0 remaining expect-throw assertions over DispatchDomainEventsJob::handle()
```

3 test-side updates (follow-up 1/2/3 commits above) reconciled the OLD
"must throw" implementation pin with the new "must fail()" behaviour.
All DB invariants (claim released, contract_violation prefix, attempts=1,
broadcaster bypassed) are preserved across every test.

---

## Owner notes (non-blocking)

1. **F-9 historical PII rows in `action_logs`**: this heal is forward-fix only. Existing rows still contain customer names. A one-shot redaction script (anonymise rows >30j) is documented as an open question in F-9 STATUS.md §Questions Owner #1.

2. **Stripe replay tolerance value**: 300s chosen per Stripe's production recommendation. If staging environment clocks drift heavily, lower bound 60s gives room without weakening replay protection. Sentinel enforces [60, 600] bounds.

3. **PayloadMismatchException short-circuit telemetry**: `failed_jobs` now receives 1 row per bad payload instead of 6 messages saturating the high lane. Operators should expect a different shape in failed-jobs reports — counts will drop, individual row visibility improves. The `failed()` callback still emits the pager-grade Log::error so monitoring grep patterns are unchanged.

4. **i18n empty-key sentinel scope**: limited to fr/en/ar.json (active V1 locales). bn/de.json are out-of-V1; if owner activates them in V1.0.x, this sentinel should be extended to gate those too.

---

## Wall-clock & word budget

- Time spent: ~40 min (8 min RECON + 30 min heals/reconciliation + 2 min STATUS)
- Heals shipped: 5 production + 1 reconciliation = 6 commits
- LOC delta: +23 production + 6 sentinel test files + 1 sentinel update
- Sentinel suite size: 286 tests (was 282; +4 new sentinels: H1, H2, H3, H4-companion + H5 splits into 3 tests but lives in 1 sentinel file)

---

## Deliverables

- `tests/Feature/Sentinels/PasswordResetMinLengthSentinelTest.php` (new)
- `tests/Feature/Sentinels/ActionLogPiiRedactionSentinelTest.php` (new)
- `tests/Feature/Sentinels/StripeWebhookReplayToleranceSentinelTest.php` (new)
- `tests/Feature/Sentinels/PayloadMismatchFailOnceSentinelTest.php` (new)
- `tests/Feature/Sentinels/I18nNoEmptyKeySentinelTest.php` (new)
- `tests/Feature/Sentinels/OutboxPipelineHealthSentinelTest.php` (updated to reflect F-3 P1 behaviour)
- `app/Http/Controllers/Auth/ForgotPasswordController.php` (H1)
- `app/Services/OrderService.php` (H2)
- `app/Http/PaymentGateways/Gateways/Stripe.php` (H3)
- `app/Jobs/DispatchDomainEventsJob.php` (H4)
- `reports/audit/couche-0-backlog-v1-0-x-2026-05-19/STATUS.md` (this file)
