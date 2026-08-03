# Menu-formula pricing exact + zero 422 — PROOF (2026-07-09)

Dimension: Menu-formula pricing exact + zero 422 (GOAL WEB+APP SYNC BORNE, converged 2026-07-08).
Verifier: real curl against live Laravel dev server http://127.0.0.1:8000 + DB row inspection.

## 1. Backend role handling — PricingService

- `app/Services/Pricing/PricingService.php:793` `menuRoleAdjustedAddonPrice($role,$fullPrice)`:
  role must start with `menu_`; ratio via `config('kiosk.menu_pricing')`:
  - `menu_full`  -> full_ratio  = 1.0
  - `menu_frites`-> fries_ratio = 0.6
  - `menu_boisson`-> drink_ratio= 0.4
  - `return round($fullPrice * $ratio, 2)`
- `app/Services/Pricing/PricingService.php:224-228` applies it to each addon line.
- `config/kiosk.php:167-170` and `:263-266` => full_ratio 1.0 / fries_ratio 0.6 / drink_ratio 0.4 (no .env override).
- DB catalog: Cheese Burger item_id=98 price 6.00; ItemAddon id=81 addon_item_id=1 "Menu (Frites + Boisson)" price 2.50.
- Math: full 2.50*1.0=2.50 ; frites 2.50*0.6=1.50 ; boisson 2.50*0.4=1.00.

## 2. Real API orders (Cheese Burger, complete composition = sauce id=390 Mayonnaise)

Endpoint quote: POST /api/frontend/order/quote ; store: POST /api/frontend/order
Auth: x-api-key + Bearer kiosk:order token (user_id 3, kioskMachine kiosk-lecayenne, branch 1).
order_type=10 TAKEAWAY (KIOSK=25 blocked by V1 dine-in rule, by-design), source=5.

| variant       | role         | quote HTTP | order HTTP | order_id | subtotal | total |
|---------------|--------------|-----------|-----------|----------|----------|-------|
| menu full     | menu_full    | 200       | 201       | 5618     | 8.50     | 8.50  |
| menu + frites | menu_frites  | 200       | 201       | 5619     | 7.50     | 7.50  |
| menu + boisson| menu_boisson | 200       | 201       | 5620     | 7.00     | 7.00  |

Deltas from base 6.00: full +2.50 -> 8.50 ; frites +1.50 -> 7.50 ; boisson +1.00 -> 7.00. EXACT to the cent.
Zero HTTP 422 on the valid complete-composition path (0 of 6 valid-path httpcode files contain 422).

Note: an INCOMPLETE composition (no sauce) correctly returns 422 "Selectionnez au moins 1 Sauce"
(wizard-profile mandatory-group validation) — by-design, not a pricing defect.

## 3. DB persistence (NF525 composition_snapshot SSOT) — order_items.order_id

- order 5618: item_id 98 price 6.00 total_price 8.50 ; snapshot addon menu_full unit_price 2.50 line_total 2.50 (catalog_price 2.50)
- order 5619: item_id 98 price 6.00 total_price 7.50 ; snapshot addon menu_frites unit_price 1.50 line_total 1.50 (catalog_price 2.50)
- order 5620: item_id 98 price 6.00 total_price 7.00 ; snapshot addon menu_boisson unit_price 1.00 line_total 1.00 (catalog_price 2.50)

## 4. Web + mobile mirrors match backend canon

- mobile/data/menu.js:236 f-menu 2.50 ; :237 f-frites 1.50 (comment "role menu_frites = +1,50 canon PricingService SSOT, e2e W6 — etait 2,00 FAUX") ; :238 f-boisson 1.00 (menu_boisson +1,00).
- /Users/1millnonstop/Downloads/web/data/menu.js:208 comment "menu_full +2,50 / menu_frites +1,50 / menu_boisson +1,00" ; :211 f-menu 2.50 ; :212 f-frites 1.50 ; :213 f-boisson 1.00.

VERDICT: PASS — deltas match to the cent, backend + both mirrors aligned, zero 422 on valid path.
