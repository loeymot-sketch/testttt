# Codex — VA-SYS-00 Scope Lock / Hardware Deferral — 2026-04-30

TASK_ID: `CENTRAL-SYNC-VA-SYS-FINISHING/VA-SYS-00`

## Verdict

`VA_SYS_00_VERDICT: PASS_SCOPE_LOCK`

`NEXT_CODEX_MISSION: VA-SYS-01`

## What Changed

- Created `docs/gates/GATE_VERSION_A_SOFTWARE_SCOPE_2026-04-30.md`.
- Hardened `reports/audit/_CENTRAL_SYNC_ORCHESTRATION_CLAUDE_AUDIT_PROMPT_2026-04-30.txt`.
- Hardened `scripts/run-messages-api-audit.mjs`.
- Hardened `scripts/validate-master-audit-closeout.mjs`.
- Updated `docs/orchestration/ORCAI_AUDIT_ENDPOINT.md`.
- Updated `missions/VERSION-A-SYSTEM-FINISHING/TASKLIST.md`.

## Scope Locked

The current track is **software/system validation only**.

Hardware/provider UAT remains deferred:

- physical TPE;
- fiscal printer;
- final kiosk lockdown device;
- production realtime provider credentials/latency;
- Google Maps live;
- real installed network loss.

Sentinel locked in the gate:

`HARDWARE_UAT_REQUIRED_BEFORE_GO_LIVE`

## Audit Contract Locked

The external API audit closeout must be the final three non-empty lines:

```text
MASTER_AUDIT_VERDICT: PASS|REWORK
SOFTWARE_DECISION: READY_FOR_VA_SYS_00_05_EXECUTION|HOLD
NEXT_CODEX_MISSION: <TASK_ID>
```

`READY_FOR_VA_SYS_00_05_EXECUTION` is canonical because `VA-SYS-00` exists in the active tasklist.

## Validation

- `node --check scripts/run-messages-api-audit.mjs`: PASS
- `node --check scripts/validate-master-audit-closeout.mjs`: PASS
- strict closeout positive temporary report: PASS
- strict closeout negative temporary report with trailing content: PASS, rejected as expected
- `git diff --check` scoped VA-SYS-00 files: PASS
- adversarial read-only audit: PASS with no P0/P1; P2 sentinel/mega-context cleanup applied

## Invariants Checked

- Pricing SSOT: no pricing code touched.
- `OrderStatus`: no status logic touched.
- `branch_id`: no runtime authz code touched.
- Dispatch after commit: no event runtime code touched.
- Frozen zones: no `OrderService` / `FrontendOrderService` edits.

## Remaining Work

`VA-SYS-01` must discover the real dashboard workflows and selector map before any full dashboard E2E is written.
