# Codex Global Finishing Validation Report — POS / Kiosk / KDS / OSS / Composer / Stock / Fiscal — 2026-04-28

Date: 2026-04-28  
Executor: Codex desktop session  
Execution trace: `EXECUTE_DELEGATION: explicit-prompt-bind (human-acknowledged: "attaque", "continue")`  
Scope: super-audit rework from Claude/Orcai response, focused on C0-C6/C9/C10 plus D1-D3 and the P0/P1 gaps: runtime sync, stock/queue concurrency, fiscal cash-at-counter, catalog/photo/composer, delivery SSOT, MySQL surface filtering, release build.

---

## 1. Global Verdict

`LOCAL_MACHINE_VERDICT: PASS_STRONG_LOCAL`

`PRODLIKE_MYSQL_REDIS_VERDICT: PASS_3X`

`RUNTIME_E2E_VERDICT: PASS`

`DESIGN_D1_D2_D3_VERDICT: PASS`

`HARDWARE_UAT_DECISION: HOLD_UNTIL_PHYSICAL_DEVICE_SIGNOFF`

`GO_LIVE_DECISION: NOT_GO_LIVE_UNTIL_HARDWARE_UAT_AND_HUMAN_GATE`

Meaning:

- The code is now strong enough to proceed to hardware UAT.
- The biggest software risks raised by the latest Claude/Orcai audit were tested or corrected locally.
- I do not declare commercial go-live because TPE, fiscal printer, physical kiosk lockdown, real KDS screens, real network loss/reconnect and human signoff cannot be proven by local automation alone.

---

## 2. Corrections Applied In This Finishing Pass

### FIX-1 — Prod-like MySQL/Redis concurrency proof

Files:

- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php`
- `scripts/prodlike-concurrency-worker.php`

What changed:

- Added a MySQL-only / Redis-only prod-like test suite.
- It runs real parallel PHP worker processes, not in-memory fake concurrency.
- It proves:
  - 50 parallel stock decrements against only 20 units produce exactly 20 successes and 30 `stock_unavailable` results.
  - 50 parallel POS+kiosk queue allocations produce 50 successful unique queue numbers from `A0001` to `A0050`.

Important anchors:

- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php:27` skips unless real MySQL is used.
- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php:31` skips unless Redis cache lock is used.
- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php:40` stock stress scenario.
- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php:85` POS+kiosk queue stress scenario.
- `scripts/prodlike-concurrency-worker.php:35` worker stock path.
- `scripts/prodlike-concurrency-worker.php:42` worker POS queue path.
- `scripts/prodlike-concurrency-worker.php:55` worker kiosk queue path.

### FIX-2 — Queue lock hardening under real contention

Files:

- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`

Root cause found by machine:

- The first MySQL/Redis stress run exposed one real queue allocation failure under 50 parallel workers:
  - one worker returned `Queue number allocation is busy. Please retry.`
  - cause: lock wait and retry window were too short for 50 process contention.

Correction:

- POS path:
  - `app/Services/OrderService.php:2052` max attempts now `5`.
  - `app/Services/OrderService.php:2087` queue lock TTL now `30s`.
  - `app/Services/OrderService.php:2091` lock wait now `15s`.
- Kiosk/frontend path:
  - `app/Services/FrontendOrderService.php:830` max attempts now `5`.
  - `app/Services/FrontendOrderService.php:865` queue lock TTL now `30s`.
  - `app/Services/FrontendOrderService.php:869` lock wait now `15s`.

Symmetry note:

- The queue allocation hardening was applied symmetrically in `OrderService` and `FrontendOrderService`.
- `node tools/audit/order-service-symmetry.mjs` still passes for the existing stock symmetry guard.

### FIX-3 — MySQL migration rollback compatibility issue discovered by prod-like run

File:

- `database/migrations/2026_03_12_130000_add_performance_indexes.php`

Root cause found by machine:

- `migrate:fresh` against MySQL hit an unsafe rollback path in a legacy performance-index migration:
  - old code used a non-existent `Blueprint::dropIndexIfExists()`.
  - some MySQL performance indexes are required by foreign keys and cannot be safely dropped during rollback.

