# Codex — Supplemental Central Sync Orchestration Audit — 2026-04-30

## Verdict

`SUPPLEMENTAL_AUDIT_VERDICT: PASS_WITH_CORRECTIONS_TO_CLAUDE_API_ORCHESTRATION`

`NEXT_EXECUTION_DECISION: START_VA_SYS_00_THEN_01_05`

This audit reviews the Cursor/Claude API return and reinforces the final orchestration for the central data/sync/product-management system.

## What Cursor Added Correctly

The Cursor side added a useful three-layer audit harness:

1. A stronger external audit prompt:
   - `reports/audit/_CENTRAL_SYNC_ORCHESTRATION_CLAUDE_AUDIT_PROMPT_2026-04-30.txt`
   - forces a dedicated API vs MCP section;
   - forces a final contractual closeout.

2. API runner fallback:
   - `scripts/run-messages-api-audit.mjs`
   - supports a second API turn when the final closeout is missing.

3. Closeout validator:
   - `scripts/validate-master-audit-closeout.mjs`
   - validates the final `MASTER_AUDIT_VERDICT`, `SOFTWARE_DECISION`, `NEXT_CODEX_MISSION` block.

This is directionally correct: Claude API can be used as external adversarial reasoning when terminal Claude is blocked, but only if the prompt contains the actual context and the output is mechanically validated.

## Corrections Codex Applied

### 1. Closeout Must Be Last Three Lines

The original validator accepted the contractual block anywhere in the Markdown. That is weaker than the prompt, which requires the three final lines.

Fixed:

- `scripts/validate-master-audit-closeout.mjs` now checks the last three non-empty lines.
- `scripts/run-messages-api-audit.mjs::hasMasterCloseout()` now uses the same strict last-three-lines rule.
- Local strict test PASS:
  - compliant temporary file -> `closeout_ok`
  - same block followed by `extra` -> exit 1

### 2. Canonical Software Decision Is `READY_FOR_VA_SYS_00_05_EXECUTION`

The plan and tasklist include `VA-SYS-00`, so the previous `READY_FOR_VA_SYS_01_05_EXECUTION` value was semantically incomplete.

Fixed:

- prompt now asks for `SOFTWARE_DECISION: READY_FOR_VA_SYS_00_05_EXECUTION | HOLD`
- second-turn API closeout asks for `READY_FOR_VA_SYS_00_05_EXECUTION`
- validator accepts only `READY_FOR_VA_SYS_00_05_EXECUTION` or `HOLD`

### 3. Documentation Updated

- `docs/orchestration/ORCAI_AUDIT_ENDPOINT.md` now documents that the validator checks the final three non-empty lines, not just presence anywhere.

## Audit of Claude API Return

Claude API reached the right macro-verdict:

- `MASTER_AUDIT_VERDICT: REWORK`
- core sync is solid, but the complete system is not closed while VA-SYS-00..05 are open.

However, the returned orchestration needs correction before execution.

### Correct

| Claim | Codex Assessment |
| --- | --- |
| Core sync/data PASS is not equivalent to whole system PASS | Correct |
| VA-SYS-00..05 must be executed before final software close | Correct |
| API/outbox/realtime is the right runtime protocol, not MCP | Correct |
| Full dashboard-to-kiosk/POS/KDS E2E is the decisive missing proof | Correct |

### Needs Correction

| Claude API Claim | Correction |
| --- | --- |
| VA-SYS-00 = migrations/schema/seed foundations | Wrong relative to active tasklist. VA-SYS-00 is scope lock / hardware deferral gate note. |
| Stock decrement not proven | Overstated. `tests/Feature/Stock` passed 21 tests, including POS/Kiosk decrement and 50-attempt stress guard. Remaining need is full Dashboard-created product E2E, not base stock decrement. |
| Outbox idempotence not proven | Overstated. `tests/Feature/Outbox` passed 14 tests, including concurrent worker/dedupe and production-like retry/rescue. Remaining need is ordering/SLA in staging and full dashboard mutation flow. |
| Photos not proven | Partially overstated. Product photo authz and invalidation are covered by Catalog tests, but full browser dashboard upload-to-kiosk/POS visibility remains unproven. |
| P0 list includes already-covered foundations | Reclassify: many are P1/E2E coverage gaps, not current P0 regressions. |

## Current Truth Table

| Domain | Status | Proof / Gap |
| --- | --- | --- |
| Product-level rupture | PASS_LOCAL | VA-SYS-06 + Stock/Menu/Pricing tests |
| Choice-level wizard rupture | PASS_LOCAL | `ChoiceAvailabilityResolver`, Pricing tests, POS/Kiosk JS tests |
| Central authz | PASS_LOCAL_STRONG | VA-SYS-07B matrix |
| Outbox/realtime local | PASS_RUNTIME_LOCAL_STRONG | VA-SYS-08 + C3 Playwright |
| Docs/memory/runbook | PASS_DOCS_MEMORY | VA-SYS-09 with adversarial rework |
| Final critical validation pack | PASS_CORE_SYNC_VALIDATION_WITH_REMAINING_SYSTEM_GATES | VA-SYS-10 |
| Dashboard workflow selectors | NOT_VALIDATED | VA-SYS-01 |
| Request contract hardening around catalog/composer operations | NOT_VALIDATED | VA-SYS-02 |
| Wizard runtime contract formal closure | NOT_VALIDATED | VA-SYS-03 |
| Dashboard builder operator UX | NOT_VALIDATED | VA-SYS-04 |
| Dashboard-created product full E2E | NOT_VALIDATED | VA-SYS-05 |
| Hardware/provider | DEFERRED | UAT only |

## Final Intelligent Orchestration

