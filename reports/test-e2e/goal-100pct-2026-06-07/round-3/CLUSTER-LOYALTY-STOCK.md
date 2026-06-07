# CLUSTER-LOYALTY-STOCK — Round 3 Validation Report
**Date:** 2026-06-07 · Agent: CLUSTER-LOYALTY-STOCK · Clone: foodking_e2e :8766 · NO frozen edits, NO product-code edits
**Method:** drove the function + inspected real output + tried to break it. All mutations transaction-rolled-back; 0 clone pollution; fiscal seq unchanged (2029); no Z sealed.

## VERDICT: PASS (5/5 checks) — no new P0/P1. Two caveats (both deliberate-in-V1, documented).

---

## LOYALTY (1) EARN — PASS
- **Path:** `AwardLoyaltyPointsOnDelivery` (status-triggered on `OrderStatusChanged`→DELIVERED for POS, PREPARED|DELIVERED for kiosk). Rate = `loyalty_points_per_euro` (clone=10 pts/€, owner-configurable G1).
- **Static proof:** `PosLoyaltyAccrualRealPathTest` drives the FULL real path: mints loyalty_code via real `/loyalty/balance`, creates order via real `/api/admin/pos` passing ONLY customer_id (forces server-side derivation), walks PENDING→ACCEPT→DELIVERED via real change-status endpoint, asserts `earn` ledger row + balance increase. Plus `PosCustomerActiveStatus5LoyaltyTest`, `KioskLoyaltyLedgerAtomicTest`. **35/35 loyalty Feature/sentinel tests green** (incl. `PosRedemptionTtcTaxDoubleCountSentinel`, `LoyaltyClawbackOnRefund`, `LoyaltyRefundPointsIdempotent`).
- **Gotcha verified:** earn reads `total` (kiosk) vs `order_amount ?? total` (POS) per type — handled correctly in listener (`AwardLoyaltyPointsOnDelivery:96-101`). `points = floor(orderTotal * rate)`.
- **LIVE try-to-break (clone, all rolled back):** 10€ takeaway order @ PREPARED → balance 0→**100** (10€×10), 1 earn ledger row. **Idempotent re-fire** of the same `OrderStatusChanged` → balance STILL 100, STILL 1 earn row (atomic sentinel `loyalty_points_awarded=-1` = exactly-once). **Cancelled order** → 0 earn rows (no award).