Correction:

- `database/migrations/2026_03_12_130000_add_performance_indexes.php:71` skips the down path for MySQL performance indexes.
- `database/migrations/2026_03_12_130000_add_performance_indexes.php:100` now checks indexes portably for SQLite/MySQL.
- `database/migrations/2026_03_12_130000_add_performance_indexes.php:118` drops indexes only when safe and ignores MySQL FK-required index drops.

Rationale:

- These are optional performance indexes; skipping the MySQL down path avoids destructive rollback of FK-required support indexes.
- Migration dry-run and rollback tooling tests pass after this change.

---

## 3. Validation Matrix By Mission / Feature

### C0 — Kiosk post-payment auto-return

Verdict: `PASS`

Proof:

- `npx playwright test tests/e2e/kiosk-post-payment-auto-return.spec.js ...`
- Covered inside the 19-test runtime suite.
- Result: `paid kiosk order leaves waiting, shows confirmation, then returns to idle` passed.

Risk closed:

- The reported blocker "kiosk stuck after simulated payment" is covered by an E2E test.

### C1 — Full kiosk process

Verdict: `PASS`

Proof:

- `tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js`
- 5/5 scenarios passed:
  - card simple: paid kiosk order confirms, fiscal number exists, stock decrements.
  - tacos composition: immutable snapshot survives confirmation flow.
  - cash-at-counter: kiosk confirms and remains non-fiscal before POS collection.
  - rupture projection: zero stock marks kiosk projection unavailable and blocks decrement.
  - abandon/new-order: confirmation CTA resets to locked idle surface.

### C2 — Full POS process

Verdict: `PASS`

Proof:

- `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js`
- 5/5 scenarios passed:
  - dine-in/walk-in cash.
  - takeaway customer card.
  - delivery quote recomputation ignores forged delivery charge.
  - counter-collect confirm allocates fiscal sequence.
  - counter-collect cancel releases stock and does not allocate fiscal sequence.

### C3 — Multi-surface runtime sync

Verdict: `PASS_RUNTIME_LOCAL`

Proof:

- First runtime suite: 2/2 passed inside the 19-test run.
- Run-many after build:
  - `npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --project=chromium --retries=0 --repeat-each=3`
  - Result: 6/6 passed.

Covered:

- Kiosk cash order reaches KDS, POS counter-collect, and OSS without manual reload.
- POS order reaches KDS and OSS without manual reload.
- This is real browser/runtime flow against `http://127.0.0.1:8000`, not only a static event contract.

Remaining:

- Real WebSocket provider / Reverb / Pusher production topology should still be checked in staging or hardware UAT if local uses fallback/polling.

### C4 — Stock V2 decrement/release/concurrency

Verdict: `PASS_STRONG_LOCAL_AND_PRODLIKE`

Proof:

- `php artisan test tests/Feature/Stock --stop-on-failure`
  - Result: 20 passed.
- MySQL/Redis prod-like:
  - `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php`
  - Result: 3 consecutive runs, each 2/2 passed.

Covered:

- decrement POS path.
- decrement kiosk/frontend path.
- release on cancel.
- release on refund.
- append-only movements.
- branch isolation.
- rupture projection and restore.
- 50 parallel workers against limited stock using MySQL + Redis.

### C5 — Queue number uniqueness

Verdict: `PASS_STRONG_LOCAL_AND_PRODLIKE`

Proof:

- `php artisan test tests/Feature/QueueNumberConcurrencyTest.php --stop-on-failure`
  - Result: 5 passed.
- `php artisan test tests/Feature/Sentinels/QueueNumberUniquenessSentinelTest.php --stop-on-failure`
  - Result: 1 passed.
- MySQL/Redis prod-like:
  - 3 consecutive runs.
  - each run creates 50 parallel POS/kiosk queue allocations.
  - each run produced unique sequence `A0001` to `A0050`.

Closed risk:

- The previous theoretical risk "same queue number from POS and kiosk in parallel" is now tested with a real DB and real cache lock.

### C6 — Fiscal / NF525 / outbox / history

Verdict: `PASS_LOCAL_FEATURE`

Proof:

