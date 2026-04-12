# QA Loop

This repository uses a strict AI-assisted development cycle with two distinct loops.

---

## Normal loop (~90% — fast iteration)

```
1. Human requests feature/fix
2. Claude analyzes and writes plan in reports/planning/latest.md
   → Plan MUST specify test strategy (active vocabulary: no-test, static-inspection,
     local-validation, playwright-mcp, playwright-critical-flow, playwright-full-e2e, human-verification)
3. Human validates plan (GO / MODIFY / STOP)
4. Kimi / Cursor implements following the plan
5. Executor runs local-validation if plan requires it (PHPUnit, Jest, etc.)
6. Executor writes execution summary in reports/execution/latest.md
7. Claude reviews and writes reports/review/latest.md
   → Verdict: APPROVED / NEEDS_FIX / NEEDS_PLAYWRIGHT
8. Human validates final result
```

**When to use**: Most features, bug fixes, UI changes, CRUD, simple logic

**Test types (examples)**:

- `local-validation`: PHPUnit, Jest, Vitest, linter
- `no-test`: Docs, comments, trivial formatting
- `static-inspection`: Read-only classification / audit

---

## Playwright / E2E loop (~10% — critical validation)

```
1. Claude's plan specifies playwright-critical-flow / playwright-full-e2e / playwright-mcp
   OR Claude's review says NEEDS_PLAYWRIGHT
2. Human explicitly authorizes when gating requires it
3. Playwright (MCP or runner) executes E2E / browser / critical tests
4. Evidence written to reports/antigravity/latest.md (legacy directory name)
5. Claude analyzes report → writes new plan
6. Back to Normal loop step 3
```

**When to use**:

- E2E browser testing
- Complex multi-device flows
- Critical business scenarios (payment, order lifecycle)
- Auth/authz integration
- Performance testing (when in scope)
- When **local-validation** fails repeatedly on flow-critical behavior

---

## Human role

The human developer remains the final authority and decides whether to continue, revise, or stop a cycle.

## Important rule

No cycle is complete until:

- a plan exists (with test strategy specified)
- execution notes exist (with test results if applicable)
- review exists (with verdict)
- human validates

## Multi-agent operating model

### Official role sequence (normal loop)

1. Claude thinks and plans (with test strategy)
2. Human validates plan
3. Kimi / Cursor builds
4. Executor tests per plan (**local-validation**, etc.)
5. Claude reviews
6. Human validates result

### Official role sequence (Playwright / E2E loop)

1. Human authorizes Playwright / E2E when required
2. Playwright runs
3. Evidence recorded under `reports/antigravity/`
4. Claude analyzes
5. Back to normal loop

### Report flow

- Claude writes plans to `reports/planning/latest.md` (with test strategy)
- Executor writes execution notes to `reports/execution/latest.md` (with test results)
- Claude writes reviews to `reports/review/latest.md` (with verdict)
- Playwright / E2E writes QA reports to `reports/antigravity/latest.md` (only when invoked)

**Note**: Numbered files remain for historical traceability but are not automatically loaded into AI context. Agents read **`latest.md`** as the entry point.

### Critical rule

Agents must not skip the report chain.  
Every important change must be traceable through:

1. a plan (with test strategy)
2. execution notes (with test results)
3. a review (with verdict)
4. human validation

### Responsibility boundaries

- Claude does not perform broad blind implementation
- Claude MUST specify test strategy in every plan
- Kimi / Cursor does not make architecture decisions
- Executor runs **local-validation** when the plan requires it
- Playwright / E2E does not modify application code without a separate, explicit implementation plan
- Playwright is only invoked when explicitly planned or when verdict is **NEEDS_PLAYWRIGHT**
- The human developer approves continuation of the loop
