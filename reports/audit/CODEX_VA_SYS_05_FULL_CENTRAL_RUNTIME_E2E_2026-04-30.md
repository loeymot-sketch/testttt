# CODEX VA-SYS-05 — Full Central Management To Runtime E2E

Date: 2026-04-30
Executor: Codex cursor-session

## Verdict

VA_SYS_05_VERDICT: PASS_RUNTIME_LOCAL_STRONG

The central software/system flow is validated locally across dashboard visibility, menu projection, POS quote/order, KDS runtime visibility, stock decrement, and immutable composition history.

Hardware/provider checks remain deferred to final UAT.

## What Was Proven

Scenario: `central_management_to_runtime_projection_and_order`

1. Central product/category/composer data is persisted.
2. Admin dashboard product list shows the generated product.
3. Admin composer route renders the operator editor and stable step controls.
4. POS and kiosk menu projections expose the published composer profile.
5. POS quote accepts the composer selections and computes backend SSOT total.
6. POS order is created with variation + extra + addon composer choices.
7. KDS sees the order without manual reload.
8. Stock decrements from 8 to 7.
9. Order item `composition_snapshot` persists the chosen variation, extra, and addon names separately.

## Files Added Or Updated

- `tests/e2e/central-management-va-sys05.spec.js`
- `reports/antigravity/va-sys-05-central-management.json`
- `reports/antigravity/c3-runtime-multi-surface.json`
- `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md`
- `reports/audit/CODEX_VA_SYS_05_FULL_CENTRAL_RUNTIME_E2E_2026-04-30.md`

## Validation Matrix

PASS:

- `npx playwright test tests/e2e/central-management-va-sys05.spec.js --reporter=line` — 1 passed.
- `npx playwright test tests/e2e/central-management-va-sys05.spec.js --repeat-each=3 --reporter=line` — 3 passed.
- `npx playwright test tests/e2e/c3-runtime-multi-surface.spec.js --repeat-each=2 --reporter=line` — 4 passed.
- `php artisan test tests/Feature/Composer` — 24 passed.
- `php artisan test tests/Feature/Services/Menu/MenuProjectionComposerProfileTest.php` — 5 passed.
- `php artisan test tests/Feature/Services/Pricing/ComposerStepConstraintTest.php` — 13 passed.
- `npx vitest run tests/js/productComposerEditor.spec.js tests/js/kioskWizardGenericComposer.spec.js tests/js/posWizardComposerProfile.spec.js` — 15 passed.
- Scoped `git diff --check` — passed.

Existing support validation from VA-SYS-04:

- `npm run production` — passed.

## Runtime Evidence

`reports/antigravity/va-sys-05-central-management.json`:

- Verdict: `PASS_RUNTIME_LOCAL`
- Order total: `13`
- Stock after order: `7`
- Snapshot lines: variation `1`, extra `1`, addon `1`

`reports/antigravity/c3-runtime-multi-surface.json`:

- Verdict: `PASS_RUNTIME_LOCAL`
- Kiosk cash to KDS/POS/OSS: passed, KDS around `4879ms`, OSS around `3873ms`.
- POS to KDS/OSS: passed, KDS around `5882ms`, OSS around `4878ms`.

## Findings Closed During The Loop

- Local server was not running on port 8000. Started `php artisan serve --host=127.0.0.1 --port=8000`.
- Admin composer UI defaults to a global composer profile. This matches existing authz tests where Tenant Admin defaults to global profile and branch admin defaults to own branch.
- KDS displays POS order token/serial rather than the response queue number in this path. The test now waits for the central product name, which is the actual KDS-visible operational signal.
- `composition_snapshot` shape stores variation, extra, and addon names in separate arrays. The E2E now asserts the real immutable schema instead of a generic `line.name`.

## Invariants Checked

- Backend pricing SSOT: POS quote/order total came from backend quote, not frontend calculation.
- OrderStatus enum: assertions use JS enum import.
- Branch isolation: composer/authz regression suite passed; runtime fixture uses branch-resolved projection.
- Dispatch after commit: C3 runtime and composer publish sync suites passed.
- OrderService / FrontendOrderService parity: not edited in this mission.
- Frozen zones: not touched.

## Residual Scope

No software P0/P1 remains in VA-SYS-00..05 from this loop.

Still deferred by explicit scope gate:

- TPE physical payment terminal.
- Fiscal printer physical output.
- Kiosk OS lockdown on target hardware.
- Google Maps live provider conditions.
- Production network loss/reconnect on real devices.
