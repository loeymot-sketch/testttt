# FoodKing – Cloud Supervisor Operating Contract

## Engine
**Claude Code on the web (the "cloud") is the single supervisor AND executor.**
It plans, implements, validates, audits, and judges every cycle itself, delegating only to
sub-agents (read-only exploration, isolated verification) and to Playwright for behavioral
evidence. There is no local Cursor executor and no external orchestration framework.

> Migrated 2026-06-05 from the retired Cursor-local / Cowork model. The previous contract
> ("Cursor local agent. No cloud orchestration.") is **superseded**. Historical Cursor-era
> docs under `.cursor/`, `docs/orchestration/CURSOR_PHASE1_*`, and `tasks/phase9/` are kept
> as record only and carry a superseded banner.

## Workflow
PLAN → EXECUTE → VALIDATE → AUDIT → [HUMAN GATE | CLOSE]

No phase may be skipped. Audit always precedes close. The cloud session drives all phases
within one session, halting only on a hard gate condition (see Stop Conditions).

## Agent roster
The **ultra-detailed per-agent breakdown is the single source of truth** in
[`docs/orchestration/AGENT_ROLES.md`](docs/orchestration/AGENT_ROLES.md) — mandate, inputs,
outputs, hard limits, escalation triggers, and discipline rules for each agent. Summary:

| Agent | Role | Never |
|---|---|---|
| **Cloud Claude Code** (you) | Supervisor + executor: plan, implement, test, audit, judge, open PRs | Merge to default branch; self-approve a gate; claim done without evidence |
| **Explore sub-agent** | Read-only fan-out search across the codebase; returns conclusions | Edit files |
| **general-purpose Task sub-agent** | Isolated audit / finding verification / regression scan (≤300-word report) | Edit code; make architecture decisions |
| **Playwright MCP** | Real browser/device behavioral evidence on critical flows | Decide architecture or direction |
| **Human** | Merge gate, inter-track arbitration, product escalation | — |

Architecture and subsystem detail: [`docs/ARCHITECTURE.md`](docs/ARCHITECTURE.md).

## Source of truth (extended)
- README.md
- CLAUDE.md (identity, principles, non-negotiables)
- docs/orchestration/AGENT_ROLES.md (agent breakdown)
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
- workflows/qa-loop.md
- workflows/task-routing.md
- workflows/report-format.md
- workflows/task-status.md
- reports/README.md

## Stop Conditions
Halt and generate a gate brief on any of:
- Gate trigger detected (schema migration, auth change, new external service, frozen-zone edit)
- Scope expansion beyond declared boundary
- FoodKing invariant violation
- Two consecutive validation failures
- Planning ambiguity unresolvable from task context

## FoodKing Non-Negotiables (operational, code-level)
- Backend is pricing SSOT — no frontend price logic
- `OrderStatus` enum is authoritative — no hardcoded strings
- `branch_id` = business data isolation — no cross-branch data bleed
- Dispatch strictly after DB commit (`DB::afterCommit`)
- `OrderService` / `FrontendOrderService` symmetry mandatory on any order change
- `OrderStateMachine` is the sole authority for status transitions
- NF525 fiscal compliance on POS (Z/X reports, immutable `audit_logs`)
- Frozen zones require gate clearance before any edit

(These are the concrete enforcement of the principles in `CLAUDE.md` §3.)

## MCP
- **GitHub MCP** — PRs, issues, CI status, reviews (cloud sessions have no `gh` CLI).
- **Playwright MCP** — E2E on critical flows; `@playwright/mcp@latest`, Chromium, `BASE_URL http://localhost:8000`.
  Flows: POS Cash, POS Card, Kiosk, KDS, Auth refresh (F5). Report → `reports/antigravity/latest.md`.
  Only invoked when a plan declares `playwright-mcp` / `playwright-critical-flow` / `playwright-full-e2e`,
  or a review verdict is `NEEDS_PLAYWRIGHT`.