## LOYALTY (2) REDEEM — PASS (fiscal-correct on every consuming surface)
- **Path:** `PosRedemptionService::applyToOrder` (POS) / `LoyaltyController::redeem` (kiosk). Rate = `loyalty_points_for_1_euro_discount` (clone=100 pts = 1€).
- **CAVEAT (deliberate):** prod V1 ships `pos.manual_discount_enabled=FALSE` → both POS and kiosk redeem return **422 DISCOUNTS_DISABLED_V1** (PosRedemptionService:72, LoyaltyController:273), pending the F1 TVA/HT fix. On the clone the flag is TRUE so the path is exercisable. **This 422 is NOT a defect** — it is the V1 gate. Path is correct WHEN enabled.
- **LIVE fiscal-netting proof (THE discriminating check):** created order 1× "Menu" @ 3.00€ TTC / 10% VAT (gross VAT=0.2727), redeemed 100 pts = 1€ via the real service:
  - order after: discount=1.00, total=2.00, **raw col `order.total_tax` stays GROSS 0.2727** (internal artifact, never rendered).
  - **`OrderDetailsResource` (SSOT for printed ticket / order-show / history) projects `total_tax = 0.18 NETTED**, `tax_lines: [{rate:10, base_ht:1.82, tax:0.18}]`.
  - **Z formula expected** netted VAT on post-discount 2.00€ TTC @10% = `2.00 − 2.00/1.10 = 0.1818 → 0.18`. **MATCH.** Redemption does NOT corrupt the fiscal total; per-rate TVA = collected = Z (H7 netting holds at runtime, via `OrderDetailsResource::buildTaxLines` mirroring `ZReportService::orderDiscountRatio`).
  - **Discriminating-leak answer:** the raw gross `total_tax` does NOT leak to any rendered surface — `OrderDetailsResource:70` overrides it with `$nettedTotalTax`. H7 defect-class is NOT reincarnated on order-show/history.
- **Static proof:** `PosLoyaltyRedeemTest` 7/7, `DiscountTicketTvaNettingTest` 4/4.
- **LIVE try-to-break (all guards fire, all rolled back):**
  - over-balance (500>150) → INSUFFICIENT_BALANCE 422
  - non-multiple-of-100 (150) → POINTS_NOT_MULTIPLE 422
  - unknown code → CUSTOMER_NOT_FOUND 404
  - double-redeem → refused (UNIQUE(user_id,order_id,type) 409 ALREADY_REDEEMED path + balance-drain 422; both valid)
  - balance correctly decremented 150→50 on the legit redeem.

## LOYALTY (3) CONSULT — PASS (totals reconcile across 3 sources)
- **Path:** `LoyaltyController::check` (phone→balance) + `history` (ledger). Kiosk UI: `KioskLoyaltyComponent.vue`.
- **LIVE proof:** customer with ledger [earn:+300 ba=300, redeem:-30 ba=270], users.loyalty_points=270:
  - `check('0699222333')` → points=270, **discount_value=2.70** (270/100, correct), name+code returned, HTTP 200.
  - **Reconcile:** consult points (270) == `users.loyalty_points` (270) == latest ledger `balance_after` (270). All three agree.
  - `history()` serves ledger path: 2 rows, correct types/points/balance_after, newest-first.
- accepts BOTH legacy status=1 AND Status::ACTIVE(5) (caisse-created customers) — `isCustomerActive` (LoyaltyController:890).
- **Render:** consult UI `KioskLoyaltyComponent.vue` binds `customer.loyalty_point` (balance badge) + `customer.name`, all text via `$t('kiosk.loyalty_screen.*')` keys. **39/39 referenced keys present in FR** (`resources/js/languages/fr.json`: "Programme fidélité", "points disponibles", "Membre fidélité"...) — 0 raw-label leak. (Full in-wizard balance-screen browser screenshot deferred — non-blocking; render is API-proven + i18n-complete + binding-verified.)

## STOCK LIVE SYNC (E3) — PASS (cache-invalidation path proven in the consuming surface)
- **Admin toggle surface:** `/admin/stock/rupture` → `POST admin/menu/availability/toggle` → `AvailabilityController::toggle` → `AvailabilityService::toggle` → fires `ItemAvailabilityChanged::forBranch` via `DB::afterCommit`.
- **Invalidation listener:** `InvalidateKioskMenuCacheOnItemAvailabilityChanged` (EventServiceProvider:221) — **NOT ShouldQueue → runs SYNCHRONOUSLY** when the event fires (no worker needed). Forgets exactly `kiosk.menu.branch.{branchId}`, the SAME key `MenuController::kiosk:67` reads via `Cache::remember`.
  - (The stale "(future)" comment at `MenuController:25` is misleading — the listener is wired and live.)
- **LIVE end-to-end proof on clone (item 1, branch 1):**
  1. prime cache → menu payload shows item `is_available=true`.
  2. toggle OOS → cache key **invalidated immediately** (Cache::has = NO).
  3. consuming-endpoint rebuild (Cache::remember miss) → item `is_available=FALSE` — the OOS reflects in the menu the borne/kiosk actually reads, not just the DB row.
  4. toggle back ON → cache invalidated again → rebuild → item `is_available=TRUE` (reappears).
- **POS surface:** reads availability live from `ItemBranchAvailability` (PosCategoryController) on each menu fetch — reflects on next load, no 60s cache.
- **TWO-CONTEXT CONSUMER PROOF (the close):** spec `zz-cluster-stock-e3-consumer-2026-06-07.spec.js` (1/1 PASS) — Context A = admin Sanctum token hits real `/admin/menu/availability/toggle`; Context B = SEPARATE kiosk Sanctum token (`kiosk:order`) fetches the **real consuming endpoint `/api/frontend/menu`** (exactly what the borne renders from):
  - before: item 1 `is_available=true` in kiosk menu.
  - admin toggles OOS → kiosk menu **`is_available=false`, `unavailable_reason="out_of_stock"`** (item present but rendered unavailable — not absent → no add-to-cart-then-409 trap).
  - admin restores → kiosk menu `is_available=true` again.
  - This is cross-context, through the cache-invalidation listener, on the live consumer endpoint — NOT just DB, NOT just in-process cache.
- **Visual:** `stock-rupture-dashboard.png` renders clean (Cayenne brand, "EN STOCK" badges, no raw labels); subtitle literally says *"synchronisé en temps réel sur la caisse, la borne et le wizard"* — matching the proven path.
- **E3 PARTIAL clarification:** the prior PARTIAL was the WS live-PUSH needing an isolated e2e queue worker (harness gap). The **cache/polling reflection path that the consuming UI reads is product-correct and now PROVEN on the consumer endpoint.** The kiosk reflects immediately on the next menu fetch (cache invalidated synchronously, no TTL wait).

---

## Evidence index
- Tests: `vendor/bin/phpunit --filter 'PosLoyaltyRedeem|DiscountTicketTvaNetting|KioskLoyalty|OrderCancellationLoyalty|LoyaltyApi|LoyaltyClawback|LoyaltyRefund|PosLoyaltyAccrual|PosRedemptionTtc|PosCustomerActiveStatus5|KioskRedeemWholePoint|LoyaltyBalanceThrottle'` → all green (35+7+4).
- Code: PosRedemptionService.php:64-265 / OrderDetailsResource.php:31-70,244-305 / LoyaltyController.php:60-118,491-586 / AwardLoyaltyPointsOnDelivery.php / AvailabilityService.php:383-392 / InvalidateKioskMenuCacheOnItemAvailabilityChanged.php / MenuController.php:66-72.
- Screenshots: `round-3/stock-rupture-dashboard.png`, `round-3/kiosk-idle.png`.
- Spec: `tests/e2e/zz-cluster-loyalty-stock-r3-2026-06-07.spec.js` (2/2 passed).

## Caveats (both deliberate, NOT defects)
1. Redeem path 422-gated in prod V1 (`manual_discount_enabled=false`) pending F1 — by design.
2. Per-line ticket TVA stays gross above the netted summary (standard FR layout; summary ventilation = collected = Z). Owner receipt-format preference, P2 polish, NOT fiscal — confirms convergence verdict §🟡.

## Cleanup
All test rows transaction-rolled-back; 0 ZZ users/orders/ledger persisted; item 1 restored available; cache cleared; fiscal seq unchanged (2029). No Z sealed.
