# K07 — Cart + Bottom-Sheet

## Files audited
- `resources/js/components/frontend/kiosk/KioskCartComponent.vue` — 1235 lines
- `resources/js/components/frontend/kiosk/ds/KsCartBottomSheet.vue` — 467 lines
- `resources/js/store/modules/kioskCart.js` — 835 lines

Caller of bottom-sheet : `KioskCategoriesComponent.vue:229-234` (welcome screen).

## Findings

### P0 (blocker pre-merge V1)

- **K07-P0-01: Pricing fields posted to `/frontend/order` (quote_token gate is the only guard).**
  - File: `resources/js/store/modules/kioskCart.js:170-177`
  - Issue: `buildKioskOrderPayload` injects `subtotal`, `discount`, `delivery_charge`, `total` into the POST body. These are read directly from the frontend `quote` object returned by `/frontend/order/quote`. If a backend regression in `FrontendOrderController` ever falls back to client values instead of recomputing them via `PricingService::calculateOrder`, the NF525 SSOT invariant breaks silently — kiosk has no test pinning "backend recompute even when client price fields present".
  - Evidence: lines 171-176 set `payload.subtotal = quote.subtotal; payload.discount = quote.discount; payload.delivery_charge = quote.delivery_charge; payload.total = quote.total_ttc;`. The quote IS HMAC-signed (`quote_token + signature`), so today this is mitigated by signature verification server-side, but the contract still exposes price fields to client tampering before signature check.
  - Suggested fix: a contract test (PHPUnit) asserting `Order::total_amount` ≠ posted `total` when payload is forged with bogus values (verifies backend recompute path), and a Vitest snapshot pinning the payload key list. If accepted as-is, document the trust chain in a `docs/PRICING_SSOT.md` paragraph "Why quote-signed price fields are OK".
  - Severity: **P0 contract-test gap** (not a live bug today, but no regression net).

### P1 (high — V1.0.1 sprint)

