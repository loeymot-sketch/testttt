# VERIFY — dimension: integrity — 2026-07-09

## F1 loyalty-redeem-helper-nonmultiple (P2 → DOWNGRADED P3)
- data/loyalty.js:72-75 redeemableEuros = Math.round((points/100)*100)/100 → redeemableEuros(250)=2.50, redeemableEuros(150)=1.50 (non-multiple).
- data/loyalty.js:83-95 redeem(): pointsUsed = Math.round(granted*redeem_ratio) → 150 pts → euros 1.50 → pointsUsed 150 (non-multiple of 100).
- Backend rejects non-multiples: api.js:509 "utilisez un multiple de 100", apiContract.js:122 "Body {code, points:<multiple de 100>}".
- Live UI FLOORS correctly and never calls the helper: screens.jsx:606 `Math.floor(points/redeemRate)*redeemRate`.
- Broad grep `LC.loyalty.redeem | .redeemableEuros(` across *.jsx *.js → ZERO call sites. Helper is export/node-test only.
- Header line 7 documents linear "250 pts = 2,50 €" as intentional mobile-parity (D6=A) — so partly by-design; zero live user impact. DOWNGRADE to P3 (latent future-wireup trap in dead-to-UI code).

## F2 delivery-fee-estimate-vs-ssot (P2 → DOWNGRADED P3)
- funnel.jsx:460 deliveryFee = ctx.deliveryQuote.estimate.fee; :461 total = subtotal - discount + deliveryFee; :607 CTA `<b>{total}</b>` "Payer".
- Estimate api.js:47-54 fee = base + perKm*ceil(d-freeKm), km-bracketed (ceil).
- Order sends indicative charge: funnel.jsx:388 deliveryCharge = dq.estimate.fee; api.js:436-443 comment "backend RECALCULE ... delivery_charge envoyé = indicatif".
- Confirmation reads backend total: funnel.jsx:392 `total = Number(order.total...)`, :396 orderTotal; ConfirmationPage:626 uses ctx.orderTotal.
- WEAKENING FACT: funnel.jsx:383 api.saveAddress({latitude: dq.lat, longitude: dq.lng}) persists the CLIENT geocoded coords; backend recomputes from that SAME address_id/coords with "même formule" (api.js:56). So a divergence requires backend to re-geocode independently — NOT demonstrated. Concrete 1€-flip repro is speculative. No financial harm (counter payment, confirmation authoritative). DOWNGRADE to P3 (display-consistency, unproven divergence).

## F3 styled-frites-silent-fallback (P3 CONFIRMED)
- menu.js:515 cart line adds style price: `if (opts.fritesStyleId) total += fs.price`.
- api.js:264-270 resolveLine: styled SKU resolved by name; IF skuId==null, resolveTarget stays base line and style carried by NO variation/extra/addon → backend charges base only while cart showed base+style (undercharge).
- Silent fallback (no error) is a definite robustness gap. Actual SKU-gap existence not enumerated → undercharge is conditional. CONFIRM at P3.

## F4 tracking-earn-round-vs-floor (P3 CONFIRMED, dead)
- funnel.jsx:775 `+{ctx.earnedPoints != null ? ctx.earnedPoints : Math.round(total)} pts` — fallback uses Math.round.
- Every earn site floors (funnel.jsx:399 earnedPoints=Math.floor(total*ppe), :540, loyalty.js:68).
- earnedPoints ALWAYS set at :399 before TrackingPage → fallback dead in practice. CONFIRM P3 (latent inconsistency).

## F5 confirmation-double-hash-orderid (P3 CONFIRMED, rare)
- funnel.jsx:393 orderId = order.order_serial_no || ('#' + order.id).
- ConfirmationPage:626 orderId = ctx.orderId; :648 renders `#{orderId}` → "##123" when serial null; :655 ticket `{orderId}` → "#123".
- TrackingPage:740 strips leading # (`.replace(/^#/,'')`); ConfirmationPage does not. Cosmetic, conditional on null order_serial_no. CONFIRM P3.
