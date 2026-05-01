# Caisse V1 Ultra Finition Plan Stack

Purpose: executable planning stack for Claude's 2026-04-26 ultra audit.

This directory contains planning only. No file here is proof that a task has been executed.

## Files

| File | Role |
| --- | --- |
| `PHASE_A_GOVERNANCE_2026-04-26.md` | Human/governance blocker phase |
| `PHASE_B_TEST_STABILIZATION_2026-04-26.md` | Test red families and true product bugs |
| `PHASE_C_BACKEND_SECURITY_2026-04-26.md` | Backend invariant and security fixes |
| `PHASE_D_POS_FRONTEND_REFACTOR_2026-04-26.md` | POS frontend split and operational UI hardening |
| `PHASE_E_CATALOG_POS_LINK_2026-04-26.md` | Catalogue to POS completeness |
| `PHASE_F_SYNC_RESILIENCE_2026-04-26.md` | KDS/OSS realtime and sync hardening |
| `PHASE_G_CLOSURE_PROOFS_2026-04-26.md` | Release proof packet, UAT, hardware, fiscal |
| `TASK_REGISTRY_2026-04-26.md` | Single table of all task IDs, dependencies, allowlists, tests |

## Execution Lock

`PHASE_A_GOVERNANCE` is the only phase allowed to start before human validation. All B+ work is `BLOCKED_PHASE_A_UNSIGNED`.

## No Artificial Inflation

The plan uses atomic tasks that can be implemented and audited. It does not create fake "1000 tasks" to look large; it creates the complete set of useful tasks required by the audit.