- **K07-P1-01: Bottom-sheet visibility uses distinct-lines count, header badge uses Σqty.**
  - File: `KsCartBottomSheet.vue:11` and `:139-141`
  - Issue: `v-if="items.length > 0"` (visibility) vs `totalCount = Σqty` (badge). If a future flow allows zero-qty placeholders (it shouldn't but ADD_ITEM clamps to ≥1, so OK today), the sheet would hide despite `totalCount>0`. Mild — but more importantly the welcome bottom-bar badge `kiosk-categories-cart-indicator` uses `kioskCart/count` (Σqty). The strip thus hides only on `items.length === 0` which mirrors store `isEmpty` getter — semantically aligned today, but **the two counters use different sources**. Maintenance risk if quantity normalization rules diverge.
  - Evidence: `kioskCart.js:219` getter `count: (state) => state.items.reduce((sum, i) => sum + i.quantity, 0)` ; `KsCartBottomSheet.vue:139-141` `this.items.reduce((sum, i) => sum + (Number(i.quantity) || 0), 0)`. The strip re-derives instead of receiving `count` as prop.
  - Suggested fix: pass `count` as a prop from `KioskCategoriesComponent` (already maps `cartCount`) and drop the local re-computation. Single source of truth.

- **K07-P1-02: Hard-coded brand color `#F4501E` instead of palette token — design drift vs owner spec.**
  - File: `KsCartBottomSheet.vue:278, 323, 374, 411-413, 421, 425` and `KioskCartComponent.vue:816-817, 865, 924, 980, 1018, 1056, 1067-1068`
  - Issue: Owner project memo (`project_kiosk_design_refresh_2026-05-10.md`) defines palette **noir/rouge/jaune/blanc**. `#F4501E` is orange-red (Tailwind orange-600 territory), not the "rouge" of the official palette. Direct hex literals bypass the CSS custom property layer (`--kiosk-primary`, `--kiosk-bold-primary`) and prevent theme retuning.
  - Evidence: CSS literals `background: #F4501E` (strip count badge line 278), `color: #F4501E` (strip price 374), trash hover `color: var(--kiosk-bold-primary, #F4501E)` (cart 924). The token fallbacks all default to `#F4501E`, which propagates the drift.
  - Suggested fix: confirm with owner the exact red hex (the memo doesn't pin it). Replace all hex literals with `var(--kiosk-primary)` and update `useKioskTheme` token. If `#F4501E` IS the chosen red, document it in the design memo to align language.

- **K07-P1-03: A11y — quantity span in bottom-sheet lacks `aria-live`; cart page has it.**
  - File: `KsCartBottomSheet.vue:66-69`
  - Issue: The strip quantity (`<span class="kiosk-cart-strip-quantity">{{ item.quantity }}</span>`) has no live-region announcement. In the dedicated cart page line 193-196 the same span DOES carry `aria-live="polite"`. Customer pressing +/- on the welcome strip won't hear the new count.
  - Suggested fix: add `aria-live="polite"` to `kiosk-cart-strip-quantity`, and a `:aria-label="$t('kiosk.qty_now', { n: item.quantity })"` for sighted-only fallback.

- **K07-P1-04: RTL — strip stepper has no `direction: ltr` override; +/- order flips in Arabic.**
  - File: `KsCartBottomSheet.vue:381-389` (`.kiosk-cart-strip-qty`)
  - Issue: The cart page protects the stepper with `[dir="rtl"] .kiosk-qty-ctrl { direction: ltr }` (cart line 946-948). The bottom-sheet stepper has NO such rule. In AR mode the strip will render `+ N −`, breaking muscle memory and contradicting cart-page UX. Owner i18n: AR RTL supported, EAA 2025.
  - Suggested fix: add `[dir="rtl"] .kiosk-cart-strip-qty { direction: ltr; }` (mirror the cart rule).

- **K07-P1-05: `ADD_ITEM` merge key uses `JSON.stringify` of arrays — order-fragile equality.**
  - File: `kioskCart.js:258-263`
  - Issue: Merge condition compares `JSON.stringify(i.item_variations) === JSON.stringify(item.item_variations)`. If the wizard or `replaceEditingCartItem` reconstructs variations in different array order (e.g. user picks Sauce → Pain → Garniture vs Pain → Sauce → Garniture, then a re-edit), two semantically identical lines will fail to merge → fragmented cart, broken upsell logic that joins on `item_id`.
  - Suggested fix: canonicalize before stringify — `JSON.stringify([...arr].sort((a,b)=>a.id-b.id))`. Or hash a stable composite key.

- **K07-P1-06: `removeItemDirectly` shows a toast but owner banned add-to-cart toast — inconsistency rationale should be documented.**
  - File: `KioskCartComponent.vue:540-548`
  - Issue: Cart-page trash button fires `showToast` ("Article supprimé"), while bottom-sheet decrement (treated as delete when qty=1) emits NO toast — handled in `KioskCategoriesComponent.decrementCartItem` (line 662-671) with zero feedback. Two paths, two UX behaviors. The cart-page comment claims "explicit action → toast OK", but customers expect symmetry.
  - Suggested fix: align on ONE behavior (preferred per owner memo: no toast). The bottom-sheet trash icon already gives visual feedback (item disappears).

### P2 (medium — backlog)

- **K07-P2-01: `:key="idx + '-' + item.item_id"` in strip uses index prefix.**
  - File: `KsCartBottomSheet.vue:27`
  - Issue: After a middle-line removal, all subsequent keys shift → unnecessary DOM destroy/create + image reload. Cart page already migrated to composite key (cart line 119). Strip lagging behind.
  - Suggested fix: use `item.item_id + '-' + (line variation hash)`. Or `item._cartLineId` if you introduce stable line ids in `ADD_ITEM`.

- **K07-P2-02: `truncate(name, 22)` is character-length-based, breaks on CJK / wide glyphs.**
  - File: `KsCartBottomSheet.vue:168-171`
  - Issue: 22 ASCII chars fit; 22 emoji or 22 Arabic chars overflow visually. Owner FR-lock today, but AR RTL is supported.
  - Suggested fix: CSS clamp via `-webkit-line-clamp: 1` + `text-overflow: ellipsis` (already declared at line 367-370 — so the JS truncate is redundant). Drop the JS, rely on CSS overflow.

- **K07-P2-03: Bottom-sheet `position: absolute; bottom: 118px` collides if `--kiosk-bottom-bar-h` changes.**
  - File: `KsCartBottomSheet.vue:243-246`
  - Issue: Strip is positioned absolutely against the closest positioned ancestor. If the parent kiosk shell loses `position: relative`, the strip sticks to viewport bottom 118px, overlapping bottom-bar. The `var(--kiosk-bottom-bar-h, 118px)` fallback hardcodes the height.
  - Suggested fix: position relative to `KioskCategoriesComponent` root explicitly (`.kiosk-categories-root { position: relative }` check). Define `--kiosk-bottom-bar-h` centrally in `:root` (welcome theme) instead of per-component fallback.

- **K07-P2-04: `quoteOrder` 429 toast uses `window.__appI18n` reach-around.**
  - File: `kioskCart.js:636-650`
  - Issue: Vuex store reaches into global `window.app` devtools record to grab `$i18n`. Fragile (prod build strips devtools), hard to unit-test.
  - Suggested fix: inject i18n into the store at boot (`store.$i18n = i18n`), or move the toast call to the caller (component layer).

- **K07-P2-05: `editingCartIndex` snapshot is a deep clone via `JSON.parse(JSON.stringify(...))`.**
  - File: `kioskCart.js:315`
  - Issue: Loses non-JSON types (Date, undefined, Function refs). Cart items are JSON-safe today, but if `_wizardSelections` ever holds a Set/Map it silently drops.
  - Suggested fix: `structuredClone()` (Chrome 98+, ok for kiosk hardware).

### P3 (low)

- **K07-P3-01: Promo `cart_total` sent server-side but server recomputes — minor over-fetch.** kioskCart.js:564-567. Acceptable per inline comment.
- **K07-P3-02: `formatPrice` reads currency from `globalState.lists` mixin — coupling.** KioskCartComponent.vue:619. Acceptable, common pattern.
- **K07-P3-03: `_inFlightKioskLogin` module-scoped guard.** kioskCart.js:20. Documented; OK.

## Owner design drift summary

| Spec (memo 2026-05-10) | Code state | Verdict |
|---|---|---|
| Bottom-sheet persistent on welcome | `v-if="items.length > 0"` on KsCartBottomSheet | OK |
| Image + name + +/- + trash per line | cart page YES (1235); strip YES (467) — strip uses `−` morphing into trash when qty=1 | OK |
| Recap grouped by section with controls | Cart page IS flat list (no grouping by section). Memo says "grouped by section" | **drift P1** (no group-by-category) |
| Palette noir/rouge/jaune/blanc | `#F4501E` orange-red literals everywhere | **drift P1** (color drift K07-P1-02) |
| Flat, not marketing-heavy | Trash btn is `position: absolute; top: -8px; right: -8px` — slight stylized "tag" feel | Acceptable |

## Existing E2E / unit coverage

- `tests/js/KioskCartRestyle.spec.js` — testid presence, empty-state, order-type radio, clear modal flow, qty-minus click, loyalty discount line
- `tests/js/kioskCartSendPayload.spec.js` (78 lines) — order POST payload shape
- `tests/js/kioskCartPromo.spec.js` (143 lines) — promo validate/apply flow
- `tests/js/kioskCartOfflinePaymentScope.spec.js` — offline electronic payment rejection
- `tests/js/KioskCategoriesRestyle.spec.js` — welcome shell tests

## Proposed new E2E tests

- **T-K07-01: SSOT contract — backend ignores client-posted `total`.**
  - Steps: build kiosk cart, intercept `/frontend/order/quote` to mutate `total_ttc` upward; submit order; assert `Order::total_amount` from response equals backend recompute via Sanctum read-back.
  - Assertions: `response.data.total !== payload.total` when forged.

- **T-K07-02: `ADD_ITEM` merge resilience to variation order.**
  - Steps (Vitest): commit ADD_ITEM with `item_variations=[{id:1},{id:2}]`, then ADD_ITEM same `item_id` with `[{id:2},{id:1}]`.
  - Assertions: cart has ONE line with quantity=2 (currently fails — fragments into 2 lines).

- **T-K07-03: Bottom-sheet RTL stepper integrity.**
  - Steps (Playwright AR): seed cart with 2 items, switch `dir="rtl"` via `useKioskA11y`, screenshot strip stepper.
  - Assertions: `+` button on the right visually, `−` on the left (LTR direction inside stepper).

- **T-K07-04: Bottom-sheet quantity announce on increment.**
  - Steps (Playwright + @axe-core): click increment on `kiosk-cart-bottom-sheet-increment-0`, capture aria-live region.
  - Assertions: a node with `aria-live="polite"` updates with new quantity.

- **T-K07-05: Quote token recomputation on cart mutation.**
  - Steps (Vitest): seed orderQuote via SET_ORDER_QUOTE; commit ADD_ITEM; assert `orderQuote === null`.
  - Assertions: `state.orderQuote` cleared by ADD_ITEM/UPDATE_QUANTITY/REMOVE_ITEM/SET_PROMO. (Currently OK — pin it.)

## Risks & open questions

- **Owner gate on "recap grouped by section"** — does owner consider the current flat list a regression vs the memo wording? Verify before sprint planning.
- **Owner gate on red hex** — confirm `#F4501E` IS the chosen "rouge" or adjust. Affects every component.
- **Backend recompute audit out of K07 scope** — see K18 (FrontendOrderController) to close K07-P0-01.
- **`editingCartSnapshot` JSON-cloning may need to be reviewed when Menu Formule wizard introduces Map-based selections** (cf. K06 wizard helpers).
