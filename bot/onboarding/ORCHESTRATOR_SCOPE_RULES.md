# FoodKing — Orchestrator Scope Rules

> How Claude should size, bound, and control execution cycles.
> Complements: `AGENTS.md` (workflow), `docs/ops/CLAUDE_CYCLE_OUTPUT.md` (plan format).
> This file prevents scope creep, ensures appropriate model routing, and defines escalation triggers.

---

## 1. Cycle Sizing Principles

### The default cycle should be small

A well-scoped FoodKing cycle:
- Touches 1–5 files
- Addresses 1 concern
- Has a clear definition of done
- Can be verified with 1 test strategy
- Can be completed in 1 Cursor session

### Why small cycles matter in FoodKing

| Reason | FoodKing-specific example |
|--------|--------------------------|
| Two models share one table | A "simple" order model change becomes 2 models × 4 store methods × 5 change paths |
| Event dispatch timing is critical | Moving one line (event dispatch) from inside to outside a transaction changes KDS/OSS behavior |
| Four pricing paths exist | A pricing "fix" must be verified in POS, kiosk, table, and online — each with different edge cases |
| Frozen zones exist | A cycle that accidentally drifts into a frozen zone must be caught early, not after 20 files are changed |

### Cycle size thresholds

| Size | Files | Appropriate for |
|------|-------|-----------------|
| **Micro** | 1–2 | Dead import removal, doc fix, single test addition, config change |
| **Small** | 3–5 | Targeted bugfix, single feature addition, test suite for one service |
| **Medium** | 6–10 | Cross-service fix (e.g., pricing logic across 4 store methods), new feature with tests |
| **Large** | 11+ | **Requires justification.** Should be split unless truly atomic (e.g., migration + model + service + test) |

---

## 2. How to Keep Cycles Narrow

### Rule 1: One concern per cycle

**Good**: "Fix coupon `start_date` validation in `CouponService::couponChecking`"
**Bad**: "Fix coupon validation and also update pricing logic and clean up dead imports"

### Rule 2: Explicit `files_allowed`

Every plan must list `files_allowed`. If a file isn't listed, Cursor must not modify it. If Cursor discovers a needed change outside `files_allowed`, it must stop and report (AGENTS.md §7 — S1 escalation).

### Rule 3: The blast radius test

Before writing the plan, ask: "If this change has a bug, what breaks?"

| Blast radius | Acceptable cycle size |
|--------------|----------------------|
| 1 surface, no invariant touched | Micro or Small |
| 1 surface, 1 invariant touched | Small |
| Multiple surfaces | Medium (with explicit cross-surface verification) |
| Critical invariant (pricing, status, branch, auth) | Medium maximum, strong evidence required |
| All surfaces | **Split the cycle.** No single cycle should risk all surfaces |

### Rule 4: The dual-model check

If the plan touches `Order` or `FrontendOrder`:
- Add both model files to `files_allowed`
- Or explicitly state: "Only `Order` affected because [specific reason]"
- Never assume one model is sufficient without checking

### Rule 5: The quad-path check

If the plan touches pricing or order creation:
- List which of the 4 store methods are affected
- If only 1 is affected, state why the others are exempt
- If all 4 are affected, this is a Medium cycle minimum

---

## 3. When to Split Tasks

### Split when:

| Signal | Action |
|--------|--------|
| Plan exceeds 10 `files_allowed` | Split into phases: Phase 1 = core change, Phase 2 = propagation |
| Plan mixes backend logic + frontend UI | Split into: Phase 1 = backend, Phase 2 = frontend, Phase 3 = Playwright |
| Plan touches both `OrderService` and `FrontendOrderService` | Consider: Phase 1 = `OrderService`, Phase 2 = `FrontendOrderService`, Phase 3 = verification |
| Plan requires both `local-validation` and `playwright-critical-flow` | Phase 1 = implementation + local-validation, Phase 2 = Playwright after human review |
| Plan touches a frozen zone AND active code | Split: frozen zone change needs its own human-gated cycle |
| Plan creates a new migration AND modifies services | Phase 1 = migration only (verify schema), Phase 2 = service changes |

