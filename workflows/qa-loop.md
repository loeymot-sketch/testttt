# QA Loop

This repository uses a strict AI-assisted development cycle with two distinct loops.

---

## Normal Loop (90% of cases - Fast iteration)

```
1. Human requests feature/fix
2. Claude analyzes and writes plan in reports/planning/latest.md
   → Plan MUST specify test type: "Kimi-test" / "Anti-Gravity" / "No-test"
3. Human validates plan (GO / MODIFY / STOP)
4. Kimi implements following the plan
5. Kimi executes tests if plan says "Kimi-test" (PHPUnit, Jest, etc.)
6. Kimi writes execution summary in reports/execution/latest.md
7. Claude reviews and writes reports/review/latest.md
   → Verdict: APPROVED / NEEDS_FIX / NEEDS_ANTIGRAVITY
8. Human validates final result
```

**When to use**: Most features, bug fixes, UI changes, CRUD, simple logic

**Test types**:
- "Kimi-test": PHPUnit, Jest, Vitest, linter
- "No-test": Docs, comments, trivial formatting

---

## Anti-Gravity Loop (10% of cases - Critical validation)

```
1. Claude's plan specifies "Anti-Gravity test"
   OR Claude's review says "NEEDS_ANTIGRAVITY"
2. Human explicitly requests Anti-Gravity test
3. Anti-Gravity executes E2E/browser/critical tests
4. Anti-Gravity writes report in reports/antigravity/latest.md
5. Claude analyzes report → writes new plan
6. Back to Normal Loop step 3
```

**When to use**:
- E2E browser testing
- Complex multi-device flows
- Critical business scenarios (payment, order lifecycle)
- Auth/authz integration
- Performance testing
- When Kimi tests fail repeatedly

---

## Human role
The human developer remains the final authority and decides whether to continue, revise, or stop a cycle.

## Important rule
No cycle is complete until:
- a plan exists (with test type specified)
- execution notes exist (with test results if applicable)
- review exists (with verdict)
- human validates

## Multi-agent operating model

### Official role sequence (Normal Loop)
1. Claude thinks and plans (with test strategy)
2. Human validates plan
3. Kimi builds
4. Kimi tests (if "Kimi-test")
5. Claude reviews
6. Human validates result

### Official role sequence (Anti-Gravity Loop)
1. Human requests Anti-Gravity test
2. Anti-Gravity tests
3. Anti-Gravity reports
4. Claude analyzes
5. Back to Normal Loop

### Report flow
- Claude writes plans to `reports/planning/latest.md` (with test type)
- Kimi writes execution notes to `reports/execution/latest.md` (with test results)
- Claude writes reviews to `reports/review/latest.md` (with verdict)
- Anti-Gravity writes QA reports to `reports/antigravity/latest.md` (only when invoked)

**Note**: Numbered files (`report-001.md`, `plan-001.md`, `execution-001.md`, `review-001.md`, etc.) remain for historical traceability but are not automatically loaded into AI context. Agents always read `latest.md` as the entry point.

### Critical rule
Agents must not skip the report chain.
Every important change must be traceable through:
1. a plan (with test strategy)
2. execution notes (with test results)
3. a review (with verdict)
4. human validation

### Responsibility boundaries
- Claude does not perform broad blind implementation
- Claude MUST specify test type in every plan
- Kimi does not make architecture decisions
- Kimi executes tests when plan says "Kimi-test"
- Anti-Gravity does not modify application code
- Anti-Gravity is only invoked when explicitly requested
- The human developer approves continuation of the loop
