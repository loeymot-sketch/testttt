# Phase A — Targeted Close Manifest

Date: 2026-04-26
Scope: Train A V1 release preparation
Status: CLOSED_FOR_TRAIN_A_WITH_D13_LOCAL_TEST_COMPLETE

## What Is Closed

Phase A is closed for the targeted release path needed before V1 validation:

- A.1 `GOV-PERSIST-SENTINELS-2026-04-27`: sentinels and POS quote-binding helper are tracked.
- A.2 `GOV-PERSIST-QUOTE-SUBSYSTEM-2026-04-27`: quote subsystem is tracked and `OrderQuoteService::hmacKey()` fails closed when `APP_KEY` is missing.
- A.3 `GOV-CYCLE-AND-MEMORY-CLEANUP-2026-04-27`: Caisse V1 is the single active primary and memory policy is explicit.
- A.4 `D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28`: local/test implementation is complete and validated.

This is not a mass worktree cleanup. The repository still contains unrelated modified/untracked files from previous orchestration waves.

## Human Decisions Captured

Source: `docs/gates/GATE_PHASE2_TRAIN_A_HUMAN_DECISIONS_2026-04-26.md`

- Caisse V1 / POS+Kiosk is the active primary.
- W10 is secondary/read-only until explicitly resumed.
- Memory episodes are tracked only when they preserve durable V1 decisions.
- Payment V1 is manual/simulated external terminal until a real gateway is configured.
- Senangpay/Bangladesh-era payment code must be audited and disabled/removed under a later dedicated mission if unused in France.
- French is the primary visible language for V1; technical labels require a later i18n audit.
- Hardware UAT remains required before commercial release.

D-M13 source:

- `docs/decisions/D-M13-QUEUE-NUMBER-UNIQUE.md`
- `docs/runbooks/D-M13-QUEUE-NUMBER-ROLLOUT.md`

## Validation Evidence

- D-M13 sentinel: `1 passed`.
- Queue number concurrency test: `3 passed`.
- Broad queue/kiosk/POS/order regression: `634 passed`, `4 skipped`.
- Full PHP suite: `1086 passed`, `8 skipped`, `0 failed`.
- Full Vitest: `126 files passed`, `853 tests passed`.
- Full Playwright with local Laravel server: exit code `0`, `34 passed`, `1 flaky retry passed`.
- Legacy bundle strict lint: exit code `0`, with known kiosk legacy bundle warning.

Key reports:

- `missions/D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28/report.md`
- `reports/audit/GPT_SELF_AUDIT_D-M13-QUEUE-NUMBER-DB-UNIQUE-2026-04-28.md`
- `reports/audit/PHASE2_TRAIN_A_VALIDATION_REPORT_2026-04-26.md`

## Release Blockers Still Open

- Production D-M13 rollout still needs production duplicate preflight, backup, cutover window, and rollback readiness.
- Hardware lab UAT still needs human/device execution.
- Live payment gateway configuration is intentionally out of current V1 technical validation; V1 uses manual/simulated external terminal card confirmation.
- French/i18n cleanup remains needed for visible technical English and missing locale keys.
- Legacy kiosk bundle warning remains until W2 cutover/shim cleanup.
- Playwright config has no `webServer`; E2E requires starting Laravel manually or adding an explicit test server strategy later.

## Git Discipline Note

The targeted Train A files are staged explicitly by path. Do not use `git add -A` because the worktree still contains many unrelated modifications and untracked historical artifacts.

PHASE_A_TARGETED_VERDICT: CLOSED_FOR_TRAIN_A_LOCAL_VALIDATION__COMMERCIAL_RELEASE_GATES_PENDING