### Split template

```
Cycle A: [narrow scope]
  files_allowed: [...]
  test_strategy: local-validation
  definition_of_done: [specific]
  
Cycle B: [dependent scope]  
  depends_on: Cycle A APPROVED
  files_allowed: [...]
  test_strategy: [...]
  definition_of_done: [specific]
```

### Do NOT split when:

- The change is truly atomic (e.g., adding a column requires migration + model `$fillable` + service logic — splitting loses atomicity)
- The change is documentation-only
- The change is test-only

---

## 4. When to Stop and Escalate

### Escalation triggers (Cursor → Claude)

| Trigger | Escalation type |
|---------|-----------------|
| Discovered that a change is needed in a file not in `files_allowed` | S1 — scope expansion request |
| Discovered a pre-existing bug while implementing | S2 — bug report, do not fix without plan |
| Implementation reveals a doc/code contradiction | S3 — contradiction report |
| Test fails in an unexpected way unrelated to the change | S4 — pre-existing failure report |
| New dependency needed (npm/pip/composer) | S5 — `integration-gate` required |

### Escalation triggers (Claude → Human)

| Trigger | Why |
|---------|-----|
| 3 consecutive `NEEDS_FIX` on the same cycle | Either the plan is wrong or the executor can't complete it |
| `BLOCKED` twice on the same cycle for different reasons | Structural problem — not fixable by retry |
| Any frozen zone modification needed | Architecture boundary — human authority required |
| Intent question (is behavior X intentional?) | Human knowledge required — see HG-01, HG-02 in `ORCHESTRATOR_CYCLE_PRIORITIES.md` |
| Security decision (token expiration, ability scope) | Policy decision — not a code quality question |
| Production configuration question | Requires production access — Claude has no visibility |

### Escalation format

```
ESCALATION
Type: [S1|S2|S3|S4|S5|HUMAN]
From: [Cursor|Claude]
Reason: [specific]
Evidence: [what was found]
Blocked on: [specific decision or information]
Suggested resolution: [if applicable]
```

---

## 5. When Model Strength Must Be Increased

### Model routing context

FoodKing uses `docs/ops/CURSOR_MODEL_ROUTING_POLICY.md` for Cursor execution. Claude must decide which execution class to assign in the plan.

### Execution class selection rules

| Change type | Execution class | Reasoning |
|-------------|----------------|-----------|
| Doc update, comment cleanup, dead code removal | `inspection_readonly_fast` | Read-only or trivial changes |
| Single-file bugfix with clear test | `implementation_bounded` | Narrow scope, bounded blast radius |
| Multi-file fix across services | `implementation_complex` | Cross-service coordination required |
| Status transition or pricing logic change | `implementation_complex` | Critical invariant — needs strongest executor |
| Review of execution report | `critical_review_judge` | Judgment, not implementation |
| Playwright E2E test execution | `e2e_behavioral_tooluse` | Browser automation required |

### Upgrade model strength when:

| Condition | Action |
|-----------|--------|
| Change touches `ValidStatusTransition` | Use `implementation_complex` minimum — status lifecycle is critical |
| Change touches any `*Store()` pricing logic | Use `implementation_complex` — financial impact |
| Change touches `BranchScope` | Use `implementation_complex` — multi-tenant boundary |
| Change requires modifying both `Order` and `FrontendOrder` | Use `implementation_complex` — dual-model coordination |
| Change requires understanding event dispatch timing | Use `implementation_complex` — needs understanding of transaction boundaries |
| Previous attempt with lighter model failed | Escalate to next tier |
| Cycle is on its 2nd `NEEDS_FIX` | Escalate — the executor may lack context |

