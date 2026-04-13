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
- **determining test strategy** (`no-test` | `static-inspection` | `local-validation` | `playwright-mcp` | `playwright-critical-flow` | `playwright-full-e2e` | `human-verification`)

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

## Use Playwright / E2E for

- **E2E testing** (browser automation, Playwright MCP)
- **complex integration flows**
- **critical business scenarios**
- **multi-device testing**
- **performance testing** (when scoped in plan)
- Only when Claude's plan specifies **`playwright-critical-flow`**, **`playwright-full-e2e`**, or **`playwright-mcp`**
- Or when Claude's review says **`NEEDS_PLAYWRIGHT`**

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

If the task is simple after analysis, Kimi (or Cursor per plan) implements and runs **local-validation** when required.

## Execution chain

### Normal cycle (~90% — fast iteration)

1. **Human** requests feature/fix
2. **Claude** analyzes and plans (specifies test strategy from the active vocabulary)
3. **Human** validates plan
4. **Kimi** / **Cursor** implements
5. **Executor** runs **local-validation** (or `static-inspection` / `no-test` per plan)
6. **Executor** writes execution summary with test results
7. **Claude** reviews (verdict: **APPROVED** / **NEEDS_FIX** / **NEEDS_PLAYWRIGHT**)
8. **Human** validates final result

### Playwright / E2E cycle (~10% — critical validation)

1. Claude's plan specifies **`playwright-critical-flow`** / **`playwright-full-e2e`** (or **`playwright-mcp`**) **or** Claude's review says **`NEEDS_PLAYWRIGHT`**
2. **Human** explicitly authorizes extended browser verification when required
3. **Playwright** (MCP or runner) executes E2E / critical tests
4. Evidence is written to **`reports/antigravity/latest.md`** (legacy path; semantic = Playwright / E2E)
5. Back to normal cycle step 2

This sequence must be preserved unless the human developer explicitly changes it.
