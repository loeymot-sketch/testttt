# GPT Ultra Review Wave 1 + Wave 2 Caisse V1

Date: 2026-04-26
Reviewer: Codex / GPT
Scope: review of Wave 1 / Wave 2 implementation evidence on disk, mission outputs, diff against merge base `b8b4fb76bcee45938c6a66862c3351407aa03fd9`, and targeted validation runs.

## Verdict

`RELEASE_VERDICT: RED_NEEDS_REWORK_BEFORE_FULL_GREEN`

The implemented core is mostly strong: backend invariants, pricing quote guards, branch isolation sentinels, KDS release logic, POS discount/payment refactors, kiosk routing/quote/offline/error/wizard work, and after-commit dispatch all have meaningful passing tests.

However this cannot be called full green yet because:

1. `CV1-LOT-K09-POS-REALTIME-KIOSK-VIS` stopped at `SCOPE_PRESSURE` with no implementation.
2. `CV1-LOT-K05-PAYMENT-CONFIRM-WS` and `CV1-LOT-P06-PARK-TTL` remain `BLOCKED_SKIP`.
3. The canonical Wave 2 Playwright commands for `tests/Playwright/*` are not collected by the root Playwright config.
4. The live root Playwright suite currently fails one POS flow and has one flaky auth/routing test.

## Findings

### P1 - K09 realtime POS visibility is not implemented

Evidence:
- `missions/CV1-LOT-K09-POS-REALTIME-KIOSK-VIS/output_codex.json`: status `SCOPE_PRESSURE`.
- `app/Listeners/PersistOrderCreatedToOutbox.php:24` builds the actual `OrderCreated` outbox payload with `order_id`, `queue_number`, `status`, `order_type`, `total`, `created_at`, but no explicit `_origin` or `payment_method`.
- `app/Listeners/PersistOrderStatusChangedToOutbox.php:24` builds the actual `OrderStatusChanged` payload with `order_id`, `queue_number`, `old_status`, `new_status`, `token`, but no explicit `_origin` or `payment_method`.

Impact:
POS realtime kiosk visibility cannot be certified because the payload consumed by realtime clients is persisted by outbox listeners outside the K09 allowlist. Editing only `OrderCreated.php`, `OrderStatusChanged.php`, `OrderResource.php`, or `posOrder.js` would not change the broadcasted payload.

Required rework:
Replan K09 with allowlist including:
- `app/Listeners/PersistOrderCreatedToOutbox.php`
- `app/Listeners/PersistOrderStatusChangedToOutbox.php`
- backend event contract tests
- frontend event contract / POS realtime tests
- `tests/Playwright/pos-receives-kiosk-realtime.spec.js` or equivalent live E2E

### P1 - Wave 2 Playwright canonical commands are red by config

Evidence:
- `playwright.config.js:8` sets `testDir: './tests/e2e'`.
- Wave 2 specs live under `tests/Playwright/*`.
- Reproduced command: `npx playwright test tests/Playwright/kiosk-errors.spec.js` -> `NO_TESTS_FOUND`.
- Equivalent command passes: `npx playwright test --config tests/Playwright tests/Playwright/kiosk-errors.spec.js` -> 2 passed.
- `npx playwright test --config tests/Playwright` -> 8 passed.

Impact:
Mission reports saying "Playwright pass with config override" are useful, but the mandatory commands written in several mission briefs remain red. The release gate should not treat those commands as green until the root config or the command contract is fixed.

Required rework:
Choose one SSOT:
- move Wave 2 specs under `tests/e2e`, or
- update root `playwright.config.js` with a dedicated project/testMatch for `tests/Playwright`, or
- update all mandatory test commands to include `--config tests/Playwright`.

### P1 - Live root Playwright suite is not full green

Command:
`npx playwright test`

Result:
- 24 passed
- 1 flaky then passed on retry: `tests/e2e/06-staff-only-routing.spec.js:64`
- 1 failed after retry: `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts:86`

Failure:
`tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts:101` cannot find the configured meat row:
`Viande A introuvable - adapter E2E_POS_MEAT_A_RE / seed`.

Impact:
The live POS tacos cash receipt flow is not certified on the current local seed. This may be seed/env drift rather than product logic, but the gate is still red until the seed contract or environment variables are corrected.

Required rework:
Either stabilize the seed used by this E2E or make the test discover the intended variation by stable test IDs / fixtures instead of free-text regex defaults.

### P2 - Admin login E2E is flaky

Evidence:
- `tests/e2e/06-staff-only-routing.spec.js:64` timed out once waiting for an admin URL and stayed on `/login`.
- Retry passed.

