# D0 Bug Backlog — Production Live Validation — 2026-04-27

Verdict: `NO_NEW_P0_PRODUCT_BLOCKER_IN_D0`, `D_MISSIONS_REQUIRED_BEFORE_LIVE`

This backlog includes active bugs, active validation gaps, and resolved historical issues that must remain covered by regression tests.

| ID | Severity | Component | File:line | Description | Action |
| --- | --- | --- | --- | --- | --- |
| D0-BUG-001 | RESOLVED-P0 | Kiosk post-payment | `resources/js/components/frontend/kiosk/KioskWaitingComponent.vue`, `KioskConfirmationComponent.vue` | Historical bug: kiosk blocked on waiting/confirmation after simulated payment because no KDS status progression occurred. C0 fixed route from paid/pending-counter to confirmation and auto-return idle. | Keep C0 tests in D4 regression pack. |
| D0-BUG-002 | P1 | Artisan route introspection | `php artisan route:list --path=api` | Route list fails with `ReflectionException: Class "App\\Http\\PaymentGateways\\Gateways\\Senangpay" does not exist`. Source route inventory is usable, but automated route matrix D11 is blocked until fixed or excluded. | Create cleanup/hardening task before D11 route matrix automation. |
| D0-BUG-003 | P1 | Design coverage | `tests/e2e/design/**` absent | D1-D3 visual regression suites and screenshots are not created yet. | D1/D2/D3 must add design E2E harness and baselines. |
| D0-BUG-004 | P1 | Kiosk selectors | `resources/js/components/frontend/kiosk/KioskLoginComponent.vue` | Login screen has no preflight-observed `data-testid`, making robust browser tests weaker. | D1 may add scoped testids if visual/functional tests require them. |
| D0-BUG-005 | P1 | POS selectors | `resources/js/components/admin/pos/*.vue` | POS testid coverage is sparse compared with kiosk; only receipt and counter-collect detail testids were observed. | D2/D5 should add stable selectors or use accessible role locators with care. |
| D0-BUG-006 | P1 | KDS selectors | `resources/js/components/admin/kitchenDisplaySystem/KitchenDisplaySystemComponent.vue` | KDS has realtime handlers but sparse preflight-observed testids. | D3/D6 should add or locate stable ticket/status selectors. |
| D0-BUG-007 | P1 | OSS selectors | `resources/js/components/admin/orderStatusScreen/*.vue` | OSS has sparse stable selectors for queue states. | D3 should add visual-ready stable locators if needed. |
| D0-BUG-008 | P1 | WebSocket live validation | `resources/js/services/eventContract.js`, `resources/js/bootstrap.js` | Unit/Vitest/feature coverage exists, but D7 live multi-page realtime latency evidence is missing. | D7 must run with local broadcast server or explicit mocked channel strategy documented. |
| D0-BUG-009 | P1 | KDS lifecycle | `KitchenDisplaySystemComponent.vue` | PENDING_COUNTER badge and transitions are covered in integration, but not 10x KDS browser visual/status flow. | D6 run-many. |
| D0-BUG-010 | P1 | Dashboard management | `resources/js/components/admin/items/**`, `settings/ItemCategory/**` | Product/category/photo/stock/composer manager workflows are not covered by a single browser process spec. | D9 dashboard management suite. |
| D0-BUG-011 | P1 | Stock stress | `app/Services/StockService.php`, `tests/Feature/Stock/*` | Stock V2 has strong feature tests but no 100 parallel stress run attached to live validation. | D9 stress. |
| D0-BUG-012 | P1 | Queue stress | `orders.queue_number` migrations/tests | Queue uniqueness is tested, but D12/D5 should still prove cross-channel 50 parallel allocations under current DB. | D5/D12 stress. |
| D0-BUG-013 | P1 | Authz matrix | `routes/api.php` | Branch/authz tests are numerous but no exhaustive route x role x branch matrix exists. | D11 generator/matrix. |
| D0-BUG-014 | P1 | Pricing forge matrix | Pricing/quote request/services | Many forge tests exist, but D10 requires a consolidated 15-attack matrix x5. | D10. |
| D0-BUG-015 | P1 | Persistence full-day | Fiscal/audit/outbox | Fiscal/audit tests exist, but no D8 full-day mixed kiosk/POS/counter-deferred Z close scenario. | D8. |
| D0-BUG-016 | P1 | Chaos | Network/DB/clock | D12 chaos scenarios are largely absent from current test suite. | D12. |
| D0-BUG-017 | P2 | Counter-collect structure | `routes/api.php:686-735` | Counter-collect routes are implemented as inline closures. Functional, but less maintainable/test-addressable than a controller. | Post-UAT cleanup or D11 if route policy matrix needs controller metadata. |
| D0-BUG-018 | P2 | Composer permission governance | `database/seeders/ComposerPermissionsMinimalSeeder.php` | Previous Claude review noted grants to four roles vs earlier minimal two-role spec. | Human/product governance decision; not a live blocker if intentional. |
| D0-BUG-019 | P2 | POS live board | POS UI | API for pending counter-collect exists, but a broader POS live board aggregating POS+kiosk orders remains incomplete. | Product cleanup after D5/D7 if required for launch scope. |
| D0-BUG-020 | P2 | Broadcast snapshot assertion | Outbox payloads | Previous review noted `composition_snapshot` not explicitly asserted in every broadcast payload. | D8 payload contract extension. |
| D0-BUG-021 | P2 | Google Maps real validation | Delivery/geocode | Backend blocks geocode failure and fee forge, but real Google Maps behavior requires credentials/network UAT. | Hardware/real integration UAT after D10. |
| D0-BUG-022 | P2 | Hardware printer | POS receipt/fiscal | Local tests can assert sequence and print counters, not real printer behavior. | Hardware UAT. |
| D0-BUG-023 | P2 | TPE hardware | Kiosk/POS payment | Simulated card/counter flows pass; external terminal success/refusal/timeout needs hardware. | Hardware UAT after D4/D5. |
| D0-BUG-024 | P2 | Visual baselines storage | `tests/e2e/__screenshots__` | Required D1 screenshot baselines not present for all screens. | D1 creates baselines. |
| D0-BUG-025 | P2 | Browser console noise | WebSocket local | Prior local browser runs showed WS connection refused when broadcast server is not running. | D7 must set explicit realtime environment readiness. |
| D0-BUG-026 | P2 | Admin product photo path | Item image/composer | Feature tests cover image catalog refresh; dashboard browser upload path not complete. | D9 E2E upload. |
| D0-BUG-027 | P2 | Kiosk i18n browser coverage | Kiosk screens | JS i18n audits exist but not full route-level FR/EN browser checks. | D1. |
| D0-BUG-028 | P2 | POS i18n/browser coverage | POS screens | POS visual/i18n checks are not comprehensive. | D2. |
| D0-BUG-029 | P2 | KDS/OSS high-density display | KDS/OSS | No high-density screenshot/capacity baseline found. | D3. |
| D0-BUG-030 | P2 | Run-many evidence packaging | reports | C0-C2 have run-many reports; D1-D13 do not yet have unified run logs. | Each D mission must attach report and self-audit. |
| D0-BUG-031 | P2 | Safety-check pre-existing blocker | `reports/post_execute_latest.log` history | Historical reports mention `safety-check` blocked by pre-existing staged frozen `OrderService.php` outside scope. | Before product edits in D missions, inspect current git index and avoid touching frozen zones unless gate is present. |
| D0-BUG-032 | P2 | Route inventory generated from source | `routes/api.php` | Because `route:list` failed, D0 route inventory is source-based, not framework-resolved with middleware expansion. | Fix D0-BUG-002 before D11 if exact middleware expansion is required. |

## Immediate Routing

- Start D1/D2/D3 only if the aim is design/test harness creation.
- Start D4/D5 only after keeping C0 regression green.
- Start D7 only with explicit realtime server readiness or a documented fallback strategy.
- Start D11 after D0-BUG-002 is resolved or accepted as an exclusion.
