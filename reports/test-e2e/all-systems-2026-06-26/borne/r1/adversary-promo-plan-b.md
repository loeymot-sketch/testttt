# Adversary verdict — BORNE / Panier + loyalty + paiement Plan-B

## Finding under review
[P1 candidate] `app/Services/Kiosk/KioskPromoService.php:76` (+ `resources/js/store/modules/kioskCart.js:142-158`, `app/Services/Order/OrderQuoteService.php:288-301`) — Code promo affiché « −X € » au panier borne mais JAMAIS appliqué au total payé.

## VERDICT: REAL — but severity downgraded P1 → **P2** (mauvaise UX client réelle, PAS perte d'argent / PAS NF525). Heal NON-frozen disponible.

---

## Re-verification (every claim re-Read + re-reproduced live on foodking_e2e :8766)

### Static (code) — all confirmed
- `KioskPromoService::validate` (`:36-87`) returns a real `discount_amount` from the global `coupons` fallback (`:61-86`) when `kiosk_promos` has no match.
- `kioskCart.js buildKioskQuotePayload` (`:147-158`) sends `kiosk_promo_code` + `loyalty_code` ONLY. **No `coupon_id` / `coupon_code`** — `grep coupon_id resources/js/store/modules/kioskCart.js` + `resources/js/components/frontend/kiosk/**` = EMPTY.
- `OrderQuoteService::calculatePricing` kiosk branch (`:288-301`) feeds `PricingRequest::forKiosk(... (int)$request->input('coupon_id',0) ...)` — promo code never priced.
- `OrderQuoteService::canonicalPayload` (`:496`) places `kiosk_promo_code` into `discounts.promo_code` = **HMAC metadata only**, never a monetary input.
- **Order-create path** `app/Services/FrontendOrderService.php`: `grep kiosk_promo_code|promoCode|KioskPromoService` = **NO MATCH** (exit 1). Kiosk SSOT call `:278-288` passes only `(int)$request->coupon_id`. So the promo is dropped at BOTH quote and order-create.
- Cart UI promo input is gated by `discountsEnabled` (`KioskCartComponent.vue:275`, computed `:444-448` ← `window.foodkingConfig.discountsEnabled` ← `master.blade.php:174` = `config('pos.manual_discount_enabled', false)`).
- Payment screen total = `KioskPaymentComponent.vue:328` `cartTotal() { return this._lastQuote?.total_ttc ?? this.total; }` → uses the AUTHORITATIVE quote total, not the cart's promo-discounted local total.

### Runtime (live foodking_e2e)
- `config('pos.manual_discount_enabled')` = **true** → promo input **IS shown** on the kiosk. (Refutation attempt FAILED here — if it were false the finding would be moot.)
- `SELECT COUNT(*) FROM kiosk_promos` = **0** → every "valid" kiosk code today resolves via the global coupons fallback.
- Coupon `WVALFIX5` = id 9, discount_type 5 (→ flat amount branch), discount 5.00, status 5, window 2026-06-01..2026-12-31.

### End-to-end reproduction (real `OrderQuoteService::quote`, kiosk machine id=1 / branch 1 / status 5, cart = 6 × « Petite Frites Cheddar fondu » 3,50 € = 21,00 €)
```
KioskPromoService::validate(1,'WVALFIX5',21.0) => valid=true source=coupon discount_amount=5   (cart shows -5,00 €, local total 16 €)
QUOTE_A (kiosk_promo_code='WVALFIX5', i.e. the REAL kiosk payload) => subtotal=21.00 discount=0.00 total_ttc=21.00   ← payment screen charges 21 €
QUOTE_B (coupon_id=9, which the kiosk NEVER sends)               => subtotal=21.00 discount=5.00 total_ttc=16.00   ← proves the rebate machinery works, just unwired
```

## Harm direction (decisive for severity)
- Customer sees `-5,00 €` and "Total 16,00 €" on the **cart** (local getter `kioskCart.js:250-253` subtracts `promoDiscount`).
- On **payment**, `cartTotal = quote.total_ttc = 21,00 €` → Plan-B routes **21 €** to the counter.
- Promo silently dropped: NO toast (there is a loyalty silent-drop toast `KioskPaymentComponent.vue:471-478`, but **none for promo**). Order still succeeds at 21 € (server SSOT) — no 422, no lost sale, no underpayment.
- ⇒ **The customer is charged the correct authoritative backend price (21 €) despite the cart promising 16 €.** Misleading promise / broken trust. **NOT** a money leak (books never undercharged), **NOT** an NF525/chain issue (quote + order + Z all coherent at 21 €).

Under the V1-LOCAL severity rubric ("client charged the correct authoritative price, no money/fiscal loss" = bad UX, not money-loss): **P2**, not P1.

## Heal (NON-frozen, safe)
Frozen SHA baseline (`tests/Feature/Sentinels/frozen-zone-sha256-baseline.json`) contains `KioskWizardComponent.vue`, `KioskAppComponent.vue`, `KioskUpsellComponent.vue`, `PricingService.php` — but **NOT** `KioskCartComponent.vue`, `kioskCart.js`, `master.blade.php`. `FrontendOrderService`/`OrderQuoteService` are SHARED-zone (LOCK+gate) but are **not** needed for the safe fix.

- **Option B (recommended, ship-now, zero frozen/shared):** stop the kiosk from promising an un-honored discount. The `discountsEnabled` flag already exists for exactly this "F1-dormancy" purpose (it hides the loyalty entry too). Either flip `config('pos.manual_discount_enabled')` to false for this single-box V1, or scope a kiosk-specific gate so `KioskCartComponent.vue:275/328` hide the promo+loyalty entries. TDD: a Vitest assertion that with `discountsEnabled=false` the `[data-testid=kiosk-cart-promo]` block is absent (mirror the existing loyalty-button gate test).
- **Option A (business decision required):** actually honor kiosk promos by converting a validated code → `coupon_id` in `buildKioskQuotePayload` (`kioskCart.js`, non-frozen) — the backend already prices `coupon_id` correctly (QUOTE_B proves it). Confirm with owner whether V1 LOCAL wants kiosk discounts at all.

No frozen-zone edit is required for the safe heal.
