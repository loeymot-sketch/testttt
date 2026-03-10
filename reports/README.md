# Reports

This folder stores the operational memory of the AI-assisted development workflow.

## Structure

- `reports/antigravity/`
  - QA test reports written by Anti-Gravity
- `reports/planning/`
  - plans and task breakdowns written by Claude
- `reports/execution/`
  - execution summaries written after implementation

## Workflow

The mandatory cycle is:
1. Claude thinks (Planning)
2. Kimi builds (Implementation)
3. Claude reviews (Review execution)
4. Anti-Gravity tests (QA/Retest)
5. Human validates (Approval)

## Naming
Use clear names:
- `report-001.md`
- `plan-001.md`
- `execution-001.md`

Or timestamped names:
- `2026-03-10-report-001.md`

## Reading priority

When continuing work, agents should read in this order:
1. the latest file in `reports/antigravity/`
2. the latest file in `reports/planning/`
3. the latest file in `reports/execution/`
4. the relevant files in `docs/`
5. the workflow files in `workflows/`

This ensures continuity between QA, planning, implementation, and review.
