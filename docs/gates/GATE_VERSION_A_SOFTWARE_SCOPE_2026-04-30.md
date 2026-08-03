# Gate — Version A Software Scope Lock — 2026-04-30

Gate ID: `GATE_VERSION_A_SOFTWARE_SCOPE_2026-04-30`

Status: `APPROVED_AS_SOFTWARE_SCOPE_LOCK`

Owner: Codex execution loop, pending human release gate after software close.

Sentinel: `HARDWARE_UAT_REQUIRED_BEFORE_GO_LIVE`

## Decision

Version A finishing remains a **software/system validation track** until `VA-SYS-00..VA-SYS-10` reach a coherent final PASS.

Hardware and external provider validation are explicitly deferred to a separate industrial UAT track.

## In Scope Now

- Dashboard central management workflows.
- Product/category/photo CRUD and projections.
- Composer wizard model and runtime contract.
- Product-level rupture.
- Stockable wizard-choice rupture.
- Backend pricing SSOT and stale/forged payload rejection.
- Branch isolation and central authz.
- API + outbox + realtime/fallback sync between Dashboard, POS, Kiosk, KDS and OSS.
- Local browser/runtime validation with Playwright.
- Documentation, memory, runbooks and final software reports.

## Out Of Scope Until Hardware UAT

- Physical TPE / payment terminal certification and live refusal/timeout behavior.
- Fiscal printer paper output and physical reprint behavior.
- Industrial kiosk OS/browser lockdown on final device.
- Cloud realtime provider production latency and credentials.
- Google Maps live quota/latency/geocoding conditions.
- Real router/Wi-Fi loss on installed devices.
- Commercial release signoff.

No local software PASS, API audit PASS, or Playwright PASS can replace the sentinel
`HARDWARE_UAT_REQUIRED_BEFORE_GO_LIVE`.

## Runtime Protocol Decision

Runtime surfaces use Laravel API + branch-scoped outbox/realtime + fallback polling.

MCP can support agents, audits, memory and dev tooling, but it is **not** the production runtime bus for POS/Kiosk/KDS/OSS.

Reference: `docs/sync/API_VS_MCP_DECISION.md`.

## Audit Closeout Contract

External audit reports generated through the Messages API must end with exactly these final three non-empty lines:

```text
MASTER_AUDIT_VERDICT: PASS|REWORK
SOFTWARE_DECISION: READY_FOR_VA_SYS_00_05_EXECUTION|HOLD
NEXT_CODEX_MISSION: <TASK_ID>
```

The repository validator enforces this via:

```bash
node scripts/validate-master-audit-closeout.mjs <report.md>
```

The canonical ready value includes `VA-SYS-00` because the active tasklist includes a scope lock mission before `VA-SYS-01..05`.

## Current Known State

| Mission | Status |
| --- | --- |
| VA-SYS-00 | `PASS_SCOPE_LOCK` after this gate |
| VA-SYS-01 | `PENDING_VALIDATION` |
| VA-SYS-02 | `PENDING_VALIDATION` |
| VA-SYS-03 | `PENDING_VALIDATION` |
| VA-SYS-04 | `PENDING_VALIDATION` |
| VA-SYS-05 | `PENDING_VALIDATION` |
| VA-SYS-06 | `PASS_LOCAL` |
| VA-SYS-07 | `PASS_LOCAL_STRONG` |
| VA-SYS-08 | `PASS_RUNTIME_LOCAL_STRONG` |
| VA-SYS-09 | `PASS_DOCS_MEMORY` |
| VA-SYS-10 | `PASS_CORE_SYNC_VALIDATION_WITH_REMAINING_SYSTEM_GATES` |

## PASS Criteria For This Gate

- Hardware deferral is explicit and cannot be mistaken for local software PASS.
- Runtime protocol decision remains API/outbox/realtime, not MCP.
- Claude/Orcai API closeout is mechanically validated as the final three non-empty lines.
- VA-SYS-00 tasklist status is updated.

## REWORK Triggers

- Any report claims commercial or hardware readiness from local software tests alone.
- The closeout validator accepts a contractual block that is not the final three non-empty lines.
- `READY_FOR_VA_SYS_01_05_EXECUTION` is used as the canonical software decision while `VA-SYS-00` remains in the active tasklist.