### Do NOT upgrade when:

- Change is doc-only
- Change is test-only with clear template
- Change is config-only
- Change is removing dead code

---

## 6. Scope Boundaries: What Belongs in a Cycle

### Always in scope for any FoodKing cycle

- [ ] Verify `files_allowed` covers all needed files
- [ ] Verify blast radius is documented
- [ ] Verify test strategy is appropriate for the change type
- [ ] Verify frozen zones are respected
- [ ] Verify definition of done is measurable (not "it works")

### Never in scope without explicit plan

| Activity | Why it needs its own plan |
|----------|--------------------------|
| Refactoring `Order`/`FrontendOrder` into shared trait | Architecture change — high blast radius |
| Switching `ShouldBroadcastNow` to `ShouldBroadcast` | Requires queue infrastructure verification first |
| Adding new payment gateway | Frozen zone |
| Multi-tenant SaaS evolution | Vision-level — not operational |
| Database schema redesign | Migration risk + cross-service impact |
| New middleware or auth mechanism | Security boundary change |

### Implicit scope additions (always include without asking)

| If the plan touches... | Also include... |
|------------------------|-----------------|
| `Order.$fillable` or `$casts` | Check `FrontendOrder.$fillable` / `$casts` |
| Any `*Store()` method pricing logic | List all 4 store methods in blast radius |
| Any `changeStatus()` method | List all 5 change paths in blast radius |
| `ValidStatusTransition` | Include exhaustive matrix test in definition of done |
| `BranchScope` | Include cross-branch test in definition of done |
| Event dispatch position | Include pre/post-commit verification in definition of done |

---

## 7. Cycle Anti-Patterns

### Anti-pattern 1: The Omnibus Cycle

**Signal**: Plan has 15+ `files_allowed`, touches 3 surfaces, includes "also clean up..."
**Problem**: Impossible to review, impossible to revert, impossible to attribute regressions
**Fix**: Split into focused phases

### Anti-pattern 2: The Drifting Cycle

**Signal**: Cursor reports "also fixed X while working on Y" for 3+ unrelated things
**Problem**: Scope creep makes the cycle unreviewable
**Fix**: Cursor must stop and report unrelated findings as S2 escalation

### Anti-pattern 3: The Repeat Cycle

**Signal**: Same task on 3rd `NEEDS_FIX` attempt
**Problem**: Either the plan is wrong, the executor lacks context, or the task is harder than estimated
**Fix**: Escalate to `BLOCKED` or `MANUAL_GATE`. Rewrite the plan or upgrade model strength.

### Anti-pattern 4: The Phantom Coverage Cycle

**Signal**: Plan says `local-validation`, execution report says "all tests pass", but no new test was created for the new behavior
**Problem**: Existing tests passing proves nothing about the new code
**Fix**: Require at minimum one new assertion that specifically tests the changed behavior

### Anti-pattern 5: The Single-Model Cycle

**Signal**: Plan changes `Order` behavior but doesn't mention `FrontendOrder`
**Problem**: Both models use the same `orders` table. A change to one can affect queries from the other.
**Fix**: Add dual-model check to the plan template

### Anti-pattern 6: The Doc-Trusting Cycle

**Signal**: Plan references status value 5 or 10 or 14 or 17
**Problem**: These are wrong values from stale docs
**Fix**: Reject the plan. Require enum verification.

---

## 8. Cycle Completion Criteria

A cycle is truly complete when:

1. All items in `definition_of_done` are met (not "mostly met")
2. All `files_allowed` changes are accounted for in the execution report
3. The test strategy from the plan was executed (not downgraded without justification)
4. No S1–S5 escalations are unresolved
5. The verdict is one of: `APPROVED`, `NEEDS_FIX` (with specific actions), or `BLOCKED` (with specific reason)
6. `MEMORY.md` update was considered
7. Doc/code mismatches introduced by the cycle are flagged for resolution
