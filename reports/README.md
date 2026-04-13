# Reports

This folder stores the operational memory of the AI-assisted development workflow.

## Structure

- `reports/antigravity/`  
  - **Semantic role:** Playwright / E2E verification reports (browser and critical flows).  
  - **Path note:** Directory name is legacy; new reports still land here until a rename is orchestrated.
- `reports/planning/`  
  - Plans and task breakdowns (Claude / orchestrator).
- `reports/execution/`  
  - Execution summaries after implementation (typically Cursor / executor).
- `reports/review/`  
  - Review verdicts and scoring (Claude / orchestrator).

See also: `docs/ops/BOT_TO_CLAUDE_RUNTIME_CONTRACT.md`, `docs/ops/CURSOR_MODEL_ROUTING_POLICY.md`, `workflows/qa-loop.md`, `AGENTS.md`.

## Workflow (two loops)

### Normal loop (~90% — fast iteration)

1. **Claude** plans (test strategy + `files_allowed` when applicable).
2. **Human** validates plan (GO / MODIFY / STOP).
3. **Cursor** (or designated executor) implements per plan.
4. **local-validation** (PHPUnit, Jest, Vitest, linters) when the plan specifies it.
5. **Claude** reviews → verdict in `reports/review/latest.md`.
6. **Human** final validation.

### Playwright / E2E loop (~10% — critical verification)

1. Plan or review requests **`playwright-critical-flow`** or **`playwright-full-e2e`** (or verdict **`NEEDS_PLAYWRIGHT`**).
2. **Human** authorizes when required.
3. **Playwright MCP** (or equivalent) runs browser / critical flows; evidence goes under **`reports/antigravity/`** (e.g. `latest.md`) until paths are renamed.
4. **Claude** analyzes evidence → back to normal loop planning if needed.

## Naming

Use clear names:

- `report-001.md` (numbered Playwright / E2E QA reports in `reports/antigravity/`)
- `plan-001.md` (planning snapshots)
- `execution-001.md` (execution archives)
- `review-001.md` (review archives)

Or timestamped names, e.g. `2026-03-10-report-001.md`.

## Reading priority (`latest.md` pattern)

When continuing work, agents should read in this order:

1. `reports/antigravity/latest.md` — only if a Playwright / E2E cycle produced output this round.
2. `reports/planning/latest.md` — active plan.
3. `reports/execution/latest.md` — last implementation + **local-validation** results.
4. `reports/review/latest.md` — verdict and scoring.
5. Relevant `docs/**`.
6. `workflows/**`.

**Note:** Numbered files remain for history; **`latest.md`** files are the primary entry points for runtime injection (see bot → Claude contract).

## Test strategy (active vocabulary)

Claude **must** declare a test strategy in every plan. Use these labels (not legacy names):

| Label | Meaning |
|--------|--------|
| `no-test` | Docs-only, comments, trivial formatting. |
| `static-inspection` | Read-only code/doc review, no test command. |
| `local-validation` | PHPUnit / Jest / Vitest / linters in-repo. |
| `playwright-mcp` | Targeted browser checks via Playwright MCP. |
| `playwright-critical-flow` | Critical multi-surface flows (order, KDS, OSS, etc.). |
| `playwright-full-e2e` | Broad regression / full journey. |
| `human-verification` | Requires explicit human sign-off. |

Legacy mapping (for reading old plans): former label **Kimi-test** → **`local-validation`**; former E2E QA label **Anti-Gravity** → **`playwright-critical-flow`** or **`playwright-full-e2e`**.

## Verdict types (review)

One of:

- **APPROVED** — Ready for human validation.
- **NEEDS_FIX** — Executor should correct per minimal plan.
- **NEEDS_PLAYWRIGHT** — **Playwright / E2E verification** required before approval; follow-up cycle uses `reports/antigravity/latest.md` (path legacy).

This keeps continuity between planning, implementation, review, and evidence.
