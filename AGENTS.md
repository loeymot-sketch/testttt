# FoodKing – Cursor Agent Operating Contract

## Engine
Cursor local agent. No cloud orchestration. No external framework.
Auto/Premium routing: disabled. Model selection is explicit per cycle.

## Workflow
PLAN → EXECUTE → VALIDATE → AUDIT → [HUMAN GATE | CLOSE]

No phase may be skipped. Audit always precedes close.

## Model Roles
| Model | Role |
|---|---|
| Claude | Plan, architect, orchestrate, audit |
| GPT-5.4 | Complex implementation |
| Composer | Routine edits, reports, summaries |

One PRIMARY_MODEL per cycle. Roles do not overlap.
Full routing policy: `.cursor/routing.md`

## Authoritative multi-agent bounded cycle (SSOT)

For **TASK_ID-driven** work in Cursor, this path is **authoritative** and overrides any conflicting step elsewhere in this document:

1. **Command:** `.cursor/commands/run-cycle.md` (invoke with a `TASK_ID`, e.g. `run-cycle SMOKE-001`).
2. **Cycle state:** `.cursor/ACTIVE_CYCLE.md` (`RUNNER_MODE: single-session`, `PHASE`, `PLAN_FILE`, `REPORT_FILE`, completion rows).
3. **Plan artifact:** `plans/PLAN_[TASK_ID]_[DATE].md` per `.cursor/context/plan-context.md` (from `plans/PLAN_TEMPLATE.md` when applicable).
4. **Phase instructions:** `.cursor/context/plan-context.md` (PLAN), `.cursor/context/execute-context.md` (EXECUTE), `.cursor/context/audit-context.md` (AUDIT); VALIDATE per `run-cycle.md` when `validate-context.md` is absent.

**EXECUTE delegation:** Implementation is performed only after **explicit** delegation to the subagent matching `PRIMARY_MODEL` and routing intent (see `.cursor/routing.md` and **Step 2** in `run-cycle.md`): **`foodking-routine-implementer`** for Composer (routine), **`foodking-complex-implementer`** for GPT-5.4 (complex). Do not treat a cycle as properly executed if product edits were made without that delegation pattern (or the documented exception in `run-cycle.md`).

Sections below labeled **Legacy workflow** remain valid for **PR-centric / review-loop** habits but **do not replace** this SSOT for bounded cycles.

## Source of truth (extended)
- README.md
- docs/PROJECT_CONTINUITY_AND_VISION.md
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

## Stop Conditions
Halt and generate a gate brief on any of:
- Gate trigger detected
- Scope expansion beyond declared boundary
- FoodKing invariant violation
- Two consecutive validation failures
- Planning ambiguity unresolvable from task context

## FoodKing Non-Negotiables
- Backend is pricing SSOT — no frontend price logic
- `OrderStatus` enum is authoritative — no hardcoded strings
- `branch_id` = business data isolation — no cross-branch data bleed
- Dispatch strictly after DB commit
- `OrderService` / `FrontendOrderService` symmetry mandatory on any order change
- Frozen zones require gate clearance before any edit

