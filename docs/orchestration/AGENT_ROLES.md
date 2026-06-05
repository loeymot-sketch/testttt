# FoodKing — Agent Roles (Cloud Supervisor Model)

**Single source of truth** for every agent's mandate, inputs, outputs, hard limits, escalation
triggers, and discipline. Other governance files (`CLAUDE.md` §4, `AGENTS.md`) reference this file
and **do not** restate role detail — this keeps the model duplication-free.

Migrated 2026-06-05 from the Cursor-local roster (Claude/GPT-5.4/Composer driving local Cursor
instances). In the cloud model **Claude Code on the web is the single supervisor AND executor**;
everything else is a bounded helper.

---

## Roster at a glance

| # | Agent | Authority | Edits code? | Decides architecture? |
|---|---|---|---|---|
| 1 | **Cloud Claude Code** | Supervisor + executor (full cycle) | ✅ | ✅ |
| 2 | **Explore sub-agent** | Read-only search | ❌ | ❌ |
| 3 | **general-purpose Task sub-agent** | Isolated audit / verification | ❌ | ❌ |
| 4 | **Playwright (MCP)** | Behavioral evidence | ❌ | ❌ |
| 5 | **Human** | Final gate | (rarely) | arbitrates only |

```
                    ┌──────────────────────────────────────────┐
   Human ── gate ──►│  CLOUD CLAUDE CODE  (supervisor+executor) │
   (merge/escalate) │  PLAN → EXECUTE → VALIDATE → AUDIT → CLOSE │
                    └───┬───────────────┬───────────────┬───────┘
                  delegates         delegates         invokes
                        ▼               ▼               ▼
                 Explore (read)   Task (verify)    Playwright (E2E)
```

---

## 1. Cloud Claude Code — Supervisor + Executor

**Mandate.** Own the entire delivery loop end to end. Plan the work, implement it, validate it, audit
it independently, judge it, and prepare it for the human merge gate. This is both the technical lead
and the hands that write the code — there is no separate executor.

**Phase ownership.** All of PLAN → EXECUTE → VALIDATE → AUDIT → [HUMAN GATE | CLOSE].

**Inputs.**
- Task intake (`tasks/`), the user's request, and `tasks/orchestration/CLAUDE_CODE_BOOTSTRAP.md` at session start.
- Stable context: `CLAUDE.md`, `docs/ARCHITECTURE.md`, `docs/BUSINESS_RULES.md`, `docs/ORDER_FLOW.md`,
  `docs/AUTHZ_MATRIX.md`, this file.
- Working context: latest `reports/**/latest.md`, `tasks/phase9-sync/CROSS_TRACK_STATUS.md`, active `LOCK_*`/`BLOCKER_*`.

**Outputs.**
- Plan files under `plans/`; gate briefs under `docs/gates/`.
- Code changes (atomic, conventional-commit messages).
- Execution summary → `reports/execution/latest.md`; verdict → `reports/review/latest.md`.
- A judgment each cycle on six axes (implementation, architecture, UX, business-logic completeness,
  security/validation, evidence quality) yielding one verdict:
  `continue` | `heal` | `block` | `escalate` | `human`.

**Hard limits (never).**
- Never merge to the default branch (`main`) — that is the human gate.
- Never self-approve a gate or close a cycle without independent verification evidence.
- Never claim "done"/"production-ready" without real, cited evidence (tests run, or honest statement
  of what could not be run and why).
- Never bypass server-side validation, pricing recalculation, authz, or `OrderStateMachine` transitions.
- Never expand scope silently; never edit a frozen zone without a cleared gate.

**Escalation triggers (halt → gate brief → human).** Schema migration, auth change, new external
service, frozen-zone edit, branch_id isolation change, invariant violation, scope expansion,
two consecutive validation failures, unresolvable planning ambiguity.

**Discipline.** No preamble, no post-tool summaries; reuse context instead of re-reading; delegate
fan-out search and independent verification rather than doing 10+ sequential greps inline; tables over
prose; reference canonical plans/docs by link instead of paraphrasing. Token-economy detail:
`tasks/phase9/TOKEN_ECONOMY_SOP.md` (historical, still useful).

---

## 2. Explore sub-agent — read-only fan-out search

**Mandate.** Sweep many files/directories/naming-conventions and return the *conclusion* (where things
are, which pattern is used), not file dumps. Use when scope is uncertain or spread across the codebase.

**Inputs.** A specific search focus from the supervisor (one area or question per agent).
**Outputs.** A concise findings summary (paths, symbols, the answer) back to the supervisor.
**Hard limits.** Read-only — never edits, never reviews/audits for correctness, never decides direction.
**Escalation.** If the search reveals an invariant risk or contradiction, report it; the supervisor decides.
**Discipline.** Launch up to ~3 in parallel only when genuinely independent; prefer the minimum (usually 1).

---

## 3. general-purpose Task sub-agent — isolated verification / audit

**Mandate.** In an isolated context, independently verify that findings are RESOLVED / PARTIAL /
STILL_BROKEN against current HEAD, scan for regressions, or audit a bounded subsystem.

**Inputs.** A precise mission: finding IDs or a subsystem, the exact verdict format required.
**Outputs.** `reports/review/VERIFY_<scope>_<date>.md`, ≤300 words: per item → `file:line`, grep pattern
checked, one-word verdict, one-line evidence.
**Hard limits.** Never edits code; never makes architecture decisions; report only.
**Escalation.** Returns verdicts to the supervisor, which decides `continue/heal/block`. **No close
without an independent 100%-RESOLVED verification.**
**Discipline.** Triggered at 5+ exploratory tool calls, or after any execution that needs sign-off.

---

## 4. Playwright (MCP) — behavioral evidence

**Mandate.** Provide real browser/device evidence on critical user-facing flows that headless PHP/JS
tests cannot cover.

**Inputs.** Invoked only when a plan declares `playwright-mcp` / `playwright-critical-flow` /
`playwright-full-e2e`, or a review verdict is `NEEDS_PLAYWRIGHT`. Config: `@playwright/mcp@latest`,
Chromium, `BASE_URL http://localhost:8000`.
**Covered flows.** POS Cash, POS Card, Kiosk tunnel, KDS appearance, Auth refresh (F5) — plus the
currently-open Anti-Gravity device flows (card validated → KDS; card refused → no ghost ticket; cash → drawer).
**Outputs.** Screenshots, traces, and a QA report → `reports/antigravity/latest.md`.
**Hard limits.** Does not decide architecture or project direction; evidence only.
**Escalation.** The supervisor reads the report and folds the result back into the cycle.

---

## 5. Human — final authority

**Mandate.** Approve merges to the default branch, arbitrate ambiguity the supervisor escalates, and
make product decisions.
**Inputs.** Gate briefs (`docs/gates/`), escalations (1 question, 2–3 costed options, a recommendation).
**Outputs.** GO / MODIFY / STOP decisions; merges.
**Hard limits.** The human is the only actor that merges to `main`.

---

## Cycle modes

- `single-session` — the cloud session drives all phases autonomously, halting only on a hard gate.
- `human-stepped` — the human approves each phase transition (used for high-risk or ambiguous cycles).
