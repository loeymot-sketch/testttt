# ADVERSARY re-verify — pricing-formule-422 (2026-07-09)

Goal: try to REFUTE the PASS verdict for menu-formula pricing exact + zero 422.
HEAD a693aa096 ; branch pos/category-first-caisse-2026-06-23.

## Independent reproductions (NEW orders, not the claim's 5618/5619/5620)

Live server http://127.0.0.1:8000, fresh kiosk:order token (user 3), matched-intent
quote+store (order_type=10, source=10, payment_method=5, branch 1), X-Idempotency-Key set.

Item: Cheese Burger item_id=98 (6.00) + item_variation 390 (Mayonnaise, mandatory sauce)
+ item_addon 81 role=menu_{full|frites|boisson}.

| variant      | quote HTTP | quote subtotal | order HTTP | order_id | order total |
|--------------|-----------|----------------|-----------|----------|-------------|
| menu_full    | 200       | 8.5            | 201       | 5626     | 8.5         |
| menu_frites  | 200       | 7.5            | 201       | 5627     | 7.5         |
| menu_boisson | 200       | 7.0            | 201       | 5628     | 7.0         |

Exact to the cent. Zero 422 on the valid complete-composition path.

## DB snapshot persistence (NF525) — order_items.composition_snapshot

- 5626 total_price 8.50 ; addon menu_full  unit_price 2.5 catalog_price 2.5
- 5627 total_price 7.50 ; addon menu_frites unit_price 1.5 catalog_price 2.5
- 5628 total_price 7.00 ; addon menu_boisson unit_price 1.0 catalog_price 2.5

## Code / config confirmation

- config('kiosk.menu_pricing') = {full_ratio:1, fries_ratio:0.6, drink_ratio:0.4}. No .env override.
- PricingService::menuRoleAdjustedAddonPrice('menu_full',2.5)=2.5 ; 'menu_frites'=1.5 ; 'menu_boisson'=1.0.
- Effective addon price 2.50 comes from Item id=1 (addonItem->price); ItemAddon.id=81 .price column is NULL,
  code correctly reads $dbAddon->addonItem?->price (PricingService.php:225-227). Claim wording "ItemAddon id=81 price 2.50"
  is imprecise (the 2.50 is the linked Item's price) but the resulting totals are exact — no defect.
- Mirrors: mobile/data/menu.js:236-238 (2.50/1.50/1.00) and /Users/1millnonstop/Downloads/web/data/menu.js:211-213 match canon.

## Minor cosmetic non-defects (no logic impact)
- Stale code comment near PricingService.php:207-217 references "price 3.00€" / "3.00×0.4=1.20" (old value; actual 2.50). Comment only.

## VERDICT
Could NOT refute. PASS confirmed by independent fresh reproduction. refuted=false, confidence high.