Impact:
This is not a product-blocking failure by itself because retry passed, but it reduces confidence in auth/session isolation and can mask regressions.

Required rework:
Inspect login helper isolation, session cleanup, throttle/lockout state, and test ordering effects.

### P1 - Two Wave 2 lots are intentionally blocked, not done

Evidence:
- `CV1-LOT-K05-PAYMENT-CONFIRM-WS`: `BLOCKED_SKIP`.
- `CV1-LOT-P06-PARK-TTL`: `BLOCKED_SKIP`.

Impact:
Wave 2 cannot be described as fully complete. The skip may be correct under gates, but it must remain visible in release readiness.

Required rework:
Keep them red until their gates are signed or the release scope explicitly excludes them.

### P2 - Several Wave 2 Playwright specs are static sentinels, not runtime browser flows

Evidence:
- `tests/Playwright/kiosk-errors.spec.js:8` imports Playwright but reads source files with `fs`.
- `tests/Playwright/kiosk-quote-pin.spec.js:19` checks source snippets, not runtime cart behavior.

Impact:
These are valuable contract sentinels, but they do not prove click/navigation/payment behavior in a browser. They should not be labeled as full E2E coverage.

Required rework:
Keep static sentinels, but add at least one live kiosk flow for quote -> cart -> payment/error/waiting after the remaining gates allow it.

## Green Evidence

Backend targeted validation:

`php artisan test --filter='AfterCommitDispatchTest|DispatchAfterCommitTest|OutboxRescueTest|KdsExpectedStatusConflictTest|KitchenReleaseRuleTest|KdsPaginationOverflowTest|KdsTransitionWhitelistTest|CancelAuditTrailTest|DiningTableReleaseAfterPosOrderTest|FloorplanControllerTest|PosCollectKioskCashRouteTest|PaymentNoopIdempotencyTest'`

Result: 52 passed.

Frontend targeted validation:

`npx vitest run tests/js/kioskGlobalErrors.spec.js tests/js/KioskPhase3Screens.spec.js tests/js/KioskPhase3EdgeCases.spec.js tests/js/kioskWizardNavigation.spec.js tests/js/KioskWizard.spec.js tests/js/kioskSandwichSplit.spec.js tests/js/kioskTacosSize.spec.js`

Result: 7 files passed, 174 tests passed.

Wave 2 static Playwright sentinel validation:

`npx playwright test --config tests/Playwright`

Result: 8 passed.

Live root Playwright validation:

`npx playwright test`

Result: red: 24 passed, 1 flaky, 1 failed.

## Mission Status Summary

PASS by self-audit / output evidence:
- D01 client total invariant
- D02 order event outbox map
- D03 branch filter matrix
- D04 delivery API contract
- D05 cancel audit trail
- D06 broadcast fallback doc
- D07 OS/FOS symmetry contract
- P01 quote bind
- P02 discount guard
- P03 discount reason bind
- P04 payment refactor props
- P05 floorplan release
- P07 kiosk cash decouple
- P08 KDS release rule
- P09 after commit dispatch
- K01 routing legacy
- K02 order type explicit
- K03 quote pricing pin
- K04 payment UX offline
- K06 offline waiting UX
- K07 wizard unify
- K08 global errors

NOT COMPLETE:
- K05 payment confirm WS: `BLOCKED_SKIP`
- P06 park TTL: `BLOCKED_SKIP`
- K09 POS realtime kiosk visibility: `SCOPE_PRESSURE`

## Recommended Finish Plan

1. Replan and execute K09 with expanded allowlist for outbox listeners and event contracts.
2. Fix Playwright command/config mismatch so canonical Wave 2 commands collect the intended specs.
3. Stabilize `tests/e2e/pos/tacos-4-viandes-cash-flow.spec.ts` by seed fixture or env-driven regex in CI.
4. Investigate admin login flake in `tests/e2e/06-staff-only-routing.spec.js`.
5. Decide gates for K05 and P06: either approve and implement, or document explicit release exclusion.
6. Rerun full gate matrix:
   - targeted PHPUnit
   - targeted Vitest
   - `npx playwright test --config tests/Playwright`
   - `npx playwright test`
7. Only then mark Wave 1 + Wave 2 as `GREEN_FULL`.

## Final Classification

`CODE_QUALITY_CORE: MOSTLY_GREEN`

`TEST_EVIDENCE: MIXED`

`PLAYWRIGHT_LIVE: RED`

`RELEASE_READINESS: NEEDS_REWORK`
