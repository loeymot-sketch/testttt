# CYCLE 2 — Cross-Surface Sync Convergence Check (2026-07-10)
HEAD a693aa096 · branch pos/category-first-caisse-2026-06-23 · servers :8000/:8766 LIVE · MySQL live

## Real kiosk order placed (full HTTP quote→order pipeline, composed item)
- Minted real Sanctum `kiosk:order` token (user 9 / KioskMachine 3 / branch 1).
- POST /api/frontend/order/quote (x-api-key + Bearer) → quote_token + HMAC signature, subtotal 7,90 / total_ttc 7,90 / tax 0,72.
- POST /api/frontend/order (order_type=10 TAKEAWAY, payment_method=1 CASH_ON_DELIVERY, quote_token+signature, X-Idempotency-Key) → **HTTP 201**.
- **Order id=5633, queue=A0034, total=7,90, status=4 ACCEPT, payment_status=15 PENDING_COUNTER, pos_payment_method=6 COUNTER_DEFERRED, order_type=10, source_surface=kiosk** (Plan-B counter-deferred).
- Composed item: Tacos L (item 97, 7,90) + Viande 1 Mexicanos (var 361) + Viande 2 Mexicanos (var 368) + Sauce Mayonnaise (var 375). composition_snapshot frozen at 07:33:07.

## 3-surface confirmation (order 5633)
| Surface | Source path | Present | queue | total | composition |
|---|---|---|---|---|---|
| CAISSE (à encaisser) | routes/api.php:815 closure (PENDING_COUNTER + kiosk + KIOSK/TAKEAWAY) | YES | A0034 | 7,90 | identical |
| KDS board | KitchenDisplaySystemOrderService::list() | YES | A0034 | 7,90 | BYTE-IDENTICAL |
| OSS wall | OrderStatusScreenOrderService::listForBranch(1) @ ACCEPT | NO (by-design gate) | — | — | — |
| OSS wall (after chef bump ACCEPT→PREPARING) | listForBranch(1) @ status 7 | YES | A0034 | 7,90 | — |
| OSS public HTTP | GET /api/frontend/oss-order?branch_id=1 | YES (after bump) | A0034 | — | — |

- Resource byte-compare KDSOrderDetailsResource vs OrderDetailsResource, item 0: item_name/price(7,90 €)/total_price(7.9)/3 variations MATCH, composition_snapshot **BYTE-IDENTICAL** (529 bytes).
- Queue integrity today branch1: 5631=A0032, 5632=A0033, 5633=A0034 — monotonic, **no duplicates**.
- NF525: audit_logs=4938 / z_reports=25 unchanged (Plan-B ACCEPT/PENDING_COUNTER does not seal fiscal seq — correct).

## Verdict: NO price mismatch, NO queue inconsistency, NO missing-surface divergence. OSS differs only by the documented status gate (PREPARING+PREPARED), consistent through full lifecycle.

## Healed P0/P1 — VERIFIED GONE
- **POS-1 (P1, free-delivery quote 409)**: PosFreeDeliveryQuoteSealTest **PASS**. Heal in OrderQuoteService::calculatePricing:317-341 is a faithful mirror of OrderService:860-864 — same `order_type===DELIVERY && freeAbove>0 && accumulatedSubtotal>=freeAbove && deliveryCharge>0` (`>=` + accumulatedSubtotal, no boundary drift). Kiosk path unaffected (early return line 301). No new regression.
- **OSS-01 (P2, empty-wall lines)**: PreparingAndReadyComponent.vue::_hydrateFromRows now filters rows lacking queue_number AND token. Vitest orderStatusScreenOssSync + ossChimePublicWall **8/8 PASS**.

## Residual real divergence (PRE-EXISTING P2, NOT introduced by the heals)
Zombie advance order **5399** (is_advance_order=YES, status 8 PREPARED, PAID, **queue_number=NULL**, order_datetime 2026-07-02, 8 days old, serial CARDTEST-*) is STILL returned by:
  - Public wall HTTP `GET /api/frontend/oss-order?branch_id=1` → `{"id":5399,"queue_number":null,"status":8,"order_type":25}` (blank ticket number).
  - KDS board list() (present in ids alongside 5633).
The OSS-01 Vue heal hides it on the customer Vue wall (`_hydrateFromRows` filter) but the BACKEND services (OrderStatusScreenOrderService listForBranch/list :122-124,:252-256 + KitchenDisplaySystemOrderService window :146-149) have no aging-out for PREPARED advance orders → any raw-API consumer still sees the null-queue leak. Largely test data. frozen:false. **Same finding as cycle 1 (SYNC-ZOMBIE-ADVANCE) — not a NEW P0/P1.**

## NEW P0/P1: NONE.
