# ULTRA-INTERSECTION — LoyaltyService (accrual + redeem) cross-surface

HEAD 48050af80 · DB foodking_e2e (mysql) · read-only audit · 2026-07-04
Posture: refute-by-default. GREEN ≠ correct.

## Shared function under test
`LoyaltyService` + the accrual/redeem/refund/clawback constellation:
- Accrual: `AwardLoyaltyPointsOnDelivery` (kiosk PREPARED/DELIVERED, pos/web DELIVERED)
- Redeem POS: `Loyalty\PosRedemptionService::applyToOrder` (post-create discount)
- Redeem kiosk/web (order-creation): `FrontendOrderService::applyKioskLoyaltyDiscount` → `Pricing\DiscountCalculator::kioskLoyaltyRedemption`
- Redeem standalone endpoint: `Frontend\LoyaltyController::redeem` (POST /api/frontend/loyalty/redeem)
- Reverse spent: `LoyaltyService::refundPoints` (cancel/reject/post-Z refund)
- Reverse earned: `LoyaltyService::clawbackEarnedPoints` via `ClawbackLoyaltyPointsOnRefund` (RefundCreated)

Live config verified: `pos.manual_discount_enabled=true`, `pricing.tax_inclusive_prices=true`,
`loyalty_points_per_euro=1`, `loyalty_points_for_1_euro_discount=100`, `loyalty_min_redeem_points=50`.
Unique index `loyalty_transactions_user_order_type_unique(user_id, order_id, type)` confirmed (migration 2026_03_26_075919), MySQL driver → NULL order_id rows are NOT deduped.

## Paths proven CONSISTENT (refuted findings)
- **Accrual base kiosk vs pos**: comment claims POS reads `orders.order_amount`. Column does NOT exist (schema: subtotal,discount,total_tax,total,pos_received_amount; no accessor). `$order->order_amount` is always null → both surfaces fall back to `$order->total` (post-discount). Behaviour is IDENTICAL across surfaces. Dead branch only. REFUTED gross-vs-net divergence.
- **Redeem €↔points rate**: POS (`PosRedemptionService:96`), kiosk (`DiscountCalculator:45`), endpoint (`LoyaltyController:360`) all read the SAME `loyalty_points_for_1_euro_discount` and all snap to whole points (floor). Consistent.
- **Order-linked redeem ↔ refund**: POS sets `loyalty_customer_code` + writes redeem with `order_id`; kiosk order-creation writes redeem with `order_id` (or back-links the pending one). `refundPoints` keyed on `order_id` reverses BOTH correctly. `refundPoints` (spent) and `clawbackEarnedPoints` (earned) act on DISJOINT buckets → both firing on a post-Z refund is coherent, not double-count. REFUTED double-reversal.
- **Idempotency (order-linked)**: earn sentinel (-1 CAS on `loyalty_points_awarded`), redeem unique(user_id,order_id,type), refund pre-check `manual_add`, clawback pre-check `manual_deduct` — all idempotent for non-null order_id. Confirmed.
- **Concurrency**: customer row `lockForUpdate` in POS redeem, kiosk redeem, endpoint redeem, refund — simultaneous redeem caisse+borne on same account serialize on the users row; overdraw blocked by in-lock balance check. Confirmed.

## INCONSISTENCIES CONFIRMED (all P3, latent/config-gated — no P0/P1)

### [P3] Standalone /loyalty/redeem writes an ORPHAN redeem (order_id=NULL) with NO reversal path
`LoyaltyController::redeem` debits `loyalty_points` immediately and inserts a `redeem` row with
`order_id=NULL` ("redemption is pre-order, no order_id yet", LoyaltyController.php:382).
- `refundPoints` (LoyaltyService.php:27) matches ONLY `where('order_id',$order->id)` → an order_id=NULL redeem is UNREACHABLE by any cancel/refund → **debited points can never be re-credited**.
- MySQL unique(user_id,order_id,type) does NOT dedupe NULL order_id → the "single redemption" invariant (LOCK §6.2) does not hold on this path; repeated calls each debit again.
- `applyKioskLoyaltyDiscount` only re-attaches a pending redeem when `created_at >= now()->subMinutes(10)` (FrontendOrderService.php:918). Pre-redeem older than 10 min → order-creation debits a SECOND time (fresh ledger with order_id), leaving the first orphan debit stranded.
- No cron cleans orphan redeems (grep app/Console → none).
Mitigant: current kiosk/web UI does NOT call /redeem (it uses setLoyalty→order-creation, `KioskLoyaltyComponent.applyLoyalty`); the endpoint is reachable with any kiosk:order or staff token. Latent, not exercised by shipped UI → P3.
Fix: retire /loyalty/redeem (dead vs order-creation path) OR make it order-scoped + reversible + min_redeem-checked, and add an orphan-redeem TTL sweeper.

### [P3] `loyalty_min_redeem_points` enforced on kiosk order-creation ONLY, not on POS nor /redeem
`DiscountCalculator::kioskLoyaltyRedemption:58-64` refuses `pointsRequired < min_redeem`.
`PosRedemptionService::applyToOrder` (85-106) and `LoyaltyController::redeem` (354-367) enforce
only multiple-of-rate + positivity — NO min_redeem check.
With default (min=50 < rate=100) the multiple-of-rate floor (100) masks it. But if an operator sets
`loyalty_min_redeem_points > rate` (e.g. 200), the SAME customer is refused a 100-pt redeem at the
borne yet allowed it at the caisse — surface-dependent behaviour on one shared setting.
Fix: centralize the min_redeem gate in a shared validator used by all three redeem paths.

### [P3 / improvement] Clawback clamp-at-0 lets earned-then-spent points survive a refund
`clawbackEarnedPoints` clamps `max(0, balance - amount)` (LoyaltyService.php:172). A customer who
EARNS N on an order, REDEEMS those N on a later order, then gets the first order REFUNDED → clawback
removes only what remains (possibly 0) → net free discount value from points earned on a refunded order.
Documented as V1.0.2 partial-refund deferral, but it is a genuine cross-path (accrual↔redeem↔refund)
value leak. Improvement: ledger-based net accounting instead of balance clamp.

## Verdict
Cross-surface loyalty is COHERENT for the order-linked redeem/refund/clawback paths (the shipped flows).
Incoherence is isolated to (a) the dormant standalone /redeem endpoint and (b) a config-gated
min_redeem divergence. No P0/P1. Three P3 latent items.