- `php artisan test tests/Feature/Fiscal/FiscalCashAtCounterLifecycleTest.php --stop-on-failure`
  - Result: 3 passed.
- `php artisan test tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php --stop-on-failure`
  - Result: 5 passed.
- `php artisan test tests/Feature/Payment/PaymentStateMachineTransitionsTest.php --stop-on-failure`
  - Result: 2 passed.
- `php artisan test tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php --stop-on-failure`
  - Result: 9 passed.

Covered:

- kiosk cash-at-counter creates no fiscal sequence at creation.
- POS confirm allocates fiscal sequence.
- direct cancel before payment never allocates fiscal sequence.
- confirm idempotency: no duplicate sequence or duplicate payment.
- outbox claim/dedupe/retry/error semantics.

Remaining:

- Physical fiscal printer and real Z-report export/print must be checked in hardware UAT.

### C7 / B8 — Delivery and Google Maps hardening

Verdict: `PASS_BACKEND_AND_JS`

Proof:

- `php artisan test tests/Feature/Delivery --stop-on-failure`
  - Result: 3 passed.
- `php artisan test tests/Feature/DeliveryOrderContractTest.php --stop-on-failure`
  - Result: 2 passed.
- `php artisan test tests/Unit/Services/DeliveryFeeServiceTest.php --stop-on-failure`
  - Result: 1 passed.
- Vitest:
  - `tests/js/deliveryCharge.spec.js`
  - `tests/js/checkoutGeocodeError.spec.js`
  - included in the JS run, result 12 delivery/geocode tests passed.

Covered:

- backend recomputes delivery fee.
- web/POS forged delivery charge is ignored.
- geocode failure returns `GEOCODE_FAILED`.
- no frontend-authoritative delivery price.

Remaining:

- Real Google Maps key/network in hardware/staging UAT.

### C8 — Realtime sync / broadcast contracts

Verdict: `PASS_LOCAL_RUNTIME_FOR_CORE_PATHS`

Proof:

- C3 runtime Playwright 6/6 after build.
- Vitest realtime/KDS contracts:
  - `kdsVersionGate.spec.js`
  - `kdsBackoffOn5xx.spec.js`
  - `kdsSyncCadence.spec.js`
  - `realtimeBroadcastFallback.spec.js`
  - total relevant JS sync tests passed inside the 44-test run.

Remaining:

- Production realtime infrastructure must be validated with real provider credentials, not only local server.

### C9 — Dashboard management / catalog / composer / photo / stock

Verdict: `PASS_API_AND_CONTRACT; FULL_BROWSER_DASHBOARD_UI_NOT_EXHAUSTIVELY_RE-RUN`

Proof:

- `php artisan test tests/Feature/Composer/ComposerProfileApiTest.php --stop-on-failure`
  - Result: 2 passed.
- `php artisan test tests/Feature/Composer/ComposerAuthzMinimalTest.php --stop-on-failure`
  - Result: 6 passed.
- `php artisan test tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php --stop-on-failure`
  - Result: 1 passed.
- `php artisan test tests/Feature/Catalog/CatalogChangedDispatchTest.php --stop-on-failure`
  - Result: 2 passed.
- `php artisan test tests/Feature/Catalog/CatalogOutboxIdempotencyTest.php --stop-on-failure`
  - Result: 1 passed.
- `php artisan test tests/Feature/Catalog/ComposerSchemaTest.php --stop-on-failure`
  - Result: 2 passed.
- `php artisan test tests/Feature/Catalog/AddonRolePersistenceTest.php --stop-on-failure`
  - Result: 2 passed.
- `php artisan test tests/Feature/Menu --stop-on-failure`
  - Result: 20 passed, 6 skipped under SQLite.
- MySQL closure for skipped surface filtering:
  - `DB_CONNECTION=mysql ... php artisan test tests/Feature/Menu/FrontendSurfaceFilteringTest.php`
  - Result: 6 passed.
- Projection:
  - `MenuProjectionComposerProfileTest`: 3 passed.
  - `MenuProjectionParitySentinelTest`: 5 passed.
