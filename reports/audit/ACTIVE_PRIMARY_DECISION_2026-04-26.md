# ACTIVE_PRIMARY_DECISION_2026-04-26

TASK_ID: CV1-GOV-SINGLE-ACTIVE-PRIMARY
Phase: A.5
Runner: codex-extension
Scope: decision brief only; `.cursor/ACTIVE_CYCLE.md` not edited.

## Current State

`.cursor/ACTIVE_CYCLE.md` currently declares:

- `ACTIVE_PRIMARY: CYCLE_W10_EXECUTION_CLOSEOUT`
- `CYCLE_W10_EXECUTION_CLOSEOUT`: `IN_PROGRESS`
- `CAISSE_V1_MASTERPLAY`: `ACTIVE`

This violates the intended single-active-primary discipline. It also confuses future task routing because Caisse V1 is the current human focus but W10 remains the formal primary.

## Recommended Decision

Recommended active primary:

`CAISSE_V1_ULTRA_FINITION_PHASE_A`

Recommended archive action:

- Move W10 closeout details to `.cursor/ACTIVE_CYCLE_ARCHIVE.md` as read-only.
- Set `.cursor/ACTIVE_CYCLE.md` to a single Phase A governance cycle:
  - `TASK_ID: CV1-PHASE-A-GOVERNANCE`
  - `PLAN_FILE: plans/PLAN_CAISSE_V1_ULTRA_FINITION_POST_CLAUDE_2026-04-26.md`
  - `REPORT_FILE: reports/audit/PHASE_A_GOVERNANCE_EXECUTION_2026-04-26.md`
  - `PHASE: GOVERNANCE`

## Why Not Apply Automatically

This is a governance decision, not a code fix. The plan marks A.5 as human-owned. I did not edit `.cursor/ACTIVE_CYCLE.md` from inference alone.

## Human Signature Status

HUMAN_SIGNATURE: PENDING.

Phase A cannot close until the active primary is explicitly chosen and reflected on disk.
