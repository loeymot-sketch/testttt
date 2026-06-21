# W1b POS breadth (floorplan/parked/refund/wizard) — 5 confirmed, 3 healed + 2 deferred — 2026-06-20

Workflow wqtsow4mo (8 agents, find→dispute). 5 confirmed, 0 refuted.

## HEALED (TDD, 0 frozen)
- **[P1] POS-REFUND-BYPASS-01** — a POS Operator (no pos-refund) could issue a full cash refund on a
  DELIVERED order via change-status→RETURNED (dedicated /refund-with-counter-entry 403s them, but frozen
  OrderStateMachine DELIVERED→RETURNED is unconditional while the other RETURN edges require pos-refund).
  HEAL (non-frozen): `PosOrderController::changeStatus` guards RETURNED on `can('pos-refund')` BEFORE the
  try/catch (abort reaches client intact; mirrors the dedicated endpoint L58). Owner-LOCK followup G-REFUND-GATE:
  consolidate into OrderStateMachine DELIVERED case. Test: PosRefundChangeStatusBypassTest (3) — bypass→403,
  manager-with-pos-refund→200, dedicated→403. Fixed PosOrderBL2AuditCallSitesTest (grant pos-refund sanctum).
- **[P2] W1B-DINEIN-01** — out-of-enum order_type (3/7/99) bypassed the V1 dine-in killswitch (keyed only to
  ===20) onto a real table. HEAL: Rule::in(OrderType enum) on TableOrderRequest order_type. Test:
  QrTableOrderGuardAbuseTest ABUSE 3 (bogus→422, valid DINING_TABLE→201).
- **[P3] W1B-FLOORPLAN-02** — FloorplanController missing the branch_id>0 guard its sibling ParkedOrderController
  enforces (Admin branch=0 silent no-op). HEAL: mirror the guard + FloorplanAdminBranchZeroSentinelTest (3).

## DEFERRED (documented, V1-not-reachable / anti-drift)
- **[P3] W1B-RELEASE-03** — release() leaves orphaned orders.dining_table_id (double-reference). By-design with
  a passing test (test_release_marks_the_table_free_without_modifying_the_order_table_reference); dine-in DISABLED
  in V1 (unreachable); no money/fiscal impact. Owner decision: clear-pointer vs keep-for-history. NOT overridden.
- **[P3] W1B-RECALL-04** — parked recall() destructive-on-read (lost HTTP response loses the ticket). Design change
  (soft-consume + client ACK + prune cron); no money lost (parked carries no fiscal/payment). Backlog.

## Gates: 3 RED→GREEN, frozen §7 diff = 0, scope = 3 non-frozen source + 4 tests.