- Vitest dashboard/composer:
  - `productComposerEditor.spec.js`: 4 passed.
  - `productComposerSummary.spec.js`: 3 passed.
  - `kioskWizardComposerProfile.spec.js`: 3 passed.
  - `posWizardComposerProfile.spec.js`: 3 passed.

Covered:

- composer profile API CRUD/publish/unpublish.
- composer rejects price payload.
- composer step rejects price payload.
- authz branch admin own branch only.
- branch admin cannot mutate foreign composer steps.
- tenant admin can manage all branches.
- POS/delivery roles forbidden.
- product photo upload invalidates kiosk menu and updates snapshot.
- catalog eventing/outbox idempotency.
- POS and kiosk projection consume composer profile without price duplication.

Remaining:

- Full human browser walkthrough of the restaurateur dashboard is still recommended before release:
  - create category.
  - create product.
  - upload photo.
  - configure composer steps.
  - attach stock.
  - publish.
  - verify kiosk/POS display visually.

### C10 — Authz matrix

Verdict: `PASS_TARGETED_FOR_COMPOSER_AND_COUNTER; FULL_7_ROLE_MATRIX_NOT_RE-RUN`

Proof:

- `ComposerAuthzMinimalTest`: 6 passed.
- `CounterDeferredPaymentLifecycleTest`: branch scope and permission checks passed.
- Existing branch/isolation sentinels were not fully re-run as one matrix in this pass.

Remaining:

- A full 7-role x route x branch matrix remains a good hardening mission before go-live if admin surface security is a release blocker.

### D1 — Kiosk design

Verdict: `PASS`

Proof:

- `npx playwright test tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js ...`
- Result: 1 aggregate spec passed.

### D2 — POS design

Verdict: `PASS`

Proof:

- `npx playwright test tests/e2e/design/pos/d2-pos-design-audit.spec.js ...`
- Result: 1 aggregate spec passed.

### D3 — KDS/OSS design

Verdict: `PASS`

Proof:

- `npx playwright test tests/e2e/design/kds/d3-kds-oss-design-audit.spec.js ...`
- Result: 1 aggregate spec passed.

### D4-D13 — Massive prod-live campaign

Verdict: `PARTIAL_BY_EQUIVALENT_TARGETED_RUNS`

Reason:

- I executed the most important equivalents locally:
  - D4/D5 functional kiosk/POS through C1/C2 E2E.
  - D7 sync through C3 runtime run-many.
  - D8 fiscal/outbox through C6 feature tests.
  - D9 stock/queue through prod-like MySQL/Redis.
  - D10 delivery/pricing tamper through delivery and composer price-payload rejection tests.
  - D1/D2/D3 design directly.
- I did not execute a full 2000-run D0-D13 campaign. That would be a separate long runner and should be scheduled when hardware/staging is ready.

---

## 4. Complete Test Evidence From This Pass

### Environment / safety

- `npm run verify:boucle`
  - Result: governed loop OK, Claude binary present.
- `bash .cursor/hooks/safety-check.sh`
  - Result: passed.
- PHP syntax:
  - `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php`
  - `scripts/prodlike-concurrency-worker.php`
  - `database/migrations/2026_03_12_130000_add_performance_indexes.php`
  - `app/Services/OrderService.php`
  - `app/Services/FrontendOrderService.php`
  - Result: no syntax errors.

### MySQL / Redis

- MySQL available: `mysqladmin ping` returned alive.
- Redis available: `redis-cli ping` returned `PONG`.
- Prod-like temp DB:
  - `foodking_codex_concurrency_20260428`
- Surface filtering temp DB:
  - `foodking_codex_mysql_surface_20260428`

### Backend test results