## Artifact Locations
| Artifact | Path |
|---|---|
| Task intake | `tasks/` |
| Plans | `plans/` |
| Reports | `reports/` |
| Gate briefs | `docs/gates/` |
| Cloud session bootstrap | `tasks/orchestration/CLAUDE_CODE_BOOTSTRAP.md` |

---

## Cycle loop (how a unit of work flows)

1. **PLAN** — Write a plan with explicit test strategy (`no-test` | `static-inspection` |
   `local-validation` | `playwright-mcp` | `playwright-critical-flow` | `playwright-full-e2e` |
   `human-verification`). Declare scope: subsystems touched, off-limits, invariants at risk.
2. **EXECUTE** — Implement directly. Prefer small diffs, reuse existing services/patterns, no new
   dependencies without justification. Use Explore sub-agents for fan-out search.
3. **VALIDATE** — Run the declared test strategy (see Testing rules).
4. **AUDIT** — Independently verify with a general-purpose Task sub-agent reading HEAD; produce a
   `reports/review/VERIFY_*.md` (≤300 words). No close without 100% RESOLVED.
5. **HUMAN GATE | CLOSE** — Escalate on any gate trigger; otherwise write the verdict to
   `reports/review/latest.md` and close.

## Testing rules
- The plan declares the strategy. Default to `local-validation` when uncertain.
- **PHP**: use the batch pipeline `scripts/run_php_feature_batches.sh [auth-security|kiosk-pos-sync|admin-seeders-reports|all]`.
  Tests run on SQLite `:memory:` (see `phpunit.xml`). The monolithic `php artisan test` is
  memory-bound — prefer batches or `php -d memory_limit=512M artisan test <file>`.
- **JS**: `npm test` (Vitest), `npm run production` for the build gate.
- **E2E**: Playwright MCP per the MCP section → `reports/antigravity/latest.md`.
- Prioritize: kiosk auth, pricing integrity, order creation, state transitions, KDS/OSS flows, authz boundaries, fiscal (Z/X, audit chain).

## Implementation rules
1. Prefer small diffs and the simplest change consistent with the architecture.
2. Reuse existing services, controllers, patterns, naming.
3. Point out inconsistent existing code before broad cleanup.
4. No new dependencies unless necessary and justified.
5. Large task → plan first, implement in phases.
6. After implementation summarize: files changed, why, risks, test results.

## Repository behavior rules
1. Read relevant docs before proposing or implementing a change.
2. Do not change code outside the requested scope, or touch unrelated modules.
3. Do not modify architecture casually.
4. Do not bypass server-side validation, pricing recalculation, authorization checks, or state transition rules.
5. Preserve the existing business domain language.
6. Respect boundaries between admin, manager/cashier, kiosk machine, kitchen display, and customer flows.
7. Respect the documented order flow and device flow.
8. If a change affects auth, sync, pricing, device behavior, or order states, state the risk and propose tests.

## Reports discipline
- Plans under `plans/`; gate briefs under `docs/gates/`; execution summaries in `reports/execution/latest.md`;
  reviews/verdicts in `reports/review/latest.md`; E2E findings in `reports/antigravity/latest.md`.
- Use the format in `workflows/report-format.md`. No new reporting structure without a plan-phase decision.

## Behavior in uncertainty
- If docs and code disagree, say so explicitly.
- If code and reports disagree, investigate first (reports may be stale).
- If a change is risky, stop and propose a safer phased approach.

## Workflow autonomy
Semi-autonomous, not fully autonomous. The cloud session reads the relevant files and latest reports
and drives cycles itself, but the human remains the final authority and explicitly validates each major
cycle and every merge to the default branch.

## Definition of success
- architecture preserved · business rules respected · authorization intact · no unrelated regressions
- tests passed (per declared strategy) · work easy to review · explicit risk · clear next step

Before important changes, first summarize the current architecture understanding in 5–10 lines.