### VA-SYS-00 — Scope Lock / Hardware Deferral

Objective:

- Write a short gate note that freezes the current software-only perimeter.
- State explicitly that TPE, printer, kiosk OS lockdown, provider cloud and Google Maps live are out of scope until after software close.
- Normalize the API audit closeout contract.

Files:

- `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md`
- `docs/gates/GATE_VERSION_A_SOFTWARE_SCOPE_2026-04-30.md`
- `reports/audit/_CENTRAL_SYNC_ORCHESTRATION_CLAUDE_AUDIT_PROMPT_2026-04-30.txt`
- `scripts/run-messages-api-audit.mjs`
- `scripts/validate-master-audit-closeout.mjs`
- `docs/orchestration/ORCAI_AUDIT_ENDPOINT.md`

Tests:

- `node --check scripts/run-messages-api-audit.mjs`
- `node --check scripts/validate-master-audit-closeout.mjs`
- validator positive/negative temp cases
- `git diff --check` scoped

PASS:

- Gate file exists.
- Audit closeout contract is strict and canonical.
- Tasklist moves VA-SYS-00 to PASS_SCOPE_LOCK.

REWORK:

- Hardware accidentally treated as software blocker.
- Validator accepts closeout not placed at final three lines.

### VA-SYS-01 — Dashboard Workflow Discovery

Objective:

- Map the real dashboard routes, components and selectors for product/category/photo/stock/composer workflows before writing brittle Playwright.

Read:

- `routes/api.php`
- `resources/js/router`
- `resources/js/components/admin/items`
- `resources/js/components/admin/settings/ItemCategory`
- `resources/js/components/admin/composer*` / composer components
- `docs/sync/CENTRAL_MANAGEMENT_RUNBOOK.md`

Create:

- `reports/audit/VA_SYS_01_DASHBOARD_WORKFLOW_DISCOVERY_2026-04-30.md`
- `tests/e2e/helpers/central-management-selectors.js` if selectors are stable enough

PASS:

- Selector map covers product CRUD, category CRUD, image upload, stock toggle, composer profile and publish.
- Missing `data-testid` needs are listed precisely.

REWORK:

- Report is generic or cannot drive VA-SYS-05.

### VA-SYS-02 — Composer / Catalog Request Contract Hardening

Objective:

- Lock API request behavior for product/category/photo/composer mutation edge cases.
- Include delete/disable semantics and stale payload rejection.

Targets:

- Admin item/category/variation/extra/addon controllers and requests.
- Composer profile/step requests.
- Pricing stale choice tests.

Tests to add or extend:

- product disable/delete projection test;
- category disable/delete projection test;
- composer publish without valid steps rejected or safely handled;
- stale category/product mutation cannot leak cross-branch;
- request rejects client-side price authority.

PASS:

- Mutations have deterministic API behavior.
- Historical snapshots remain readable.
- POS/Kiosk projections never expose deleted/disabled product as orderable.

### VA-SYS-03 — Wizard Runtime Contract Final

Objective:

- Formalize runtime behavior for:
  - no wizard product;
  - simple wizard product;
  - complex composer product;
  - stockable unavailable choices;
  - edit/restore stale cart.

Tests:

- PHPUnit projection/pricing tests.
- Vitest POS/Kiosk wizard tests.
- At least one browser-level smoke if selector map is ready.

PASS:

- Product without wizard adds directly.
- Product with composer opens correct steps.
- Unavailable choices are visible but not selectable/orderable.
- Backend rejects stale or forged selections.

### VA-SYS-04 — Dashboard Builder UX Hardening

Objective:

- Make the dashboard builder operator-safe before full E2E.

Targets:

- composer/profile editor components;
- item/category forms;
- photo upload UI;
- stock/availability toggle UI.

Tests:

- Vitest dashboard validation specs;
- build;
- targeted PHP regression.

PASS:

- Common bad configs cannot be published silently.
- Errors are visible, translated or at least deterministic.
- UX supports product without wizard and product with wizard.

### VA-SYS-05 — Full Dashboard-To-Runtime E2E

Objective:

- Prove the central system as an operator would use it:
  dashboard creates/edits product + category + photo + stock + composer -> publish -> Kiosk/POS see it -> order -> KDS/OSS receive -> stock decrements and rupture syncs.

Create:

- `tests/e2e/va-sys-05-central-management-e2e.spec.js`
- `reports/antigravity/va-sys-05-central-management-e2e.json`

Run:

```bash
npx playwright test tests/e2e/va-sys-05-central-management-e2e.spec.js --repeat-each=2 --retries=0
php artisan test tests/Feature/Stock
php artisan test tests/Feature/Composer
php artisan test tests/Feature/Catalog
php artisan test tests/Feature/Menu
npm run production
```

PASS:

- 2/2 Playwright runs green without retry.
- All backend regressions green.
- Artifact includes order id, product id, category id, timing, stock before/after.

REWORK:

- Any manual-only step in the flow.
- Any unstable selector not fixed in VA-SYS-01/04.
- Any product visible in one surface but not the other without documented channel rule.

## Final Execution Order

1. `VA-SYS-00` now: governance/scope/audit closeout normalization.
2. `VA-SYS-01`: discovery and selectors.
3. `VA-SYS-02` and `VA-SYS-03`: can run in sequence or parallel if write scopes are separated.
4. `VA-SYS-04`: dashboard UX hardening.
5. `VA-SYS-05`: decisive full E2E.
6. Re-run `VA-SYS-10`.

## Recommended Next Codex Mission

`NEXT_CODEX_MISSION: VA-SYS-00`

Reason: It is small, removes ambiguity, and makes every later audit output mechanically enforceable before the heavier dashboard work starts.
