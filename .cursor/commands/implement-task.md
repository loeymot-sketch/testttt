> **[SUPERSEDED — pre-phase-1]** This command predates the run-cycle.md protocol.
> Use `.cursor/commands/run-cycle.md` + ACTIVE_CYCLE.md for all implementation cycles as of Phase 1.
> Kept for historical reference only. Do not use in autonomous cycles.

Read `reports/planning/latest.md` (always the most recent Claude plan).

Implement only one clearly scoped task.

Rules:
- respect `AGENTS.md`
- do not touch unrelated modules
- keep the patch small
- if the task is architectural or risky, stop and ask for Claude-style analysis first

After implementation:
- write a summary to `reports/execution/latest.md`.

Note: Historical plans are available in `reports/planning/plan-XXX.md` but are not automatically loaded.