- Prod-like MySQL/Redis: `ProdLikeConcurrencyTest` 3 runs x 2 tests = 6 passed.
- MySQL surface filtering: 6 passed.
- Fiscal cash-at-counter lifecycle: 3 passed.
- Counter deferred payment lifecycle: 5 passed.
- Payment state machine transitions: 2 passed.
- Outbox concurrent worker dedupe: 9 passed.
- Stock suite: 20 passed.
- Queue number concurrency: 5 passed.
- Queue uniqueness sentinel: 1 passed.
- Composer profile API: 2 passed.
- Composer authz minimal: 6 passed.
- Catalog photo E2E invalidation: 1 passed.
- Catalog changed dispatch: 2 passed.
- Catalog outbox idempotency: 1 passed.
- Composer schema: 2 passed.
- Addon role persistence: 2 passed.
- Menu suite: 20 passed, 6 skipped under SQLite.
- MySQL rerun for skipped `FrontendSurfaceFilteringTest`: 6 passed.
- Menu projection composer profile: 3 passed.
- Menu projection parity sentinel: 5 passed.
- Delivery suite: 3 passed.
- Delivery order contract: 2 passed.
- Delivery fee unit: 1 passed.
- Migration dry-run tooling: 2 passed.
- Migration rollback tooling: 3 passed.

### JavaScript / Vitest results

Command:

```bash
npx vitest run tests/js/productComposerEditor.spec.js tests/js/productComposerSummary.spec.js tests/js/kioskWizardComposerProfile.spec.js tests/js/posWizardComposerProfile.spec.js tests/js/kioskRuptureUx.spec.js tests/js/posRuptureUx.spec.js tests/js/kioskWaitingAutoReturn.spec.js tests/js/kioskConfirmationCountdown.spec.js tests/js/deliveryCharge.spec.js tests/js/checkoutGeocodeError.spec.js tests/js/kioskCartSendPayload.spec.js tests/js/kdsVersionGate.spec.js tests/js/kdsBackoffOn5xx.spec.js tests/js/kdsSyncCadence.spec.js tests/js/realtimeBroadcastFallback.spec.js
```

Result:

- 15 test files passed.
- 44 tests passed.

Note:

- One earlier invocation failed before running tests because `--runInBand` is not supported by this Vitest version. It was immediately rerun without that invalid option and passed.

### Playwright runtime results

Command:

```bash
npx playwright test tests/e2e/kiosk-post-payment-auto-return.spec.js tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js tests/e2e/pos-full-process/c2-pos-process-audit.spec.js tests/e2e/c3-runtime-multi-surface.spec.js tests/e2e/composer-mega-flow.spec.js tests/e2e/kiosk-lockdown.spec.js --project=chromium --retries=0
```

Result:

- 19 passed.

Command:

```bash
npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --project=chromium --retries=0 --repeat-each=3
```

Result:

- 6 passed.

Command:

```bash
npx playwright test tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js tests/e2e/design/pos/d2-pos-design-audit.spec.js tests/e2e/design/kds/d3-kds-oss-design-audit.spec.js --project=chromium --retries=0
```

Result:

- 3 passed.

### Build

- `npm run production`
  - Result: compiled successfully.

---

## 5. Files Most Concerned By The Current Finishing Pass

Implementation / correction files:

- `app/Services/OrderService.php`
- `app/Services/FrontendOrderService.php`
- `database/migrations/2026_03_12_130000_add_performance_indexes.php`
- `tests/Feature/ProdLike/ProdLikeConcurrencyTest.php`
- `scripts/prodlike-concurrency-worker.php`

Important existing test surfaces revalidated:

- `tests/e2e/c3-runtime-multi-surface.spec.js`
- `tests/e2e/kiosk-post-payment-auto-return.spec.js`
- `tests/e2e/kiosk-full-process/c1-kiosk-process-audit.spec.js`
- `tests/e2e/pos-full-process/c2-pos-process-audit.spec.js`
- `tests/e2e/composer-mega-flow.spec.js`
- `tests/e2e/kiosk-lockdown.spec.js`
- `tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js`
- `tests/e2e/design/pos/d2-pos-design-audit.spec.js`
- `tests/e2e/design/kds/d3-kds-oss-design-audit.spec.js`
- `tests/Feature/Fiscal/FiscalCashAtCounterLifecycleTest.php`
- `tests/Feature/Payment/CounterDeferredPaymentLifecycleTest.php`
- `tests/Feature/Outbox/OutboxConcurrentWorkerDedupeTest.php`
- `tests/Feature/Stock`
- `tests/Feature/QueueNumberConcurrencyTest.php`
- `tests/Feature/Menu/FrontendSurfaceFilteringTest.php`
- `tests/Feature/Composer/ComposerProfileApiTest.php`
- `tests/Feature/Composer/ComposerAuthzMinimalTest.php`
- `tests/Feature/Catalog/PhotoEndToEndKioskInvalidationTest.php`

