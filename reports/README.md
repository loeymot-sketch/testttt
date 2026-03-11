# Reports

This folder stores the operational memory of the AI-assisted development workflow.

## Structure

- `reports/antigravity/`
  - QA test reports written by Anti-Gravity (E2E/critical tests)
- `reports/planning/`
  - Plans and task breakdowns written by Claude
- `reports/execution/`
  - Execution summaries written by Kimi after implementation
- `reports/review/`
  - Review reports written by Claude after Kimi implementation

## Workflow (Two Loops)

### Normal Loop (90% of cases - Fast iteration)
1. **Claude thinks** (Planning with test strategy)
2. **Human validates plan**
3. **Kimi builds** (Implementation)
4. **Kimi tests** (if "Kimi-test" specified)
5. **Claude reviews** (Review with verdict)
6. **Human validates** (Final approval)

### Anti-Gravity Loop (10% of cases - Critical validation)
1. **Claude plans** specifies "Anti-Gravity test"
2. **Human requests Anti-Gravity**
3. **Anti-Gravity tests** (E2E/browser/critical)
4. **Anti-Gravity reports**
5. **Claude analyzes** → Back to Normal Loop

## Naming
Use clear names:
- `report-001.md` (Anti-Gravity QA reports)
- `plan-001.md` (Claude planning)
- `execution-001.md` (Kimi execution)
- `review-001.md` (Claude review)

Or timestamped names:
- `2026-03-10-report-001.md`

## Reading Priority (latest.md pattern)

When continuing work, agents should read in this order:
1. `reports/antigravity/latest.md` (only if Anti-Gravity was invoked)
2. `reports/planning/latest.md` (Claude's current plan)
3. `reports/execution/latest.md` (Kimi's implementation)
4. `reports/review/latest.md` (Claude's review with verdict)
5. Relevant files in `docs/`
6. Workflow files in `workflows/`

**Note**: Numbered files (`report-001.md`, `plan-001.md`, etc.) remain for historical traceability but `latest.md` is the primary entry point.

## Test Strategy

Claude MUST specify test type in every plan:
- **"Kimi-test"**: Unit/integration tests (PHPUnit, Jest) - executed by Kimi
- **"Anti-Gravity"**: E2E/browser tests - executed by Anti-Gravity
- **"No-test"**: Trivial changes (docs, formatting)

## Verdict Types (in Review)

Claude's review includes one of:
- **APPROVED**: Implementation correct, ready for human validation
- **NEEDS_FIX**: Issues found, Kimi should fix
- **NEEDS_ANTIGRAVITY**: Critical validation needed, Anti-Gravity should test

This ensures continuity between QA, planning, implementation, review, and validation.
