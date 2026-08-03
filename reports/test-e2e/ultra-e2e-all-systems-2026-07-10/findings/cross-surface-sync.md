# Cross-Surface Sync — Real E2E Journey (BORNE → CAISSE / KDS / OSS)

HEAD `a693aa096` · branch `pos/category-first-caisse-2026-06-23` · 2026-07-10 · Laravel 9.52

## Real order placed via API (real quote→order pipeline)
- Minted real Sanctum `kiosk:order` token for kiosk machine 1 user (id=3, branch 1).
- `POST /api/frontend/order/quote` (x-api-key + Bearer) → signed quote (token+HMAC sig), subtotal 6 / total_ttc 6 / tax 0.55.
- `POST /api/frontend/order` with `order_type=10 (TAKEAWAY)`, `payment_method=1 (CASH_ON_DELIVERY)`, quote_token+quote_signature.
- Result: **order id=5631, queue=A0032, status=4 (ACCEPT), payment_status=15 (PENDING_COUNTER), pos_payment_method=6 (COUNTER_DEFERRED), order_type=10, source_surface=kiosk, total=6.00** — the Plan-B (`kiosk.payment_route_all_to_counter=true`) counter-deferred flow.
- Item: Cheese Burger (item_id 98) + Sauce Mayonnaise (variation 390). composition_snapshot frozen at 02:23:43.

Note: `order_type=25 (KIOSK / sur place)` is rejected in V1 ("service sur place désactivé — commandes borne à emporter"). Quote intent-hash binds `payment_method`, so the quote must carry the same payment_method as the order (else 401 "Order quote intent mismatch") — verified security behavior.

## 3-surface confirmation (order 5631, at status ACCEPT)
Queries run as branch-1 staff (caissier id=3 / chef id=4), executing the REAL surface code paths:

| Surface | Query source | Present | queue | total | composition |
|---|---|---|---|---|---|
| CAISSE (à encaisser) | `routes/api.php:815` closure (PENDING_COUNTER + kiosk + KIOSK/TAKEAWAY) | **YES** | A0032 | 6.00 | identical |
| KDS board | `KitchenDisplaySystemOrderService::list()` | **YES** | A0032 | 6.00 | byte-identical |
| OSS wall | `OrderStatusScreenOrderService::listForBranch(1)` | **NO** (at ACCEPT) | — | — | — |

Resource-level comparison (`OrderDetailsResource` vs `KDSOrderDetailsResource`), item 0:
- item_id 98 = 98, qty 1 = 1, price "6,00 €" = "6,00 €", total_price 6 = 6, item_name "Cheese Burger" = "Cheese Burger".
- composition_snapshot **BYTE-IDENTICAL**: `{"lines":[{"quantity":1,...,"variation_id":390,"attribute_name":"Sauce (1ère Gratuite)","variation_name":"Mayonnaise"}],"addons":[],"extras":[],"captured_at":"2026-07-10T02:23:43+02:00","schema_version":1}`.

## Lifecycle proof (OSS gate is status-based & consistent)
- OSS before chef bump (status ACCEPT): ABSENT.
- Chef bump ACCEPT(4)→PREPARING(7) via real `KitchenDisplaySystemOrderService::changeStatus()`: OK.
- OSS after bump: **PRESENT, queue A0032, status 7, total 6.00**.
- Public wall endpoint `GET /api/frontend/oss-order` (unauth, x-api-key) returns 5631 A0032 status 7 — customer wall works end-to-end.

## Queue integrity
- Today (2026-07-10) branch 1: 5631=A0032, 5632=A0033. Monotonic, no duplicates. Fresh business day resets to configured `kiosk.queue_start_number=32` (A0032). Yesterday's 5628=A0039 → new-day reset is by-design, not a regression.

## VERDICT
No price mismatch, no queue inconsistency, no missing-order divergence, no status desync for the live order across CAISSE/KDS/OSS. The composition_snapshot (NF525 frozen) is identical across surfaces. OSS differs by an intentional status gate (PREPARING+PREPARED only), confirmed consistent through the full lifecycle.

## Secondary divergences (see structured findings)
1. **OSS excludes ACCEPT** — a just-placed Plan-B takeaway is on CAISSE+KDS instantly but not on the customer OSS wall until the chef taps "start" (ACCEPT→PREPARING). By-design (OSS = en préparation/prêt), minor borne→wall journey gap. `OrderStatusScreenOrderService.php:63` `whereIn('status',[PREPARING,PREPARED])`.
2. **Zombie advance-order retention on OSS + KDS** — order 5399 (advance order is_advance_order=YES, PREPARED, PAID, **queue_number=NULL**, order_datetime 2026-07-02, 8 days old) is still on both the KDS board AND the public OSS wall. Rendered to customers via `CDSOrderDetailsResource` as `{"id":5399,...,"queue_number":null,"order_type":25,"status":8}` — a blank ticket number on the wall. Cause: advance-order window clause `order_datetime < tomorrowStart AND status NOT IN [DELIVERED,CANCELED]` has no aging-out for PREPARED advance orders (`OrderStatusScreenOrderService.php:122-124`, mirror in KDS service window). Likely test-data (`CARDTEST-…`) but the retention+null-queue rendering is real code behavior.
