# GATE — TASK_V1_PRICING_SSOT_001 — 2026-04-15

## Gate Type
**Frozen zone edit** — `OrderService.php` + `FrontendOrderService.php`

## Decision Required
Approve controlled refactoring of pricing logic inside both frozen services to delegate to a new centralized `PricingService`.

---

## 1. Cartographie des usages actuels

### OrderService.php — 3 pricing paths
| Method | Lines | What it calculates |
|---|---|---|
| `myOrderStore` (web orders) | 294–451 | item price + variations + extras × qty, tax (fixed/%), coupon discount, subtotal/total_tax/discount/total |
| `posOrderStore` (POS) | 562–760 | Same formula + `round()` on line totals & tax, manual discount, cash validation |
| `tableOrderStore` (QR dine-in) | 888–1072 | Same formula (no round), coupon + manual discount |

### FrontendOrderService.php — 1 pricing path
| Method | Lines | What it calculates |
|---|---|---|
| `myOrderStore` (kiosk/frontend) | 185–464 | Same formula with `round()`, coupon + loyalty redemption |

### Shared dependency
- `CouponService::calculateDiscountAmount()` (lines 268–279) — percent vs fixed, max cap, floor 0.

### Frontend (display only — NOT modified)
- `kioskPricing.js` — menu ratio display pricing (server recalculates)
- `posCartLineMath.js` — POS UI line display
- `kioskCart.js` Vuex — cart total display

---

## 2. Divergences connues POS vs Kiosk

| # | Divergence | POS path | Kiosk path | Risk |
|---|---|---|---|---|
| 1 | **Rounding** | `round(...,2)` on `$verifiedTotalPrice` and `$taxPrice` | `round(...,2)` on same + final totals | Cent-level differences possible on multi-line orders |
| 2 | **Web path rounding** | NO `round()` on line totals or tax | N/A (web uses OrderService) | Floating-point accumulation drift |
| 3 | **Loyalty** | Not available (coupon or manual only) | Inline loyalty redemption (`ceil` points, stacked after coupon) | Different discount totals for same basket if loyalty active |
| 4 | **Manual discount** | Available (POS operator can apply) | Not available on kiosk | Expected asymmetry — context-dependent |
| 5 | **delivery_charge null safety** | Uses `?? 0` | Uses bare property (nullable) | Potential null arithmetic in kiosk path |
| 6 | **Cross-item validation** | POS validates variation/extra belongs to item | Kiosk does same | Web `myOrderStore` has weaker validation (existence-only) |

**Fixtures expected to fail before refactor:** baskets with 3+ items where cumulative rounding differs (divergence #1), and baskets with loyalty + coupon stacking (divergence #3).

---

## 3. Migration Strategy

### Phase 1: Greenfield (no frozen zone contact)
- Create `app/Services/Pricing/` namespace with `PricingService`, `PricingRequest`, `PricingResult`, `PricingLineResult`, `TaxCalculator`, `DiscountCalculator`.
- 50+ unit test fixtures must pass green.

### Phase 2: Controlled bascule (frozen zone — this gate)
- **Feature-flagged**: `config('pricing.use_ssot_service', true)`.
- Inside each pricing path (`myOrderStore`, `posOrderStore`, `tableOrderStore` in OrderService; `myOrderStore` in FrontendOrderService):
  - If flag ON → delegate to `PricingService::calculateOrder()`, assign results to model fields.
  - If flag OFF → existing inline code preserved verbatim in an `else` block.
- **No other logic modified**: item creation, event dispatch, notification dispatch, status transitions — all untouched.
- API response shape is unchanged (same model fields serialized).

### Phase 3: Validation
- Parity test: same 50+ baskets → identical `total` between POS and Kiosk contexts.
- Existing PHPUnit suite must pass.
- API snapshot: response keys and types unchanged.

---

## 4. Rollback Plan

| Rollback level | Action | Time to execute |
|---|---|---|
| **Instant** | Set `PRICING_SSOT=false` in `.env` | < 1 minute |
| **Code revert** | Feature flag `else` branch contains original code verbatim | Automatic with flag |
| **Full revert** | `git revert` the merge commit | < 5 minutes |

The feature flag is the primary safety mechanism. The old pricing code stays in the codebase inside the `else` branch until the next major cleanup cycle.

---

## 5. Scope Boundaries

### WILL change
- Pricing **calculation** logic inside 4 methods (3 in OrderService, 1 in FrontendOrderService).
- Addition of `PricingService` call + feature flag guard.

### WILL NOT change
- Event dispatch (`OrderCreated`, `OrderStatusChanged`) — outbox pattern stays.
- Notification dispatch — stays after transaction.
- Item/OrderItem creation — model fields assigned from PricingResult but creation logic untouched.
- Status transitions — not in scope.
- Auth / middleware — not in scope.
- Frontend components — not in scope.
- Database schema — no migrations.

---

## Human Approval

- [ ] **I approve the frozen zone edit** to `OrderService.php` and `FrontendOrderService.php` under the conditions above.
- [ ] I confirm the feature-flag rollback plan is acceptable.
- [ ] EXECUTE may proceed.

**Approver:** _______________  
**Date:** _______________
