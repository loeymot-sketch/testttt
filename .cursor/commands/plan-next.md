> **[SUPERSEDED — pre-phase-1]** This command predates the run-cycle.md protocol.
> Use `.cursor/commands/run-cycle.md` + ACTIVE_CYCLE.md for all planning cycles as of Phase 1.
> Kept for historical reference only. Do not use in autonomous cycles.

Read `reports/antigravity/latest.md` (always the most recent Playwright / E2E verification report).

Then:
- summarize the issue
- identify affected modules
- produce a technical plan
- split into small tasks
- mark each task as CLAUDE or KIMI
- write the result to `reports/planning/latest.md`

Use:
- `AGENTS.md`
- the docs in `docs/`
- `workflows/task-routing.md`.

Note: Historical reports are available in `reports/antigravity/report-XXX.md` but are not automatically loaded.
