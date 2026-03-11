---
description: 
alwaysApply: true
---

---
description: 
alwaysApply: true
---

# AI Project Operating Instructions AGENTS.md

## Mission
This repository follows a disciplined AI-assisted development workflow for a complex restaurant/POS SaaS system.

The product includes:
- admin and web POS
- kiosk ordering
- kitchen display system
- customer/order status display
- future SaaS evolution
- strong operational consistency across devices

## Source of truth
Always read and respect these files before important decisions:

- README.md
- docs/ARCHITECTURE.md
- docs/API_MAP.md
- docs/AUTHZ_MATRIX.md
- docs/ORDER_FLOW.md
- docs/DEVICE_FLOW.md
- docs/BUSINESS_RULES.md
- docs/CORE_MODULES.md
- docs/DATABASE_SCHEMA_CORE.md
- docs/ERROR_HANDLING.md
- docs/SECURITY_NOTES.md
- docs/TEST_PLAN.md
- docs/MASSIVE_TEST_PLAN.md
- docs/SAAS_VISION.md
- docs/CONTRIBUTING_QA_BOTS.md
- workflows/qa-loop.md
- workflows/task-routing.md
- workflows/report-format.md
- workflows/task-status.md
- reports/README.md

## Agent role model

### Claude (Architect & Reviewer)
Responsibilities:
- Architecture decisions and reasoning
- Root-cause analysis and debugging
- Planning with explicit test strategy
- Final review of implementation quality
- Risky refactors and cross-module decisions
- Auth/sync/pricing/state logic analysis
- Determines test type in plan: Kimi-test / Anti-Gravity / No-test

### Kimi (Builder & Tester)
Responsibilities:
- Localized implementation following Claude's plan
- UI, CRUD, simple wiring, repetitive code
- Execute unit/integration tests (PHPUnit, Jest, Vitest)
- Run linter and format checks
- Write execution summary with test results
- Limited-scope patches

### Anti-Gravity (E2E & Critical QA)
Responsibilities:
- E2E testing (browser, complex flows)
- Critical integration testing
- Functional exploration
- Structured QA reporting
- Only invoked when Claude's plan specifies "Anti-Gravity test"

### Cursor
Orchestration environment

## Mandatory workflow

### Normal Cycle (90% of cases)
1. **Human** requests feature/fix
2. **Claude** analyzes and writes plan in `reports/planning/latest.md`
   - Plan MUST specify test type: "Kimi-test" / "Anti-Gravity" / "No-test"
3. **Human** validates plan (GO / MODIFY / STOP)
4. **Kimi** implements following the plan
5. **Kimi** executes tests if plan says "Kimi-test" (PHPUnit, Jest, etc.)
6. **Kimi** writes execution summary in `reports/execution/latest.md` with test results
7. **Claude** reviews implementation and test results
8. **Claude** writes review in `reports/review/latest.md` with verdict: APPROVED / NEEDS_FIX / NEEDS_ANTIGRAVITY
9. **Human** validates final result

### Anti-Gravity Cycle (10% of cases - critical tests only)
1. **Claude's plan** specifies "Anti-Gravity test" OR **Claude's review** says "NEEDS_ANTIGRAVITY"
2. **Human** explicitly requests Anti-Gravity test
3. **Anti-Gravity** executes E2E/browser/critical tests
4. **Anti-Gravity** writes report in `reports/antigravity/latest.md`
5. **Claude** analyzes report → back to Normal Cycle step 2

## Task routing rules

### Use Claude for:
- Architecture decisions
- Synchronization logic
- Auth/authz
- Pricing integrity
- Risky refactors
- Bug root-cause analysis
- Cross-module decisions
- Order lifecycle logic
- State consistency
- Planning (with test strategy)
- Final review

### Use Kimi for:
- Localized code changes
- UI implementation
- CRUD endpoints
- Simple wiring
- Repetitive code generation
- Limited-scope patches
- Unit/integration testing (PHPUnit, Jest, Vitest)
- Linting and formatting

### Use Anti-Gravity for:
- E2E testing (browser automation)
- Complex integration flows
- Critical business scenarios
- Multi-device testing
- Performance testing
- Only when explicitly requested or when Claude's plan specifies it

## Repository behavior rules
1. Always read relevant docs before proposing or implementing a change.
2. Treat existing docs and workflow files as required operational context.
3. Do not change code outside the requested scope.
4. Do not touch unrelated modules.
5. Do not modify architecture casually.
6. Do not bypass server-side validations, pricing recalculation, authorization checks, or state transition rules.
7. Preserve the existing business domain language.
8. Respect all boundaries between:
   - admin
   - manager/cashier
   - kiosk machine
   - kitchen display
   - frontend/customer flows
9. Respect the documented order flow and device flow.
10. If a change affects auth, sync, pricing, device behavior, or order states, explicitly mention the risk and propose tests.

## Implementation rules
1. Prefer small diffs.
2. Prefer the simplest working change consistent with the architecture.
3. Reuse existing services, controllers, patterns, and naming conventions where possible.
4. If existing code is inconsistent, point it out before broad cleanup.
5. Do not introduce new dependencies unless necessary and justified.
6. If a task is large, first produce a plan, then implement in phases.
7. After implementation, summarize:
   - files changed
   - why they changed
   - risks
   - test results (if Kimi-test)

## Testing rules
1. **Claude decides test type in the plan**:
   - "Kimi-test": Unit/integration tests (PHPUnit, Jest, Vitest)
   - "Anti-Gravity": E2E/browser/critical tests
   - "No-test": Trivial changes (docs, comments, formatting)

2. **Kimi executes "Kimi-test"**:
   - Run PHPUnit for backend changes
   - Run Jest/Vitest for frontend changes
   - Run linter (phpcs, eslint)
   - Include test results in execution summary

3. **Anti-Gravity executes "Anti-Gravity" tests**:
   - E2E browser testing
   - Complex integration scenarios
   - Critical business flows
   - Generate detailed QA report

4. Prioritize tests for:
   - kiosk auth
   - pricing integrity
   - order creation
   - state transitions
   - KDS flows
   - OSS/display flows
   - authorization boundaries

## Operational output rules
- Planning output goes to `reports/planning/latest.md` (with test type specified)
- Execution summary goes to `reports/execution/latest.md` (with test results if applicable)
- Review output goes to `reports/review/latest.md` (with verdict)
- QA findings come from `reports/antigravity/latest.md` (only when Anti-Gravity is invoked)
- Use the report format defined in `workflows/report-format.md`

## Behavior in uncertainty
- If docs and code disagree, say so explicitly.
- If code and reports disagree, investigate first.
- If the change is risky, stop and propose a safer phased approach.
- If uncertain about test type, default to "Kimi-test" for safety.

## Definition of good output
A good result is:
- scoped
- consistent with docs
- easy to review
- safe to test
- explicit about risk
- explicit about test strategy
- explicit about next steps

## Workflow autonomy
This workflow is semi-autonomous, not fully autonomous.
Agents must automatically read the relevant project files and latest operational reports,
but the human developer remains the final authority and explicitly validates each major cycle.

## Definition of success
- architecture preserved
- business rules respected
- authorization intact
- no unrelated regressions
- tests passed (if applicable)
- work easy to review
- clear next step

Before making important changes, first summarize the current architecture understanding in 5-10 lines.
