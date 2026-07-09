# Sub-system 1.d — Parking / Loyalty / Refund / changeStatus / Floorplan + MONEY-FR & i18n

READ-ONLY adversarial audit. Repo HEAD `61850b53` (branch `pos/category-first-caisse-2026-06-23`),
working tree dirty with 4 uncommitted source files (delivery/web-wireup: `OrderRequest.php`,
`DeliveryFeeService.php`, `FrontendOrderService.php`, `deliveryCharge.js`). DB foodking_e2e (live)
not mutated; PHPUnit run on sqlite `:memory:`.

## Verdict
No new P0/P1 in scope. Refund-bypass guard (the prior uncommitted heal) is **PRESENT** in the working
tree. 1 P2 (cross-cutting, adjacent sub-systems), 2 P3 cosmetic, 1 pre-existing out-of-scope test fail.

## Findings

| ID | Sev | Surface | file:line | Issue | Repro / Evidence |
|----|-----|---------|-----------|-------|------------------|
| F-1d-A | P2 | refund (cross-cutting) | `app/Http/Controllers/Admin/TableOrderController.php:63-70` + `OnlineOrderController.php:94-101` | `can('pos-refund')` gate on RETURNED exists ONLY in `PosOrderController::changeStatus`. Sibling controllers call `OrderService::changeStatus` with no pos-refund gate → a user holding `table-orders` / `online-orders` (but NOT `pos-refund`) can drive `status=RETURNED` → `PaymentService::cashBack` + `LoyaltyService::refundPoints`. Twin of the healed P1, siblings uncovered. | `grep -rn "pos-refund"` → only `PosOrderController.php` (3 hits). `OrderService.php:2188-2195` fires cashBack/refundPoints on RETURNED with no perm check. Routes `routes/api.php:1014,1027` gate only `online-orders`/`table-orders`. |
| F-1d-B | P3 | money-FR | `resources/js/components/admin/pos/PosLoyaltyRedeemModal.vue:113,320` | Renders `1,00 €` with a REGULAR space (0x20) before €, not narrow-NBSP per canonical Intl fr-FR. Comma is correct. Dormant in V1 (redeem disabled by `pos.manual_discount_enabled`). | `od -An -c` on L113 → `} } <space> € ` (octal 040, not 302 240 / 342 200 257). |
| F-1d-C | P3 (owner-gate, FROZEN — do NOT edit) | money-FR | `public/js/pos-wizard.js:220,666,2255` | en-US `'€' + num.toFixed(2)` → `€7.90` (US point, symbol-first) vs FR mandate `7,90 €`. FROZEN §7 + known owner-gate. | `grep -n "'€'" public/js/pos-wizard.js`. |
| F-1d-X | pre-existing, OUT OF 1.d SCOPE | POS walk-in CREATE | `tests/Feature/Pos/PosWalkinDeferredCreateTest.php:115,130,148` | `POST /api/admin/pos` returns 422 instead of 201 (all 3 cases). | Stash-revert proof: identical 3 fails on CLEAN tree (uncommitted 4 files stashed) → NOT caused by uncommitted work, pre-existing at HEAD. Create path = sub-system 1.a/1.b, not 1.d. Flag to POS-create sub-owner. |

## Verified-CLEAN

### T-1.d.1 Parking (`ParkedOrderController` + `PosParkedOrderService`)
- park/recall/discard all scoped by BOTH `user_id` AND `branch_id` (`PosParkedOrderService.php:64-66,75-79,197-201`) → no cross-branch leak.
- Controller `resolveOperatorContext` aborts 403 if `branch_id <= 0` (`ParkedOrderController.php:93-98`) → Admin (branch 0) cannot dump all-branch parked orders (P0-POS-04 guard intact).
- recall = `lockForUpdate` + delete inside tx (`:74-102`) → single-consume, no double-resume. Prunes unavailable variations (`:113-193`).
- "Resume an already-PAID order?" — N/A: parked orders are draft carts (`payload_json`), not placed Orders; no payment concept.
- Purge cron `purgeOlderThanHours` deletes by age across all (`:204-209`). **PosPurgeParkedScheduleTest 1/1 PASS.**