## MCP
Phase 1: Filesystem MCP only.
Phase 2: Graphiti MCP (mémoire inter-cycles — décisions architecture, contexte passé, historique cycles).
Phase 3: Playwright MCP (tests E2E automatiques sur flows critiques FoodKing).
  Package : @playwright/mcp@latest (npx, pas d'install global)
  Browser : Chromium
  BASE_URL : http://localhost:8000
  Config : .cursor/mcp/playwright.json
  Flows couverts : POS Cash, POS Card, Kiosk, KDS, Auth refresh (F5)
  Déclencheur : plan déclare playwright-mcp | playwright-critical-flow | playwright-full-e2e
  Rapport : reports/antigravity/latest.md
  Règle : le Planner-Orchestrator seul décide si un cycle requiert E2E — jamais auto-déclenché.
  Server : github.com/getzep/graphiti — `mcp_server/graphiti_mcp_server.py`
  Clone local : `/Users/1millnonstop/graphiti` (commit épinglé — voir `.cursor/mcp/graphiti.json`)
  Backend : Neo4j AuraDB Free Tier
  Config : `.cursor/mcp/graphiti.json`
  Scope : lecture avant PLAN (query subsystem), écriture après CLOSE (episode + décisions clés).
  Group ID : `foodking` (isolation des entités FoodKing dans le graphe Graphiti)
  Règle : ne jamais bloquer la phase PLAN si Graphiti est indisponible — continuer sans contexte.

## Pre-Execution
Run `.cursor/hooks/safety-check.sh` manually before every execution phase.

## Artifact Locations
| Artifact | Path |
|---|---|
| Task intake | `tasks/` |
| Plans | `plans/` |
| Reports | `reports/` |
| Gate briefs | `docs/gates/` |
| Routing policy | `.cursor/routing.md` |
| Rules | `.cursor/rules/` |

---

## Extended workflow — repository operating instructions

### Claude (Architect & Reviewer)
Responsibilities:
- Architecture decisions and reasoning
- Root-cause analysis and debugging
- Planning with explicit test strategy
- Final review of implementation quality
- Risky refactors and cross-module decisions
- Auth/sync/pricing/state logic analysis
- Determines **test strategy** in plan using the active vocabulary (see **Testing rules**)

### Playwright / E2E verification (Critical QA)
Responsibilities:
- E2E testing (browser, Playwright MCP, complex flows)
- Critical integration testing
- Functional exploration
- Structured QA reporting under `reports/antigravity/` (legacy directory name)
- Only invoked when Claude's plan specifies **`playwright-critical-flow`**, **`playwright-full-e2e`**, or **`playwright-mcp`**, or when review verdict is **`NEEDS_PLAYWRIGHT`**

### Bugbot (Passive Diff Scanner — NO authority)
Responsibilities:
- Automatically scans PR diffs for bugs, security issues, regressions, edge cases
- Writes findings ONLY to `reports/review/bugbot-latest.md`
- NEVER autonomous — generates a file and stops
- NEVER communicates directly with Kimi or the Playwright / E2E executor outside the documented report chain
- NEVER makes architectural decisions
- Governed strictly by `.cursor/BUGBOT.md`

### Cursor
Orchestration environment

## Legacy workflow (PR / review loop — optional)

Use this loop for **historical PR-centric** review habits. It **does not** replace the **Authoritative multi-agent bounded cycle (SSOT)** above for `TASK_ID` + `run-cycle` work.

### Normal Cycle (90% of cases)
1. **Human** requests feature/fix
2. **Claude** analyzes and may write a **narrative or scratch** plan in `reports/planning/latest.md` **only when not** using the bounded SSOT; for bounded cycles the plan **must** be `plans/PLAN_[TASK_ID]_[DATE].md` as in `plan-context.md`.
   - Plan MUST specify test strategy: `no-test` | `static-inspection` | `local-validation` | `playwright-mcp` | `playwright-critical-flow` | `playwright-full-e2e` | `human-verification`
3. **Human** validates plan (GO / MODIFY / STOP)
4. **Kimi** MUST check FIRST: does `reports/review/bugbot-latest.md` exist?
   - **YES** → Kimi **notifies the Human** with:
     `ℹ️ Bugbot findings detected in reports/review/bugbot-latest.md — Claude review needed (ask Claude to fix when ready).`
     Then Kimi **continues normally to step 5** without stopping.
   - **NO** → Kimi continues normally to step 5
5. **Kimi** (or **Cursor** per plan) implements following Claude's plan
6. **Executor** runs **local-validation** (or other declared strategy) when the plan requires it (PHPUnit, Jest, etc.)
7. **Executor** writes execution summary in `reports/execution/latest.md` with test results
8. **Bugbot** (if PR exists) scans the diff → writes `reports/review/bugbot-latest.md` passively
9. **Claude** reads `reports/review/bugbot-latest.md` (when Human convokes Claude) and decides:
   - `ACCEPT` → not blocking, writes verdict in `reports/review/latest.md`
   - `REQUEST_FIX` → writes a minimal correction plan for Kimi
   - `ESCALATE` → schedules **Playwright / E2E verification** (only Claude can do this)
10. **Claude** writes final review in `reports/review/latest.md` with verdict: **APPROVED** / **NEEDS_FIX** / **NEEDS_PLAYWRIGHT**
11. **Kimi** deletes `reports/review/bugbot-latest.md` only after Claude writes `APPROVED` verdict
12. **Human** validates final result

### Playwright / E2E cycle (10% of cases - critical tests only)
1. **Claude's plan** specifies **`playwright-critical-flow`** / **`playwright-full-e2e`** / **`playwright-mcp`** OR **Claude's review** says **`NEEDS_PLAYWRIGHT`**
2. **Human** explicitly requests or authorizes the browser / E2E cycle when gating requires it
3. **Playwright** (MCP or runner) executes E2E/browser/critical tests
4. **Playwright** writes report in `reports/antigravity/latest.md`
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

### Use Playwright / E2E verification for:
- E2E testing (browser automation)
- Complex integration flows
- Critical business scenarios
- Multi-device testing
- Performance testing
- Only when explicitly requested or when Claude's plan specifies **`playwright-critical-flow`**, **`playwright-full-e2e`**, or **`playwright-mcp`**

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
   - test results (if **local-validation** or other test strategy ran)

## Testing rules
1. **Claude decides test strategy in the plan** (active vocabulary):
   - **`local-validation`**: Unit/integration tests (PHPUnit, Jest, Vitest)
   - **`playwright-mcp`** / **`playwright-critical-flow`** / **`playwright-full-e2e`**: E2E / browser / critical paths
   - **`static-inspection`**: Read-only audit without running the full suite
   - **`no-test`**: Trivial changes (docs, comments, formatting)
   - **`human-verification`**: Explicit human sign-off required

2. **Executor runs `local-validation`** when the plan specifies it:
   - Run PHPUnit for backend changes
   - Run Jest/Vitest for frontend changes
   - Run linter (phpcs, eslint)
   - Include test results in execution summary

3. **Playwright / E2E** executes when the plan specifies **`playwright-mcp`**, **`playwright-critical-flow`**, or **`playwright-full-e2e`**:
   - E2E browser testing
   - Complex integration scenarios
   - Critical business flows
   - Generate detailed QA report under `reports/antigravity/`

4. Prioritize tests for:
   - kiosk auth
   - pricing integrity
   - order creation
   - state transitions
   - KDS flows
   - OSS/display flows
   - authorization boundaries

## Operational output rules
- **Bounded SSOT cycles:** plan under `plans/` per `ACTIVE_CYCLE.md` / `plan-context.md`; execution evidence in `REPORT_FILE` and `reports/post_execute_latest.log` as in `run-cycle.md`.
- **Legacy loop:** planning narrative may go to `reports/planning/latest.md` (with test strategy specified) when not using the bounded SSOT.
- Execution summary goes to `reports/execution/latest.md` (with test results if applicable)
- Review output goes to `reports/review/latest.md` (with verdict)
- QA findings come from `reports/antigravity/latest.md` (only when Playwright / E2E verification is invoked; path name is legacy)
- **Bugbot findings** go to `reports/review/bugbot-latest.md` (passive, read only by Claude)
- Use the report format defined in `workflows/report-format.md`
- See `.cursor/BUGBOT.md` for Bugbot operating rules

## Behavior in uncertainty
- If docs and code disagree, say so explicitly.
- If code and reports disagree, investigate first.
- If the change is risky, stop and propose a safer phased approach.
- If uncertain about test strategy, default to **`local-validation`** for safety.

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
