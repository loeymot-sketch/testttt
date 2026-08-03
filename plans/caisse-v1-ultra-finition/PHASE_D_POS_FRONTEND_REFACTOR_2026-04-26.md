# PHASE D — POS Frontend Refactor

Status: BLOCKED_PHASE_A_UNSIGNED
Owner: Codex after backend safety is stable.

## Goal

Reduce POS frontend risk without changing business truth. The frontend must become more testable and recoverable while keeping backend pricing SSOT.

## Tasks

### D.1 `CV1-REFACTOR-POSCOMPONENT-SPLIT`

Objective: split `PosComponent.vue` into bounded components.

Target components:
- `PosLayout.vue`
- `PosCatalog.vue`
- `PosCart.vue`
- `PosCheckout.vue`
- `PosRealtimeStatus.vue`
- `PosParkedDrawer.vue`

Allowlist:
- POS Vue components under `resources/js/components/admin/pos/`
- POS component tests
- imports/router registration if required

Forbidden:
- backend pricing logic
- new frontend total authority
- payment ledger scope expansion

Mandatory tests:
- existing POS Vitest suite
- `npx playwright test tests/e2e/02-pos-cash.spec.js tests/e2e/05-pos-card.spec.js`

Exit criteria:
- no component above 700 lines unless explicitly justified.
- cart/payment flow behavior unchanged.
- bundle size change measured and within accepted range or documented.

### D.2 `CV1-TESTS-VITEST-POS-STORES`

Objective: add direct tests for `posCart`, `posOrder`, and `posParked`.

Allowlist:
- `tests/js/pos*.spec.js`
- `resources/js/store/modules/posCart.js`
- `resources/js/store/modules/posOrder.js`
- `resources/js/store/modules/posParked.js`

Mandatory tests:
- `npx vitest run tests/js/posCart*.spec.js tests/js/posOrder*.spec.js tests/js/posParked*.spec.js`

Exit criteria:
- localStorage scoping by branch/user covered.
- idempotency key behavior covered.
- parked recall pruning covered.

### D.3 `CV1-FRONT-POS-ERROR-BOUNDARY`

Objective: add Vue error boundary to preserve cart and prevent mid-payment UI crash.

Allowlist:
- POS layout/root component
- POS error component
- POS error tests

Mandatory tests:
- `npx vitest run tests/js/posErrorBoundary.spec.js`
- targeted Playwright scenario if added

Exit criteria:
- cart is preserved after simulated component error.
- operator sees actionable error state.
- no double submit after recovered error.

### D.4 `CV1-FRONT-POS-QUOTE-COUNTDOWN`

Objective: expose quote expiry countdown and refresh behavior.

Allowlist:
- `PaymentComponent.vue`
- POS checkout component after D.1
- quote-related tests

Mandatory tests:
- `npx vitest run tests/js/posPaymentQuoteCountdown.spec.js`

Exit criteria:
- payment disabled after quote expiry.
- auto-refresh occurs before expiry if safe.
- backend remains final authority.

### D.5 `CV1-FRONT-OBSERVABILITY-SENTRY`

Objective: client-side structured breadcrumbs for critical POS actions.

Status: PARTLY_HUMAN because external key/project decision is required.

Exit criteria:
- no secrets in repo.
- breadcrumbs are no-op safe when Sentry disabled.
- POS critical actions traced.

### D.6 `CV1-CUTOVER-LEGACY-BUNDLES-DECISION`

Objective: purge or explicitly sign legacy `pos-wizard.js` shim.

Status: BLOCKED_HUMAN_CUTOVER_GATE.

Exit criteria:
- either shim signed in `docs/gates/GATE_LOG.md`, or legacy script injection removed.
- `bash scripts/lint-fk-bundle-legacy.sh strict` PASS.
