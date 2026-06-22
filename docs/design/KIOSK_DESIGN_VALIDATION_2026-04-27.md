# Kiosk Design Validation — D1 — 2026-04-27

Status: `PASS_LOCAL_SMOKE_5X`

Implemented:
- Playwright design audit spec: `tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js`.
- Shared helper: `tests/e2e/design/_shared/design-audit-helpers.js`.
- Screenshots output: `tests/e2e/__screenshots__/kiosk/`.
- JSON run output: `reports/antigravity/d1-kiosk-design-audit.json`.

Covered screens in this first executable D1 harness:
- idle
- categories
- cart
- payment empty-cart guard
- cash instruction
- network error
- menu unavailable error
- product removed error
- payment refused error

Assertions:
- DOM renders with text.
- No server error page.
- No critical JS console/page error.
- `axe-core` WCAG 2A/2AA scan is executed and captured.
- Touch target and `data-testid` inventory is captured in JSON.
- Screenshots are captured at 1080x1920 and 1920x1080.

Validation run:
- `npx playwright test tests/e2e/design/kiosk/d1-kiosk-design-audit.spec.js tests/e2e/design/pos/d2-pos-design-audit.spec.js tests/e2e/design/kds/d3-kds-oss-design-audit.spec.js --project=chromium --repeat-each=5 --retries=0`
- Result: `15 passed (5.4m)` overall; kiosk spec ran 5/5 green.
- Latest JSON: `reports/antigravity/d1-kiosk-design-audit.json`
- Kiosk latest serious/critical axe count: `0`.

Known remaining work before strict D1 PASS:
- Add wizard, loyalty, upsell, waiting and confirmation stateful fixtures.
- Convert smoke screenshots into stable visual baselines if the team wants snapshot gating.
- Tighten `axe-core` threshold from captured audit to zero serious/critical after any UI corrections.
- Repeat full D1 suite 5x after selectors/state fixtures are complete.

Current D1 decision: `PASS_LOCAL_SMOKE_5X`. Strict D1 full release still requires stateful wizard/loyalty/upsell/waiting/confirmation fixtures and screenshot baseline gating.
