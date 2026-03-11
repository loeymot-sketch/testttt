> **AI NAVIGATION NOTICE**
> This file is always the copy of the latest Claude review.
> Source document: review-XXX.md (numbered file)
> Date: YYYY-MM-DD
>
> **AGENTS MUST READ THIS FILE**, not the numbered reviews.
> The numbered reviews (review-001.md, review-002.md, etc.) remain available for historical traceability but are not automatically loaded into AI context.

---

# Review Report - [Cycle Number]

## Date
YYYY-MM-DD

## Reviewer
Claude (Architect & Reviewer Agent)

## Plan Reference
[Plan XXX](../planning/plan-XXX.md)

## Execution Reference
[Execution XXX](../execution/execution-XXX.md)

---

## Implementation Summary

### Files Changed
- List all files modified in this cycle

### Changes Overview
- Brief description of what was implemented

---

## Architecture Review

### ✅ Architecture Preserved
[ ] Yes - Architecture consistent with docs
[ ] No - Architecture deviations found (see below)

### Business Rules Review
[ ] All business rules respected
[ ] Issues found (see below)

### Authorization Boundaries
[ ] Auth boundaries intact
[ ] Issues found (see below)

---

## Test Results Review

### Test Type Specified in Plan
- [ ] Kimi-test
- [ ] Anti-Gravity
- [ ] No-test

### Test Execution
- [ ] Tests executed by Kimi
- [ ] Test results included in execution summary
- [ ] All tests passed
- [ ] Some tests failed (see below)

### Test Coverage
- [ ] Unit tests present
- [ ] Integration tests present
- [ ] E2E tests not required
- [ ] E2E tests required but not executed

---

## Code Quality Review

### Linting & Formatting
- [ ] PHP CS Fixer passed
- [ ] ESLint passed
- [ ] No formatting issues

### Code Patterns
- [ ] Existing patterns followed
- [ ] Naming conventions respected
- [ ] No code duplication introduced

---

## Risk Assessment

### Risk Level
- [ ] Low - Simple change, well tested
- [ ] Medium - Some complexity, needs monitoring
- [ ] High - Complex change, needs careful validation

### Potential Issues
- List any potential issues or edge cases

---

## VERDICT

**[ ] APPROVED** - Implementation is correct and ready for human validation

**[ ] NEEDS_FIX** - Issues found, Kimi should fix (see details below)

**[ ] NEEDS_ANTIGRAVITY** - Critical validation needed, Anti-Gravity should test

---

## Next Steps

### If APPROVED:
1. Human validates final result
2. Continue to next cycle or finish

### If NEEDS_FIX:
1. Kimi fixes identified issues
2. Kimi re-runs tests
3. Claude re-reviews

### If NEEDS_ANTIGRAVITY:
1. Human explicitly requests Anti-Gravity test
2. Anti-Gravity executes E2E/critical tests
3. Anti-Gravity generates report
4. Back to planning cycle

---

## Detailed Findings (if any issues)

### Issue 1: [Title]
**Severity**: [Low/Medium/High]
**Description**: Detailed description
**Recommendation**: How to fix

### Issue 2: [Title]
**Severity**: [Low/Medium/High]
**Description**: Detailed description
**Recommendation**: How to fix

---

## Definition of Success Checklist

- [ ] Architecture preserved
- [ ] Business rules respected
- [ ] Authorization intact
- [ ] No unrelated regressions
- [ ] Tests passed (if applicable)
- [ ] Work easy to review
- [ ] Clear next step identified

---

**End of Review Report**