### T-1.d.2 refundWithCounterEntry (`PosOrderController.php:47-196`)
- Gated `can('pos-refund')` (Admin + BM only) fail-fast `:58-62`. Cross-branch fatal `:70-73`.
- Server-side sealed predicate (SSOT) `:105`; sealed→counter-entry mirror, pre-Z→RETURNED `:107-143`.
- Double-refund blocked: DB `UNIQUE(parent_order_id)` → `409 MIRROR_ALREADY_EXISTS` `:170-176`. No mirror×2.

### T-1.d.3 Loyalty redeem (`PosLoyaltyController` + `PosRedemptionService`)
- Gate `pos.redeem-loyalty` in `PosLoyaltyRedeemRequest::authorize` (`->can('pos.redeem-loyalty')`).
- Cross-branch 403 `PosLoyaltyController.php:53-56`. redeem>balance → 422 INSUFFICIENT_BALANCE (`PosRedemptionService.php:135-141`).
- redeem after refund/paid blocked: `assertOrderRedeemable` PAID→409, RETURNED/terminal→409 (`:271-297`). Double-redeem → UNIQUE→409 `:191-198`.
- V1 default: redeem DISABLED (`pos.manual_discount_enabled !== true` → 422 DISCOUNTS_DISABLED_V1, `:72-78`). **PosLoyaltyRedeemTest 7/7 PASS.**

### T-1.d.4 changeStatus / changePaymentStatus (`PosOrderController.php:312-357`)
- **Refund-bypass guard PRESENT** `:328-334`: `status===RETURNED` ⇒ `abort_unless(can('pos-refund'))`. (Prior uncommitted heal confirmed in working tree.)
- `changePaymentStatus` cannot trigger a money refund: `PaymentStateMachine` `PAID => []` blocks PAID→REFUNDED (`PaymentStateMachine.php:17`); method only flips field + writes audit, never calls cashBack (`OrderService.php:2292-2484`).
- `->only(...)` method names at `:28-37` all exist (no false-green phantom-method). **RefundBypassGuardTest 4/4 PASS.**

### T-1.d.5 Floorplan (`FloorplanController` + `DiningTableService`)
- assign validates order exists for branch (422) BEFORE lock `:194-200`; `lockForUpdate`; syncs `orders.dining_table_id` branch-scoped `:227-238`.
- release branch-scoped + locked `:266-300`. transfer locks ASCENDING id (deadlock guard) `:344-361`, occupancy guards `:366-372`.
- release-after-pos-order guarded: DINING_TABLE + PAID + table held by THIS order `:307-335`. **FloorplanControllerTest 14/14, DiningTableReleaseAfterPosOrderTest 2/2 PASS.**

### Money-FR (non-frozen) & i18n
- All primary display paths use `Intl.NumberFormat('fr-FR')` (PosRefundModal:314, PosCounterCollectModal:400, PosComponent:3456, ParkedOrdersComponent:219, cash/cashOverview `formatMoneyEuro`) or `.replace('.', ',')`+NBSP (ReceiptComponent:519-525). `toFixed`-without-replace only in try/catch fallbacks (PosRefundModal:319, PosCounterCollectModal:402, PosCashDrawerSessionDialog:550, CashOverview:574) — never primary.
- PosComponent:3823,3944 `toFixed` builds FORM payload (backend recalculates SSOT), not display.
- Raw i18n: NO `Label.X`/`kiosk.X`/`studio.X` literally rendered in POS Vue. No hardcoded English user-facing strings (grep heuristic clean).

## Test summary (sqlite :memory:)
PosLoyaltyRedeemTest 7/7 ✓ · DiningTableReleaseAfterPosOrderTest 2/2 ✓ · FloorplanControllerTest 14/14 ✓ ·
RefundBypassGuardTest 4/4 ✓ · PosPurgeParkedScheduleTest 1/1 ✓ · **PosWalkinDeferredCreateTest 0/3 (pre-existing, out-of-scope — F-1d-X).**