---

## 6. Remaining Work / Honest Limits

### R1 — Hardware UAT is still mandatory

Status: `REQUIRED_BEFORE_GO_LIVE`

Needs physical validation:

- TPE / payment terminal success/refusal/timeout.
- Fiscal printer: paid POS ticket, kiosk cash-at-counter confirm ticket, non-fiscal counter slip reprint.
- Real kiosk browser lockdown: no URL bar escape, no admin route escape, touch UX.
- Real KDS screen readability and realtime update.
- Real network loss/reconnect.
- Real Google Maps/geocode key and address validation.
- Multi-device branch isolation with separate screens.

### R2 — Full restaurateur dashboard browser walkthrough

Status: `RECOMMENDED_BEFORE_COMMERCIAL_RELEASE`

Automated API/contract tests pass. A human browser walkthrough should still confirm:

- create category.
- create product.
- upload/change/delete photo.
- configure composer profile and steps.
- assign stock.
- publish.
- verify POS/kiosk projections visually.

### R3 — Full D4-D13 2000-run campaign

Status: `OPTIONAL_HEAVY_BEFORE_LIVE; RECOMMENDED_FOR_MAX_CONFIDENCE`

I executed targeted equivalents for the highest-risk issues, not the complete 2000-run matrix. If the objective is "abuse the system until statistical confidence", launch the full D0-D13 campaign in a dedicated long-running window after hardware/staging is stable.

### R4 — Production realtime provider

Status: `STAGING_CHECK_REQUIRED`

Local runtime sync is green. Staging must confirm real provider config:

- Reverb/Pusher credentials.
- channel auth.
- reconnect.
- no 429 under real screens.

---

## 7. Final Position

What is validated strongly:

- Kiosk order flow.
- POS order flow.
- KDS/OSS receiving orders without manual reload in local runtime.
- Kiosk auto-return after simulated payment.
- Cash-at-counter lifecycle: pending, confirm, direct cancel, no premature fiscal sequence.
- Stock decrement/release/rupture, including 50-worker MySQL/Redis stress.
- Queue number uniqueness, including 50-worker POS+kiosk MySQL/Redis stress.
- Backend delivery fee SSOT and geocode failure blocking.
- Composer API/authz/projection without frontend price duplication.
- Product photo update invalidates kiosk menu snapshot.
- Kiosk/POS/KDS design aggregate audits.
- Production frontend build.

What is not honestly claimable from local automation:

- Physical TPE integration.
- Physical printer/NF525 paper behavior.
- Locked kiosk OS/browser shell.
- Real production realtime infrastructure.
- Real customer hardware UX.
- Full 2000-run D4-D13 chaos/statistical campaign.

Recommended next command for Claude independent review:

```bash
claude -p "Lis AGENTS.md, reports/audit/CODEX_GLOBAL_FINISHING_VALIDATION_REPORT_2026-04-28.md, reports/audit/CODEX_SUPER_AUDIT_EXECUTION_STATUS_2026-04-28.md, reports/audit/CODEX_M1_C3_RUNTIME_MULTI_SURFACE_2026-04-28.md, tests/Feature/ProdLike/ProdLikeConcurrencyTest.php, scripts/prodlike-concurrency-worker.php, app/Services/OrderService.php, app/Services/FrontendOrderService.php, database/migrations/2026_03_12_130000_add_performance_indexes.php. Fais un audit critique independant FoodKing: verifie les invariants prix backend SSOT, branch_id isolation, OrderStatus enum, dispatch after commit, symetrie OrderService/FrontendOrderService, queue number sous concurrence, stock atomique, cash-at-counter NF525, sync Kiosk-POS-KDS-OSS, et dis PASS_PROCEED_HARDWARE_UAT ou REWORK avec findings P0/P1 file:line. Ne valide pas le hardware, seulement le code local et la readiness UAT." --model claude-opus-4-7 --effort high
```

