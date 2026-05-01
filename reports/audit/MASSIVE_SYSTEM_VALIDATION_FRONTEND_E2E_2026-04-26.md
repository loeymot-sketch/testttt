# Frontend + Browser E2E Validation Report

Date: 2026-04-26  
Scope: Vue/Vitest, kiosk UI contracts, POS/Kiosk browser flows, KDS browser flow, frontend lint/performance/i18n.

## Verdict

`FRONTEND_E2E_VERDICT: FUNCTIONAL_PASS_WITH_RELEASE_QUALITY_REWORK`

Vitest and Playwright are green. Release quality still needs i18n cleanup and kiosk shell budget work.

## Test Evidence

| Command | Result | Evidence |
| --- | ---: | --- |
| `npx vitest run` | PASS | `126 files / 853 tests passed` |
| `npx playwright test` | PASS | `35 tests passed` |
| `bash scripts/lint-fk-bundle-legacy.sh strict` | PASS with warning | kiosk legacy POS wizard references remain |
| `npm run perf:bundle-check` | FAIL | `kiosk-shell.js` exceeds budget |
| `npm run i18n:audit` | FAIL | many missing locale keys |

## Browser Coverage Observed

Playwright passed:

- auth refresh/F5 POS behavior;
- POS cash full cycle;
- POS card surface;
- Kiosk login/config/navigation;
- KDS login/surface;
- staff-only routing;
- seed-adapted tacos cash receipt flow;
- KDS multi-screen contract;
- kiosk error routing;
- kiosk legacy redirect;
- kiosk offline waiting;
- kiosk order-type-required;
- kiosk quote-pin;
- POS receives kiosk realtime contract;
- offline kiosk refuses CB/TR payment affordances.

## Frontend Test Warnings

Vitest passes but emits noise that should be reduced before long-term CI hardening:

- kiosk pricing preview warnings when axios is unavailable in tests;
- Vuex unknown action/getter warnings in kiosk restyle tests;
- missing i18n keys in kiosk cart restyle tests;
- expected happy-dom network errors for external/fetch test cases;
- POS component warnings for unresolved `router-link`/`vue-select` in isolated tests;
- receipt increment network-down logs in expected fallback tests.

These are not red tests today, but they make future regression triage harder.

## Release Quality Findings

### P1 — i18n audit is red

Vue missing keys:
- fr: 492
- en: 26
- ar: 57
- de: 126
- bn: 127

Laravel missing keys:
- fr: 20
- en: 20
- ar: 25
- de: 36
- bn: 33

Decision needed: either declare a single-locale V1 release policy or make i18n cleanup a release gate.

### P1 — Kiosk shell bundle budget is red

`kiosk-shell.js: 431KB > budget 350KB`.

Functional E2E passes, so this is performance/release quality, not core behavior failure.

### P2 — Legacy POS wizard references remain in kiosk bundles

Strict legacy lint exits 0 but warns:

- `public/js/kiosk.js`
- `public/js/kiosk-wizard.js`

Decision needed: enforce `FK_LEGACY_STRICT_POS_WIZARD=1` at release cutover, or explicitly accept the shim for V1.

## Frontend Release Gate

Frontend can be considered functionally usable now, but release readiness requires:

1. Decide i18n policy.
2. Bring `kiosk-shell.js` under budget or approve a temporary budget exception.
3. Decide POS wizard shim/cutover policy.
4. Re-run Vitest and Playwright after any changes.

