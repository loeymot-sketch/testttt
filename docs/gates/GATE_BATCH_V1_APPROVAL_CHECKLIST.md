# Batch approval checklist — Index V1 (2026-04-15)

Use this checklist when approving **all four** V1 gates in one pass. Each item links to the authoritative gate brief (scope, rollback, invariants).

## Approver

- **Name:** _______________________
- **Date:** _______________________
- **Environment:** production / staging / both

## Gates (sign each brief in-repo)

| # | Gate file | Scope summary | Rollback |
|---|-----------|-----------------|----------|
| 1 | [GATE_V1_PRICING_SSOT_001_2026-04-15.md](GATE_V1_PRICING_SSOT_001_2026-04-15.md) | Frozen: `OrderService` + `FrontendOrderService` → `PricingService`; feature flag | `PRICING_SSOT=false` / revert |
| 2 | [GATE_V1_STATUS_MACHINE_001_2026-04-15.md](GATE_V1_STATUS_MACHINE_001_2026-04-15.md) | Frozen: status methods → `OrderStateMachine`; migration `order_status_transitions` | migrate:rollback / revert |
| 3 | [GATE_V1_MENU_86_001_2026-04-15.md](GATE_V1_MENU_86_001_2026-04-15.md) | Migration `item_branch_availability` + services/UI | migrate:rollback / revert |
| 4 | [GATE_V1_DATA_SOFTDELETE_001_2026-04-15.md](GATE_V1_DATA_SOFTDELETE_001_2026-04-15.md) | Soft deletes + `deletion_log`; BranchScope interaction | migrate:rollback / revert |

## Confirmation

- [ ] I have read all four gate briefs end-to-end.
- [ ] I accept the frozen-zone edit scope for Pricing and Status Machine.
- [ ] I accept the listed migrations and data-model changes.
- [ ] I authorize **EXECUTE** for the corresponding tasks (`TASK_V1_*`) in the order agreed with tech lead.

**Batch statement (optional):**  
> All gates approved, proceed.

---

After approval, record approver + date in [reports/execution/REPORT_INDEX_V1_GLOBAL_AUDIT_2026-04-15.md](../../reports/execution/REPORT_INDEX_V1_GLOBAL_AUDIT_2026-04-15.md) § Sign-off.
