# Web standalone — DATA / NUMERIC INTEGRITY & POLISH audit (2026-07-09)

Target: /Users/1millnonstop/Downloads/web (React+Babel-in-browser). Backend live @ :8766.

## Headline: NO P0, NO P1.
- **Price integrity (P0 axis) is SOUND.** The web NEVER sends a price to the backend
  (`api.js:8` "on n'envoie jamais de prix client"; order body `api.js:428-435` carries only
  item_id/variations/extras/addons). The backend recomputes via PricingService (SSOT). The
  **confirmation total reads the real backend value** `order.total`
  (`funnel.jsx:391` → `ctx.orderTotal` → `ConfirmationPage funnel.jsx:626/663`), and
  `order.total` is a verified field of `OrderDetailsResource.php:50`
  (`round((float)$this->total,2)`) returned by `Frontend/OrderController::store` (line 54).
  → displayed-at-confirmation == charged == backend. No "display total ≠ charged total".
- **Loyalty live math is correct.** Earn = `Math.floor(total × ppe)` everywhere
  (funnel.jsx:131/399/542, flows.jsx:130) — matches backend "1pt/€ floor du TTC"
  (apiContract.js:110). Redeem UI floors to a multiple of 100 before sending
  (`screens.jsx:605-609,623`): `usablePoints = Math.floor(points/redeemRate)*redeemRate`,
  guarded by `>= min_redeem_points`. 150→100, 250→200, 99→locked. No off-by-one.
  Backend config confirms 1 / 100 / 100 (curl /loyalty/config).
- **Formatting clean.** French comma via `.replace('.', ',')` on every money site; all
  `toFixed` operands are guaranteed numbers or guarded (`data/loyalty.js:99` isFinite;
  `upsell.jsx:13` Number()||0; `orders.jsx:22` parseFloat||0). No `0undefined`/NaN leak.
  `total_amount_price` = `AppLibrary::flatAmountFormat` = `number_format(.,2,'.','')`
  (dot-decimal) so `parseFloat` in orders.jsx:22 is safe (no comma truncation).
- **i18n / raw labels: none.** Site is hardcoded FR; no i18n framework, no `Label.x`/`kiosk.foo`.
- **Menu parity OK.** Cayenne 7,40 · Cheese Burger 6,00 · Big Burger 9,00 (menu.js:363/402/410)
  match canonical. Formule surcharges full/frites/boisson = 2,50/1,50/1,00 (menu.js:211-213)
  match backend menu_component roles (api.js:347). Drink catalogue prices == priceForDrinkAddon
  (coca 1,90 / eau 1,00 / capri 1,50 — menu.js:344-353 vs 464-471). Frites styles +1,00/+2,00
  reconcile to the styled SKU price (menu.js:216-219).
- **No console.* , no TODO/FIXME** on any money path.

## Real findings (all P2/P3 — latent, no active overcharge)

### P2-1 data/loyalty.js redeem()/redeemableEuros() contradict the backend rule (and their own doc)
`data/loyalty.js:72-95`. `redeemableEuros(250)` → 2.50 and `redeem()` computes
`pointsUsed = Math.round(granted*100)` → allows **non-multiples of 100** (150 pts → 1,50 €).
The backend REQUIRES multiples of 100 (api.js:509 "utilisez un multiple de 100";
apiContract.js:122). The file header even claims "parité mobile exacte : 250 pts = 2,50 €",
which directly contradicts the live UI that correctly floors 250→200→2,00 (screens.jsx:606).
These helpers are **exported + node-tested but UNUSED by any live component**
(grep: no `.redeem(`/`redeemableEuros(` in any *.jsx) — so no user impact today, but a
wireup trap: any future caller sending `redeem()`'s pointsUsed gets a backend 400.

### P2-2 Delivery fee shown pre-submit is a client haversine ESTIMATE; backend recomputes (SSOT)
`funnel.jsx:334/460-461/607` show `dq.estimate.fee` from `api.js:47-53 deliveryEstimate`
(client Nominatim geocode → haversine, then `base + perKm*ceil(d-freeKm)`). The order sends
`delivery_charge` as "indicatif" and the backend RECALCULATES from address_id
(api.js:436-443 comment; funnel.jsx:381-389). Because the fee is km-bracketed with `ceil`,
a small geocoder coord difference near a km boundary flips the fee by 1 €, so the
**payment-page "Payer X €" can differ from the confirmation total** (which reads backend
`order.total`). Not an overcharge (confirmation is authoritative, payment is at counter), but
the committed number at the pay step may not equal the final one for delivery orders.

### P3-1 Styled-frites silent fallback → pre-submit display can exceed charged
`api.js:265-273`. A styled frites (e.g. state.frites_style='fs-cheddar') is re-targeted to a
separate SKU by name; if `resolveItemId({name: base+' '+style})` returns null (SKU missing for
that base+style, e.g. "Petite Frites Cheddar fondu"), `resolveTarget` stays the base line and
the style price is **dropped from the order** (no variation/extra/addon carries it). The cart
line price already added `fritesStyle.price` (menu.js:515), so web shows base+style but the
backend would charge base only. Undercharge (display > charged); conditional on a backend SKU
gap I did not enumerate.

### P3-2 TrackingPage earn fallback uses Math.round, not floor
`funnel.jsx:775`: `ctx.earnedPoints != null ? ctx.earnedPoints : Math.round(total)`. Every
other earn site floors. Fallback only fires if earnedPoints is null (it is always set at
placeRealOrder funnel.jsx:399), so effectively dead, but if reached it can show +1 pt vs the
backend floor (e.g. total 12,60 → Math.round 13 vs backend 12).

### P3-3 ConfirmationPage order id can double-hash
`funnel.jsx:627/643`: `orderId = ctx.orderId || 'C-0000'` then rendered `#{orderId}`. When the
serial is null the id is set to `'#'+order.id` (funnel.jsx:393), yielding `##123`. Cosmetic;
TrackingPage strips it (funnel.jsx:740) but ConfirmationPage does not. order_serial_no is
normally present so rarely hit.

### P3-4 wizard-v2 fmt() has no NaN guard (inconsistent with upsell fmt)
`wizard-v2.jsx:390,649`: `const fmt = (n) => n.toFixed(2)...` — no `Number()||0` guard unlike
`upsell.jsx:13`. All current call sites pass numbers, so latent only.

### P3-5 Dead fallback: order.convert_price
`funnel.jsx:391` `order.total != null ? order.total : (order.convert_price || 0)` — backend
OrderDetailsResource always returns `total`, so the `convert_price` branch is unreachable
(harmless dead code).
