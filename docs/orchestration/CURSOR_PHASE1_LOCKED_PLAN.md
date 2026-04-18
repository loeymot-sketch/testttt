# FoodKing – Cursor Phase 1 Locked Plan

Status: LOCKED
All core phase-1 file groups validated: Groups 1–4
No core phase-1 configuration files remain unvalidated.

---

## Target Operating Model
- Engine: Cursor local agent — no cloud orchestration, no external framework
- Autonomy: semi-autonomous strict — runs within declared scope, halts on stop conditions
- Auto/Premium routing: disabled — model selection is explicit per cycle
- MCP: Filesystem MCP only — all other MCP deferred to phase 2
- Agent configuration: Cursor UI / Agents Window — repo-file agent definitions deferred to phase 2

---

## Workflow
PLAN → EXECUTE → VALIDATE → AUDIT → [HUMAN GATE | CLOSE]

- No phase may be skipped
- Audit precedes close on every cycle
- One PRIMARY_MODEL per cycle
- Loop halts and gates on any stop condition — never self-approves

---

## Model Routing
| Model | Role |
|---|---|
| Claude | Plan, architect, orchestrate, audit — no implementation code |
| GPT-5.4 | Complex implementation — executes within scoped plan only |
| Composer | Routine edits, validation, reports, summaries |

Full policy: `.cursor/routing.md`

Mid-cycle escalation requires Claude confirmation logged in the plan file.
Routing policy may not be modified during an active cycle.

---

## MCP Policy
Phase 1: Filesystem MCP only.
Deferred to phase 2: Graphiti, Playwright MCP, GitHub MCP, memory systems, all other connectors.

---

## Human Gate Policy
Gates are hard loop blocks. No model self-approves. No gate auto-resolves.

Hard gates (immediate halt): schema migrations, auth changes, new external services, manual UX test required, frozen zone edits, invariant violations, scope expansion, branch_id isolation changes, two consecutive validation failures.

Soft gates (halt pending clarification): planning ambiguity, audit drift findings, unresolved ESCALATION, unresolved SYMMETRY_NOTE.

Gate brief written by Claude to `docs/gates/GATE_[TASK_ID]_[DATE].md`.
Loop resumes only after human populates approval field and decision is logged in `GATE_LOG.md`.

---

## Scope Discipline
- Scope declared in plan file before execution: SUBSYSTEMS_TOUCHED, SUBSYSTEMS_OFF_LIMITS, PRIMARY_MODEL, GATE_CONDITIONS, INVARIANTS_AT_RISK
- Unlisted subsystems are off-limits without exception
- No implicit scope expansion — SCOPE_PRESSURE logged and reviewed at audit
- Ambiguous scope = hard stop before plan is written

---

## FoodKing Invariant Policy
Six invariants enforced at plan, execution, and audit. Violation triggers immediate gate.

1. Backend pricing is SSOT — no frontend price logic
2. OrderStatus enum is authoritative — no hardcoded strings
3. branch_id is business data isolation — no cross-boundary data access without explicit plan authorization
4. Dispatch strictly after DB commit
5. OrderService / FrontendOrderService symmetry mandatory when either is touched
6. Frozen zones require gate clearance before any edit

---

## Validated Groups

### Group 1 — Operating contract and routing
- AGENTS.md
- .cursor/routing.md
- .cursor/rules/global.mdc

### Group 2 — Model role contracts
- .cursor/rules/claude.mdc
- .cursor/rules/gpt.mdc
- .cursor/rules/composer.mdc

### Group 3 — Control and safety system
- .cursor/rules/scope.mdc
- .cursor/rules/project-invariants.mdc
- .cursor/rules/human-gates.mdc

### Group 4 — Execution artifacts
- .cursor/hooks/safety-check.sh
- tasks/TASK_TEMPLATE.md
- plans/PLAN_TEMPLATE.md
- reports/REPORT_TEMPLATE.md
- docs/gates/GATE_LOG.md

---

## Postponed to Phase 2
- .cursor/agents/*.json repo-file agent definitions
- .cursor/hooks.json and automated hook execution
- Automated test runner integration (post-validate)
- Automated lint integration
- Graphiti, Playwright MCP, GitHub MCP, all non-Filesystem MCP
- Memory / vector store for task history
- CI/CD pipeline integration
- Multi-agent parallelism
- Automated regression test generation
- Cross-repo task propagation
- Cost/token budget enforcement
- Slack/email gate notifications
