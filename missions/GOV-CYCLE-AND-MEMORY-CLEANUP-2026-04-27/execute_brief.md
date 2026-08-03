# GOV-CYCLE-AND-MEMORY-CLEANUP-2026-04-27

Mode: EXECUTE after A.1 and A.2 CLOSED
Purpose: remove active-cycle ambiguity and document memory/Phase A policy without mass cleanup.

## Human Decisions Already Captured

Source: `docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md`

- Caisse V1 / POS+Kiosk becomes active primary.
- W10 should be cleaned into secondary/archive.
- Track useful V1 memory decisions; do not track memory noise.
- Use targeted Phase A close and document remaining cleanup.

## Objective

Make orchestration reproducible for Train A and V1 release work:

- one active primary;
- explicit memory episode policy;
- no deletion of memory episodes;
- no mass triage of the whole dirty worktree;
- `docs/PHASE_A_CLOSED.md` documents what was closed and what remains.

## Allowlist

See `allowlist.txt`.

## Hard Prohibitions

- No product code edits.
- No migrations.
- No deletion of memory files.
- No self-approval of later D-M13 migration.
- No broad worktree cleanup.

## Validation

```bash
git status --short .cursor/ACTIVE_CYCLE.md memory/INDEX.md .gitignore docs/PHASE_A_CLOSED.md docs/gates/GATE_LOG.md docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md
bash scripts/agent-activity-log.sh tail 50
```

Expected result:

- Caisse V1 is the single active primary.
- memory policy is explicit.
- remaining cleanup is documented.

## Output Contract

- Write `missions/GOV-CYCLE-AND-MEMORY-CLEANUP-2026-04-27/report.md`.
- Write self-audit under `reports/audit/`.
- Verdict values: `A3_STATUS: CLOSED|REWORK|BLOCKED_HUMAN_GATE`.
