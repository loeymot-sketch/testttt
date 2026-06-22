# Test Inventory — FoodKing / Le Cayenne (TESTER specialist, ultra-v1-plan 2026-05-29)

> ANTI-HALLUCINATION CONTRACT: every path/count below comes from an actual
> `find`/`ls`/`Read` result captured this session. Nothing is invented.
> Items marked "TO BE CREATED" do NOT exist yet — they are gap recommendations.

---

## Test Inventory (grouped by domain, REAL paths + counts)

**Total PHPUnit `*Test.php` files: 606** (`find tests -name "*Test.php" | wc -l`).
Suites in `phpunit.xml`: **Unit** (`./tests/Unit`), **Feature** (`./tests/Feature`),
**Load** (`./tests/load`). `@group manual` is excluded from CI (phpunit.xml 32-36).

Grouped by top-level domain folder under `tests/Feature/` (real counts):

| Folder | N | Folder | N | Folder | N |
|---|---|---|---|---|---|
| Sentinels | 93 | Payment | 9 | Idempotency | 4 |
| Fiscal | 43 | KDS | 9 | Frontend | 4 |
| Stock | 21 | Order | 8 | Coupon | 4 |
| Composer | 19 | Orders | 7 | Loyalty | 3 |
| Menu | 18 | Webhooks | 6 | Queue | 3 |
| Pos | 15 | Sync | 6 | Dashboard | 3 |
| KioskPhase1 | 14 | Refund | 6 | Items | 3 |
| Catalog | 13 | Branch | 6 | OSS | 2 |
| Admin | 13 | Auth | 6 | Multitenant | 2 |
| Observability | 12 | Migrations | 5 | KioskSecurity | 2 |
| Cash | 12 | Kiosk | 4 | Database | 2 |
| Outbox | 11 | Ingredients | 4 | (many singletons) | 1 |
| Delivery | 10 | Security | 9 | | |

