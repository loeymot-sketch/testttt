# Deprecated: KDS V2 Items Board (Owner Gate G1 = Option B)

**Date**: 2026-05-17
**Sprint**: V1.0.1 Hardening, H4.1 (Z3-NEW-001)
**Decision-maker**: Owner (sign-off via OWNER_GATES.md G1)
**Status**: DEPRECATED in V2

## Finding

Wave Z audit Z3 — Z3-NEW-001 (originally P0, owner-gate reclassified):

> V2 KDS layout dropped the legacy 5th "Items Board" column that aggregated `item.name + variations` across all visible orders. Chef batch-prep view (e.g., "5 fries + 3 cheeseburgers pending across N orders") not available.

V2 ships at HEAD `56204f052` (Wave Z final) with `KdsV2Grid.vue` containing zero aggregation logic — only per-order cards.

## Owner Gate G1 decision: **Option B (Deprecate)**

The Items Board is officially **removed** from FoodKing KDS as of V1.0.1. V2's unified-queue per-order view replaces the batch-prep aggregation.

## Rationale

1. **V2 unified queue makes batch-prep less essential**. Legacy KDS scattered items across 4 status columns (NEW / IN_PROGRESS / READY / DELIVERED). Chef needed an Items Board to find "all the fries to drop now". V2 puts every active order in one stream — chef reads top-down per ticket, batch-prep opportunity is visible without aggregation column.

2. **Screen real estate**. V2 KDS targets 10-15" landscape displays. A 5th column would compress every order card by ~20%, hurting per-order legibility (timer, allergens, customer name). The trade-off doesn't favor restoring the aggregation.

3. **Restoration cost vs train cost**. Option A (restore) was estimated 3-5 jours-agent: aggregation logic + UI + tests + CPU performance work for 50+ concurrent orders. Option B (deprecate) is doc + train = 0.5 jour. The delta exceeds the operational value of the feature.

4. **No fiscal/data correctness impact**. Items Board was a UX optimization. NF525 + cash trail + delivery enrichment are unaffected.

## Operational impact

| Aspect | Before V2 | V2 default (post-V1.0.1) |
|--------|-----------|---------------------------|
| Item-aggregation view | 5th column on KDS | NOT AVAILABLE |
| Per-order view | Per-status columns | Unified queue |
| Batch prep ergonomic | Scan one column | Scan per-order, top-down |
| Training | Pre-existing chef habit | New workflow taught at V1 deployment |

## Training note

Owner / restaurant manager to brief chefs on the V2 workflow at V1 deployment:
- Read the unified queue top-down.
- Same items across multiple orders are still adjacent on screen due to queue ordering by `queue_number` (FIFO).
- "Batch the same item" instinct works without an explicit aggregation column — chef can visually identify duplicates across cards.

## Reversal trigger conditions

Move from "Deprecated" back to "Restore" IF and ONLY IF:
- Field study (≥2 weeks post-deployment) shows chefs explicitly requesting the Items Board with measurable workflow loss
- KDS rush-hour throughput regression vs legacy baseline > 15%
- Owner reports operational complaint with telemetry evidence

Otherwise: KDS V2 stays unified-queue-only.

## References

- Wave Z `reports/test-e2e/wave-z-2026-05-16-claudemax/CONVERGENCE_FINAL.md` §"V1.0.1 polish backlog" Z3
- `plans/v1-0-1-hardening/OWNER_GATES.md` Gate G1
- `plans/v1-0-1-hardening/MASTER_V1_0_1_HARDENING_2026-05-16.md` §4 G1 + §9 H4.1
- `resources/js/components/admin/kitchenDisplaySystem/KdsV2Grid.vue` (V2 implementation, no Items Board pane)
- CLAUDE.md §6 (Visual Test Mandate) and §10 (Decision framework)

## CHANGELOG entry

Add to `docs/CHANGELOG_V1.md` (or equivalent) under V1.0.0:

```markdown
### Removed
- KDS legacy "Items Board" 5th column. V2 unified queue replaces it.
  Chefs should read the FIFO order stream top-down. See
  docs/decisions/DEPRECATED_KDS_V2_ITEMS_BOARD.md for rationale.
```
