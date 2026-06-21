# W3+W4 Borne/KDS/OSS — 5 confirmed→2 HEALED, 3 refuted — 2026-06-20

Workflow w13uoi8ol (8 agents). 2 confirmed (both same jumeau-class), 3 refuted.

## HEALED (TDD, 0 frozen) — the unreleased-board-release-filter twin class
- **[P1] KDS-ABUSE-01** — orderItems() (cook items board, GET /kds-order/items) leaked line items from
  UNPAID/unreleased orders (chef prepares food before payment). The unreleased-bump P1 heal patched
  list()+changeStatus() but left this sibling read endpoint. HEAL: 1-line `applyBoardReleaseFilter($query)`
  mirroring list():81. KitchenDisplaySystemOrderService (non-frozen).
- **[P2] KDS-ABUSE-02** — KdsSyncService::sync() (WS-down polling fallback) leaked the same orders (incl.
  customer phone) into orders[]. HEAL: 1-line `applyBoardReleaseFilter($ordersQuery)` + import. Non-frozen.
- Sentinel: KdsItemsBoardUnreleasedLeakCharacterizationTest flipped to assert absent from BOTH items-board + sync.

## REFUTED (verify-before-report)
- KIOSK-ABANDON-01 — "abandoned kiosk orders never cleaned up" = FALSE (CleanupStalePendingKioskOrders job
  exists, scheduled every 5min, 7 test files). Duplicate re-discovery of shipped functionality.
- KIOSK-LOYALTY-DIVERGE-02 — kiosk loyalty redeem fails at order time = real **P3 fail-closed** functional
  defect (TTC tax double-count in OrderQuoteService::withKioskLoyaltyDiscount:270, ignores tax_inclusive_prices;
  no money/point loss, atomic rollback). NOTED for backlog (non-frozen fix). Not a security/fiscal defect.
- KIOSK-COUPON-QUOTE-SCOPE-03 — "quote/order divergence on scoped coupons" = no divergence (BOTH paths drop
  scope identically, fail-closed); residual = scoped-coupons fail-closed at quote (P3, UX-only, no bypass).

## Gates: sentinel green, KDS regression 50 passed, frozen §7 diff = 0, scope = 2 non-frozen services + 1 test.
