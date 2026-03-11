# Task Routing

## Use Claude for
- architecture decisions
- debugging complex failures
- root-cause analysis
- synchronization logic
- order lifecycle logic
- risky refactors
- pricing and server-side integrity
- authorization and access boundaries
- cross-module reasoning
- planning and review
- **determining test strategy** (Kimi-test / Anti-Gravity / No-test)

## Use Kimi for
- localized implementation
- UI changes
- CRUD endpoints
- repetitive code generation
- small and clear patches
- limited-scope file edits
- simple wiring between existing modules
- **unit/integration testing** (PHPUnit, Jest, Vitest)
- **linting and formatting**
- **execution summary with test results**

## Use Anti-Gravity for
- **E2E testing** (browser automation, Selenium, Playwright)
- **complex integration flows**
- **critical business scenarios**
- **multi-device testing**
- **performance testing**
- Only when Claude's plan explicitly specifies "Anti-Gravity test"
- Or when Claude's review says "NEEDS_ANTIGRAVITY"

## Default rule
If the task touches:
- auth
- sync
- pricing
- KDS
- OSS
- order state transitions
- or multiple domains

Then Claude must analyze first and specify test strategy.

If the task is simple after analysis, Kimi implements and tests.

## Execution chain

### Normal Cycle (90% of cases - Fast iteration)
1. **Human** requests feature/fix
2. **Claude** analyzes and plans (specifies test type: Kimi-test / Anti-Gravity / No-test)
3. **Human** validates plan
4. **Kimi** implements
5. **Kimi** tests (if plan says "Kimi-test")
6. **Kimi** writes execution summary with test results
7. **Claude** reviews (verdict: APPROVED / NEEDS_FIX / NEEDS_ANTIGRAVITY)
8. **Human** validates final result

### Anti-Gravity Cycle (10% of cases - Critical validation)
1. Claude's plan specifies "Anti-Gravity test" OR Claude's review says "NEEDS_ANTIGRAVITY"
2. **Human** explicitly requests Anti-Gravity
3. **Anti-Gravity** executes E2E/critical tests
4. **Anti-Gravity** generates report
5. Back to Normal Cycle step 2

This sequence must be preserved unless the human developer explicitly changes it.