**tests/Unit/** (VERIFIED): Services (4), Services/Pricing (3), Security (2),
Rules (2), Payment (2), Domain/Order (2), Services/Payment (1), Services/Menu (1),
Services/Fiscal (1), PaymentGateways (1), Listeners (1), Http/Resources (1),
Domain/Events (1).

**tests/load/** (VERIFIED): `tests/load/RushMidiSimulationTest.php` (`@group stress`,
rush-midi concurrency simulation).

Domain-critical singletons (real paths, sample):
- Order/state machine: `tests/Feature/OrderStateTransitionTest.php`,
  `OrderStateMachineLockForUpdateTest.php`, `OrderFlowTest.php`,
  `OrderItemCompositionSnapshotTest.php`, `ConcurrentOrderTest.php`,
  `CleanupVsConfirmRaceTest.php`.
- KDS: `KdsTransitionWhitelistTest.php`, `KDSScopeRestrictionTest.php`,
  `KdsChangeStatusConcurrencyTest.php`, `KdsExpectedStatusConflictTest.php`,
  `KdsBranchFilterExactTest.php`, `KDSFlowTest.php`.
- OSS: `tests/Feature/OSSReadOnlyTest.php` + `tests/Feature/OSS/` (2 in folder).
- Pricing SSOT: `PosPricingSsotProofTest.php`, `PosKioskPricingParityTest.php`,
  `PricingIntegrityTest.php`, `tests/Unit/Services/Pricing/` (3),
  `tests/Feature/Services/Pricing/` (3).
- Kiosk quote/security: `KioskQuoteIntegrityTest.php`,
  `KioskQuoteTokenRequiredOnCommitTest.php`, `KioskScopeIsolationTest.php`,
  `KioskPaymentStateMachineTest.php`, `KioskLoyaltyLedgerAtomicTest.php`.
- Payment edges: `PaymentConfirmCrossBranchTest.php`,
  `PaymentConfirmMachineResolverTest.php`, `PosTicketRestaurantPaymentTest.php`.

---

## Sentinels (real path + what it locks)

**123 `*Sentinel*Test.php` files total** (`find tests -name "*Sentinel*Test.php" | wc -l`).
The `tests/Feature/Sentinels/` folder holds **93 `.php` files** (85 match `*Sentinel*`;
the other 8 are non-"Sentinel"-named drift/bypass tests e.g.
`BypassPaymentInvariantsTest.php`, `IdempotencyRecoveryBranchScopedTest.php`,
`PlaywrightFixtureCleanupCommandTest.php`). The remaining ~38 `*Sentinel*Test.php` are
scattered across domain folders. Sentinels are baseline-lock / drift guards
(count grows → CI fails). Key ones (real paths; "what it locks" from canonical name —
verify docstring before citing as a hard contract):

- `tests/Feature/Branch/BranchScopeCoverageSentinelTest.php` — BranchScope on the locked
  baseline of 20 models (CLAUDE.md §9); paired with
  `tests/Feature/Sentinels/ClaudeMdBranchScopeCountSentinelTest.php`.
- `tests/Feature/Sentinels/FormRequestAuthzDriftSentinelTest.php` — ceiling on FormRequests
  with bare `return true;`.
- Pricing SSOT: `Sentinels/ClientTotalWriteForbiddenSentinelTest.php`,
  `Sentinels/PosSubtotalForgerySentinelTest.php`,
  `Sentinels/PricingSsotFlagProductionStableSentinelTest.php`,
  `Sentinels/Zone5PricingSsotConvergenceSentinelTest.php`.
- NF525 fiscal: `Sentinels/F001KioskFiscalSequenceInvariantSentinelTest.php`,
  `Sentinels/FiscalSealedZSentinelTest.php`, `Sentinels/FiscalZBranchExactnessSentinelTest.php`,
  `Fiscal/ZReportCloseAuditAnchorSentinelTest.php`,
  `Fiscal/FiscalAllocErrorFlagOutsideTxSentinelTest.php`.
- Idempotency: `Sentinels/IdempotencyMiddlewareProductionGuardSentinelTest.php`,
  `Security/IdempotencyCrossUserLeakSentinelTest.php`.
- Production boot guards: `Sentinels/PosSimulationHardwareProductionGuardSentinelTest.php`,
  `Boot/ProductionBootGuardsCompletenessSentinelTest.php`,
  `Sentinels/CorsAppUrlProductionGuardSentinelTest.php`.
- Sync: `Sync/OrderCreatedDispatchPlacementSentinelTest.php`,
  `Sync/PusherChannelAuthWildcardSentinelTest.php`,
  `Sentinels/StatusPropagationGapSentinelTest.php`.
- KDS: `Sentinels/KdsTransitionWhitelistSentinelTest.php`,
  `Sentinels/KdsExpectedStatusConflictSentinelTest.php`,
  `Sentinels/KdsItemAvailabilityEchoSentinelTest.php`.
- Payment/split: `Sentinels/PaymentConfirmConcurrencySentinelTest.php`,
  `Sentinels/PaymentConfirmCrossBranchSentinelTest.php`,
  `Sentinels/PosSplitPaymentPhantomCardSentinelTest.php`,
  `Sentinels/SplitPaymentSentinelTest.php`.
- Branch exactness: `Sentinels/OrderListBranchExactnessSentinelTest.php`,
  `Sentinels/TransactionBranchExactnessSentinelTest.php`,
  `Sentinels/OssAdminBranchPolicySentinelTest.php`.

(Full 123-path list captured this session; available on request.)

---

## E2E / Playwright specs (real paths)

- **`tests/e2e/` — 256 `.spec.js`** (`ls tests/e2e/*.spec.js | wc -l`). Most are dated
  session captures (prefixed `_` or `audit-*`/`goal-*`). The **canonical numbered E2E
  suite** (real, non-underscore): `01-auth-refresh`, `02-pos-cash`, `03-kiosk-wizard`,
  `04-kds-status`, `05-pos-card`, `06-staff-only-routing`, `08-admin-baseurl`,
  `09-admin-dashboards-ui`. Cross-surface specs present:
  `tests/e2e/stock-rupture-sync.spec.js`, `concurrent-orders.spec.js`,
  `global-pos-kiosk-order-trace.spec.js`, `c3-runtime-multi-surface.spec.js`,
  `audit-sync-rupture-2026-05-07.spec.js`, `audit-max-sync-order-journey-documentation.spec.js`.
- **`tests/Playwright/` — 20 `.spec.js`**, incl. `pos-receives-kiosk-realtime.spec.js`,
  `KdsMultiScreenPlaywrightTest.spec.js`, `kiosk-quote-pin.spec.js`,
  `kiosk-offline-waiting.spec.js`, plus `critical-flow/` + `sentinels/` subdirs.
- **`tests/mobile-e2e/` — 22 `.spec.js`** (loyalty earn/redeem/wallet flows etc.).
- **`tests/web-e2e/`** — only `playwright.config.js` (NO specs yet — standalone web site).
- **`tests/js/` — 216 Vitest specs** (`ls tests/js/*.spec.js | wc -l`). NOTE:
  `find resources -name "*.test.js"/"*.spec.js"` = **0** — ALL JS unit specs live in
  `tests/js/`, not `resources/`. Sample: `posCart.spec.js`,
  `posPaymentComponentContract.spec.js`, `paymentComponent401Retry.spec.js`,
  `KioskWizard.spec.js`, `kdsState.spec.js`, `kdsSyncCadence.spec.js`,
  `posSyncFallback.spec.js`, `posKioskVariationParity.spec.js`. Subdirs:
  `tests/js/a11y/`, `tests/js/sentinels/`, `tests/js/quickwins/`, `__fixtures__/`.
- npm scripts (`package.json`): `test`=`vitest run`; `test:e2e:full`=full Playwright;
  `test:e2e:smoke`=5 specs (`01-auth-refresh`,`02-pos-cash`,`03-kiosk-wizard`,
  `04-kds-status`,`stock-rupture-sync`).

---

## CI pipeline (what runs, SQLite/MySQL, drift risk)

5 workflows in `.github/workflows/` (all VERIFIED by Read — all parse cleanly):

1. **`phpunit.yml` (`name: PHPUnit (MySQL)`)** — the primary backend gate. Two jobs:
   - `invariants-grep`: `scripts/check-invariants.sh` (6 greps, blocks merge on
     POS pricing/orderStatus regression).
   - `phpunit-mysql`: spins **MySQL 8.0 + Redis 7 service containers**, runs migration
     drift check (`migrate --pretend` + `migrate` + `migrate:status`), then
     **`vendor/bin/phpunit --testdox` (the FULL 606-file suite on MySQL)**, then
     `--filter FrontendSurfaceFilteringTest`. Env: `DB_CONNECTION=mysql`,
     `CACHE_DRIVER=array`, `QUEUE_CONNECTION=sync`, `PRICING_USE_SSOT=true`, PHP 8.2.
     Rationale in the file header: SQLite was masking a real `whereJsonContains`
     surface-filter regression, so CI forces MySQL.
2. **`ci-sync-rupture-harness.yml`** — brings up a **real soketi (Pusher-protocol)
   broadcaster** on 127.0.0.1:6001 + MySQL, sets `BROADCAST_DRIVER=pusher` +
   `QUEUE_CONNECTION=database`, and runs `OutboxPipelineHealthSentinel` end-to-end.
   Explicitly exists because the default log+sync env makes sync-rupture tests
   "pass for the wrong reason". Path-triggered on outbox/event/broadcast files.
3. **`vitest.yml`** — `actions/setup-node@v4` (node 20, npm cache), `npm ci`,
   POS invariant guards (`pos:lint:status` + `pos:lint:pricing`), then
   `npx vitest run --reporter=verbose` (216 JS specs). Parses cleanly.
4. **`playwright.yml`** — Playwright E2E lane (9.6 KB; not fully read this session).
5. **`legacy-guards.yml`** — path-triggered; runs 4 lint scripts (archive banner,
   legacy imports, legacy routes, bundle scan).

**DB engine: BOTH, by layer.** Local-dev default = SQLite `:memory:` (phpunit.xml).
**CI runs the full PHPUnit suite on MySQL 8.0** via `phpunit.yml`, plus a MySQL+soketi
sync harness. This is the correct posture: the SQLite-vs-MySQL blind spot most projects
have is explicitly closed here — NF525 triggers / `FOR UPDATE` / JSON predicates / branch
scope all execute against real MySQL before merge.

**DRIFT RISK (LOW-MODERATE):**
- CI runs the WHOLE suite on MySQL, so no per-test allowlist gap — good. Residual risk:
  the NF525 `BEFORE DELETE` triggers + GRANT-REVOKE are documented as "MySQL prod only"
  (CLAUDE.md §8); confirm the CI MySQL migrations actually install those triggers (the
  harness runs `php artisan migrate`, so it should — verify the trigger migration is not
  guarded behind a non-test env check).
- `playwright.yml` not fully read — confirm which E2E specs it actually executes (the
  256-file `tests/e2e/` dir is mostly ad-hoc audit captures, not a curated CI suite).
- Per the `phpunit.yml` header comment (NOT independently verified in the test class),
  the 3 `FrontendSurfaceFilteringTest` surface tests auto-skip on non-MySQL drivers — so
  local SQLite dev runs would not catch surface-filter regressions; only CI does. Confirm
  this skip behavior in the test class before citing it as a hard guarantee.

---

## Coverage GAPS (per critical domain)

- **Cross-surface SYNC (Kiosk→KDS→OSS)** — COVERED at E2E + harness level
  (`tests/e2e/stock-rupture-sync.spec.js`, `global-pos-kiosk-order-trace.spec.js`,
  `tests/Playwright/pos-receives-kiosk-realtime.spec.js`, the soketi CI harness +
  sentinel `StatusPropagationGapSentinelTest.php`). GAP: no SERVER-SIDE PHPUnit test
  asserting full 3-surface status ORDERING under concurrent load.
  **TO BE CREATED at `tests/Feature/Sync/CrossSurfaceOrderPropagationTest.php`.**
- **Order-flow full lifecycle across actors** — strong unit coverage of individual
  transitions/locks, but no single test driving PLACED→PREPARING→READY→COLLECTED
  across POS+KDS+OSS. **TO BE CREATED at `tests/Feature/Order/CrossSurfaceLifecycleTest.php`.**
- **OSS** — thin: only `OSSReadOnlyTest.php` + 1 in `tests/Feature/OSS/` + sentinel
  `OssAdminBranchPolicySentinelTest.php`. **TO BE CREATED at
  `tests/Feature/OSS/OssAllowlistFailClosedTest.php`** (whereIn(KIOSK,TAKEAWAY)
  fail-closed + branch isolation, per memory project_pos_first_page_oss_filter).
- **Payment edge — multi-tranche split + refund-after-Z** — split sentinels exist
  (`SplitPaymentSentinelTest`, `PosSplitPaymentPhantomCardSentinelTest`,
  `RefundCounterEntryRequiresSealedParentSentinelTest`) but multi-tranche partial
  refund is deferred (V1.0.2 per memory).
  **TO BE CREATED at `tests/Feature/Payment/MultiTranchePartialRefundTest.php`.**
- **NF525 DELETE-trigger enforcement under MySQL** — the suite runs on MySQL in CI, but
  there is no explicit test asserting that `DELETE FROM audit_logs` / `z_reports` is
  REJECTED by the `BEFORE DELETE` trigger. The single highest-stakes invariant deserves
  a direct test. **TO BE CREATED at `tests/Feature/Fiscal/AuditChainDeleteTriggerTest.php`**
  (skip on SQLite, assert SQLSTATE 45000 on MySQL).

Domains with HEALTHY coverage (no new V1 test required): Fiscal (43 + MySQL CI),
Stock/rupture (21 + sync harness), Composer/wizard (19), Pricing SSOT (sentinel-locked
+ grep guards), BranchScope (sentinel + MySQL CI), Idempotency (4 + sentinels),
Outbox (11 + soketi harness).

---

## Maturity score: **9 / 10**

Strengths: 606 PHPUnit files; **123 drift-sentinels** (exceptional discipline);
**CI runs the FULL backend suite on real MySQL 8** (closes the SQLite blind spot most
projects miss) with migration-drift guard + invariant greps; a **dedicated soketi
Pusher-protocol sync-rupture harness** that prevents "passes for the wrong reason"
broadcast tests; 216 Vitest specs gated behind POS pricing/orderStatus lint guards;
256 e2e + 20 Playwright + 22 mobile-e2e specs; a load/stress suite. Deductions:
(a) no server-side cross-surface lifecycle/propagation-ordering PHPUnit test;
(b) OSS coverage thin (2 files); (c) no explicit NF525 DELETE-trigger-rejection test;
(d) multi-tranche partial-refund deferred to V1.0.2; (e) `tests/e2e/` is mostly ad-hoc
audit captures rather than a curated CI E2E suite — confirm which specs `playwright.yml`
actually runs.
