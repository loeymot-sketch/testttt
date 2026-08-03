# PLAN — CV1 V2 Finalization Loop — 2026-05-03

TASK_ID: `CV1-V2-FINALIZATION-LOOP-001`  
PRIMARY_EXECUTION_MODEL: `codex-extension (in-session orchestration fallback active)`  
REASONING_EFFORT: `high`  
PLAN_REVIEW: `in-session continuous audit after each batch`  
EXECUTION_TIER: `mixed (routine + complex)`  

## Goal

Close remaining V2 hardening gaps from the central-tree architecture baseline with strict loop discipline:

1. Prioritized execution
2. Validation after each unit
3. Audit evidence after each batch
4. Mega-audit at the end

## Prioritized Backlog (highest first)

0. **Delivered in this loop (validated)**
   - Catalog Studio hardening L1/L2/L3/L4/L5/L6
   - Status: `DONE` (sentinels + global vitest pass, see `reports/audit/CATALOG_STUDIO_AUDIT_AND_REMEDIATION_PLAN_2026-05-03.md`)
1. **P0 — Hard gate**
   - `CV1-WC-T-WC-SOURCE-FK-01` (DB FK migration for `source_ref`)
   - Status: `BLOCKED_HUMAN_GATE` (schema migration)
2. **P0 — Runtime resilience**
   - POS/KDS/OSS sync fallback parity + runtime flags + sentinels
   - Status: `IN_PROGRESS (major part done)`
3. **P1 — Projection convergence**
   - `V2-POS-CATEGORY-CONVERGE`
   - Status: `IN_PROGRESS (controller + projection + sentinels done)`
4. **P1 — Ops rollout readiness**
   - `T-OPS-POS-POLLING-01` and `T-OPS-POS-WIZARD-COMPOSER-01`
   - Status: `READY_FOR_STAGING_ROLLOUT` (code complete, env flip pending)
5. **P2 — Structural refactors**
   - `V2-WIZARD-RT-REFACTOR-XL`
   - Status: `NOT_STARTED`
6. **P2 — Dashboard cleanup phase 2**
   - `CV1-DASHBOARD-CLEANUP-2`
   - Status: `NOT_STARTED (gates expected depending on deletion scope)`

## Subsystems Touched

- `app/Services/Menu` (write)
- `app/Http/Controllers/Admin` (write)
- `resources/js/services` (write)
- `resources/js/components/admin/orderStatusScreen` (write)
- `resources/views` (write)
- `config` (write)
- `tests/Feature/Menu` (write)
- `tests/js` (write)

## Subsystems Off-Limits (for this loop file)

- Pricing engine internals
- Auth middleware/guards
- Schema migrations before gate clearance
- Frozen zones without explicit gate

## Invariants At Risk

- Backend SSOT for pricing: preserved
- `branch_id` isolation: preserved (POS branch-scope tests run)
- Dispatch-after-commit: unchanged in this batch
- Service symmetry (`OrderService` / `FrontendOrderService`): untouched in this batch

## Gate Conditions

- Schema migration required (`SOURCE-FK`) → human gate mandatory
- Any widening into auth/schema/frozen zones → stop and escalate

## Execution Log (loop batches)

- **Batch A (done)**: POS projection convergence + failsafe + branch scope/parity sentinels
- **Batch B (done)**: OSS sync service + KDS runtime cadence config + runtime wiring sentinels
- **Batch C (next)**: Gate brief + gate log entry prep for SOURCE-FK
- **Batch D (next)**: Staging rollout checklist for ops flags
- **Batch E (next)**: Mega-audit + residual backlog pruning

## Status refresh (post Studio non-stop batch)

- Studio central page requirements delivered:
  - wizard integrated drawer
  - inline stock availability controls
  - quick-create with image upload
- Remaining true backlog = gate SOURCE-FK + OPS rollout + XL runtime refactor + cleanup phase 2.
