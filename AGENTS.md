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
- Claude = architecture, reasoning, debugging, planning, root-cause analysis, review, risky refactors, auth/sync/pricing/state logic
- Kimi = localized implementation, UI, CRUD, repetitive code generation, limited-scope patches
- Anti-Gravity = QA, flow testing, structured reporting, functional exploration
- Cursor = orchestration environment

## Mandatory loop
1. Anti-Gravity tests and writes a report in `reports/antigravity/`
2. Cursor reads the latest report
3. Claude analyzes and writes a plan in `reports/planning/`
4. Tasks are executed according to `workflows/task-routing.md`
5. Execution notes are written in `reports/execution/`
6. Anti-Gravity retests
7. Human developer validates the next step

## Core operating rules
1. Preserve architecture, business rules, and authorization boundaries.
2. Do not modify unrelated modules.
3. Do not bypass validation, pricing recalculation, state transitions, or auth checks.
4. Prefer small, reversible, well-scoped changes.
5. If a task is large, analyze first and split into phases.
6. If docs and code disagree, say so explicitly before changing anything.
7. If a change affects sync, pricing, auth, KDS, OSS, or order lifecycle, explain the risk and suggest tests.
8. Planning output belongs in `reports/planning/`
9. Execution summaries belong in `reports/execution/`
10. QA findings come from `reports/antigravity/`

## Definition of good output
A good result is:
- scoped
- consistent with docs
- easy to review
- safe to test
- explicit about risk
- explicit about next steps.

## Multi-agent operating model

This repository follows a strict multi-agent operating loop.

### Official role sequence
1. Claude thinks
2. Kimi builds
3. Claude reviews
4. Anti-Gravity tests
5. Human validates

### Report flow
- Anti-Gravity writes QA reports to `reports/antigravity/`
- Claude reads the latest Anti-Gravity report and writes plans to `reports/planning/`
- Kimi reads the latest approved planning file and implements only scoped tasks
- Kimi or the orchestrating agent writes execution notes to `reports/execution/`
- Claude reviews the execution against docs, business rules, architecture, and risks
- Anti-Gravity retests based on the latest approved implementation

### Critical rule
Agents must not skip the report chain.
Every important change must be traceable through:
1. a QA report
2. a planning file
3. an execution summary
4. a retest

### Responsibility boundaries
- Claude does not perform broad blind implementation when a local task can be delegated
- Kimi does not make architecture decisions
- Anti-Gravity does not modify application code
- The human developer approves continuation of the loop.
