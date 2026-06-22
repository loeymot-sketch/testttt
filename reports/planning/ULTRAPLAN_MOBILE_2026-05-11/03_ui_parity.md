# 03 — UI Parity Audit (mobile vs kiosk + native mobile)

**Agent**: AGENT-3 UI-PARITY-AUDITOR
**Date**: 2026-05-11
**Branch**: feature/mobile-app-le-cayenne-2026-05-10 @ ebb712dd8
**Scope**: customer-facing UX surface parity between `mobile/` (React standalone) and `resources/js/components/frontend/kiosk/` (Vue + Vuex)

---

## Executive summary

The mobile prototype matches the kiosk on **structure** (wizard state-machine, cart, loyalty, history) but is **materially incomplete** on three legally/operationally important surfaces:

1. **Allergens display** — completely absent across product card, list, item detail and wizard. Kiosk uses `KsAllergenBadge` everywhere it surfaces a product (`KioskCategoriesComponent.vue:204-210`, `KioskWizardComponent.vue:29-36`). EU food info regulation (FIC 1169/2011 art. 9) requires disclosure of the 14 allergens before purchase. **This is the biggest single gap.**
2. **Special instructions per line** — kiosk has a per-recap `instruction` field (`KioskWizardComponent.vue:151-161`, bound to `selections.instruction`, 190-char cap) that flows to the kitchen ticket. Mobile wizard has no equivalent — customer cannot communicate "sans oignon ce soir SVP", lactose substitution, etc.
3. **Promo code entry** — kiosk cart exposes a `kiosk-cart-promo` input with server validation (`KioskCartComponent.vue:265-284`). Mobile cart has only a passive loyalty banner — no field to enter a promo code, blocking marketing campaigns.

Several mobile-only stubs ("Recherche", "Favoris") are still toast placeholders, and discovery affordances (sort, recently-viewed, in-list direct-add for non-wizard items) are missing entirely. The wizard lacks a **live composition preview** (kiosk shows running chips every step at `KioskWizardComponent.vue:98-106`); cart lacks **subtotal / discount / tax breakdown** (kiosk: `KioskCartComponent.vue:239-254`); product list lacks **availability greyout** for 86'd items (kiosk: `kiosk-product-card--filtered-out` at `KioskCategoriesComponent.vue:152`).

Total: **27 missing or weakly-implemented features**, ranked below. Mobile-native expected features (push, geoloc, haptic, offline cache, pull-to-refresh) are accounted for separately — they are not parity gaps but native-platform expectations.

---

## Methodology

Read-only static comparison:

- Mobile source: `mobile/screens-main.jsx` (1,262 L), `mobile/screens-item-steps.jsx` (943 L), `mobile/screens-onboarding.jsx` (304 L), `mobile/screens-modals.jsx` (306 L)
- Kiosk reference: `resources/js/components/frontend/kiosk/KioskAppComponent.vue` (1,576 L), `KioskCategoriesComponent.vue` (1,588 L), `KioskWizardComponent.vue` (3,091 L), `KioskCartComponent.vue` (1,235 L), `KioskUpsellComponent.vue` (543 L), and design-system folder `kiosk/ds/` (KsAllergenBadge, KsBadge, KsCartBottomSheet, KsFilterChip, KsPriceLine, KsStepper)
- Cross-checked against Phase-6 mobile screenshots in `tests/e2e/__screenshots__/test-e2e-mobile-wave-{A,B,C,D}/`

Status codes used in tables below:
- **PRESENT-PARITY** — mobile has feature roughly matching kiosk behaviour
- **PRESENT-WEAK** — mobile has shell/stub but no functional implementation, or weaker version
- **MISSING** — no equivalent in mobile
- **N/A** — feature is kiosk-specific (touch hardware, fiscal-printer) and not expected in mobile

---

## Dimension by dimension findings

### Discovery & navigation

| Feature | Status | Evidence |
|---|---|---|
| Search bar on menu | PRESENT-WEAK | Icon present but binds to a "bientôt disponible" toast at `mobile/screens-main.jsx:176`. Kiosk also lacks a search (touchscreen UX), so this is a mobile-native gap, not a parity gap. |
| Category filter chips | PRESENT-PARITY | `mobile/screens-main.jsx:179-188`. Kiosk uses a sidebar (`KioskCategoriesComponent.vue:94-124`); mobile uses horizontal chips — equivalent affordance. |
| Sticky / autoscroll-active chip | MISSING | When scrolling through categories, active chip is not auto-highlighted nor scrolled into view. Kiosk's sidebar has `kiosk-sidebar-item.active` (`KioskCategoriesComponent.vue:104`). |
| Sort options (price/popularity) | MISSING | No sort control anywhere in mobile menu. Kiosk also lacks runtime sort; SSOT is `display_order` from backend. Not a parity gap. |
| Featured / TOP / NOUVEAU / SIGNATURE badges | PRESENT-PARITY | `mobile/screens-main.jsx:25-34` (`<Tag/>` component). Kiosk uses `KsBadge` in `kiosk-product-flag-row` (`KioskCategoriesComponent.vue:195-203`). |
| Recently viewed | MISSING | No "Vu récemment" carousel. Mobile-native expectation, not in kiosk. |
| Favorites (functional) | PRESENT-WEAK | Heart icon on home cards (`screens-main.jsx:114-116`) and item detail (`screens-main.jsx:314`) but clicks emit a "bientôt disponible" toast. No persistence layer. |
| Upsell "Pour accompagner ?" | PRESENT-WEAK | Static carousel in cart (`screens-main.jsx:609-628`). Kiosk has a dedicated full-screen upsell step with auto-skip 30s timer, multi-select, analytics events (`KioskUpsellComponent.vue:1-285`). |
| Auto-skip timer on upsell | MISSING | Kiosk `KioskUpsellComponent.vue:92-109` shows a 30s countdown progress bar. Mobile carousel has no timer. |
| Per-category banner / hero | MISSING | Mobile menu shows category title + count only (`screens-main.jsx:195-198`). Kiosk shows category image/emoji in the breadcrumb (`KioskCategoriesComponent.vue:4-21`). |
| Promo carousel banner | MISSING | Kiosk mounts `<KioskPromoCarouselComponent/>` on the catalog (`KioskCategoriesComponent.vue:48`). Mobile has nothing equivalent — no merchandising surface for promos. |

### Product card / list item

| Feature | Status | Evidence |
|---|---|---|
| Image | PRESENT-PARITY | Phase 6.A done, real-asset thumbs via `<Slot/>` and `it.image` (`screens-main.jsx:203, 113`). |
| Name + price | PRESENT-PARITY | `screens-main.jsx:207, 211`. |
| Description (line-clamp 2) | PRESENT-PARITY | Fixed in cluster B-004, `screens-main.jsx:209`. |
| Tags (SIGNATURE / TOP / SPICY) | PRESENT-PARITY | `screens-main.jsx:206`. |
| Dietary badges (HALAL / VEGGIE) | PRESENT-WEAK | Present only on item detail (`screens-main.jsx:334-335`), not on list cards. Kiosk renders dietary badges on every product card via `productBadges()` + `KsBadge` (`KioskCategoriesComponent.vue:195-203`). |
| Allergens display | **MISSING** | Mobile has **zero** allergen disclosure across home, menu list, item detail, and wizard. Kiosk has `KsAllergenBadge` rendered on every card (`KioskCategoriesComponent.vue:204-210`, `customerAllergenCodes` for personalized highlighting at `KioskCategoriesComponent.vue:391-395`) and in the wizard header (`KioskWizardComponent.vue:29-36`). **P0 — EU FIC 1169/2011 disclosure obligation.** |
| Prep time | PRESENT-WEAK | Shown on item detail hero badge (`screens-main.jsx:321-327`) and pill row (`:345`). Not surfaced on list/grid cards. |
| Calories / nutritional info | MISSING | Neither mobile nor kiosk surface calories. Not a parity gap but a discovery dimension to consider. |
| Add-to-cart from list (direct items) | PRESENT-WEAK | List "+" button at `screens-main.jsx:212` calls `addToCart(it)` directly — bypasses the wizard for **all** items including those that require viandes/sauces. **This is a logic bug**: a tacos with `item.viandes=2` will land in cart with empty composition. Kiosk uses `onProductCardActivate` which always opens the wizard (`KioskCategoriesComponent.vue:159-160, 465-471`). Flagged to AGENT-1 cluster as well — separate bug, but shows up as parity weakness here. |
| Greyout for unavailable / 86'd items | MISSING | Kiosk renders `kiosk-product-card--filtered-out` + `aria-disabled="true"` + tooltip `productFilteredOutTooltip` (`KioskCategoriesComponent.vue:151-160`). Mobile menu has no out-of-stock state — `is_available=false` items would render normally. |

### Wizard

| Feature | Status | Evidence |
|---|---|---|
| Step indicator dots | PRESENT-PARITY | `Dots` component at `screens-item-steps.jsx:204-206`. |
| Per-step heading + description | PRESENT-PARITY | `WizardHeader` (`screens-item-steps.jsx:177-209`) + per-step intro `<p>` (`:301-304, 350-352, 392-394`). |
| "Suivant" disabled with contextual hint | PRESENT-PARITY | `WizardCTA hint` rendered in aria-live region (`screens-item-steps.jsx:211-241, 829-841`). |
| "Précédent" / back | PRESENT-PARITY | `onPrev` callback navigates back through `activeSteps` (`screens-item-steps.jsx:811-817`). |
| Skip / jump to recap | MISSING | No "Voir le récap" shortcut. Kiosk lets the user jump through tab/step keys via the live-composition tap (`kiosk-live-composition` is clickable on some flows). |
| **Live composition preview during wizard** | MISSING | Kiosk renders `kiosk-live-composition` chip list on every step (`KioskWizardComponent.vue:98-106`). Mobile shows composition only at the recap step (`screens-item-steps.jsx:665-676`). User cannot verify selections without backing out. P1. |
| Per-step real images | PRESENT-PARITY | Phase 6.A done — viandes, sauces, crudités, suppléments, drinks, frites all have `m.image`/`s.image`/`d.image`/`fs.image` real assets. |
| Per-step description / explanation | PRESENT-PARITY | One-line intro per step (`screens-item-steps.jsx:301-304, 350-352, 392-394, 432, 499-501, 539-541, 571-573`). |
| Aria-live counter | PRESENT-PARITY | `aria-live="polite"` on step status (`screens-item-steps.jsx:189-191`) and counter (`screens-item-steps.jsx:301`). |
| Allergens visible in wizard | MISSING | Kiosk header surfaces `KsAllergenBadge` with `selections`/`itemAllergenCodes`/`customerAllergenCodes` props (`KioskWizardComponent.vue:29-36`) — dynamic, updates as user picks meats/sauces. Mobile wizard has none. P0. |
| Special instructions (note) | **MISSING** | Kiosk recap step renders `<textarea v-model.trim="selections.instruction" placeholder="..." />` with 190-char cap and label "Instructions spéciales" (`KioskWizardComponent.vue:151-161`). Mobile recap has no text input — customer cannot add "sans oignon", "lactose intolerant", "extra crispy", etc. P0. |
| Quantity stepper | PRESENT-PARITY | `screens-item-steps.jsx:679-690`. |

### Cart

| Feature | Status | Evidence |
|---|---|---|
| Line composition summary | PRESENT-PARITY | Fixed cluster B-001/C-004, `screens-main.jsx:577-586`. Composition built by `buildLineItem` at `screens-item-steps.jsx:698-751`. |
| Quantity controls | PRESENT-PARITY | `screens-main.jsx:588-592`. |
| Remove item | PRESENT-PARITY | `screens-main.jsx:596`. |
| Empty state | PRESENT-PARITY | `screens-main.jsx:557-562`. |
| Modify item from cart | MISSING | Tapping a cart line does nothing. Kiosk supports `editCartItem` which reopens the wizard with persisted selections (`KioskCartComponent.vue` editCartItem flow at line 716+). Mobile has no edit path — customer must remove + restart. P2. |
| Apply loyalty discount | PRESENT-WEAK | Cart shows a "you'll earn +N pts" banner (`screens-main.jsx:600-607`) but **no redemption surface** on the cart — points can only be redeemed from Loyalty screen modal. Kiosk has both balance display and redeem CTA in the cart summary. |
| **Promo code field** | **MISSING** | Kiosk cart has `kiosk-cart-promo` input + server validation + applied-promo chip (`KioskCartComponent.vue:265-284`, error handling at `:281-282`). Mobile cart has nothing. P0 — blocks marketing campaigns. |
| Notes / instructions per line | MISSING | Mirror of wizard `instruction` field missing. Even if added to the wizard, the cart should expose an edit-note affordance per line. |
| Estimated prep time | PRESENT-WEAK | Cart hardcodes "prêt dans ~12 min" (`screens-main.jsx:553`) — not derived from items. Item detail at `:345` shows per-item time but it doesn't aggregate. |
| Estimated ready-at clock time | MISSING | Confirmation screen shows `orderEta` (`screens-main.jsx:655, 682`) but cart does not. |
| Subtotal / discount / tax breakdown | MISSING | Mobile cart shows only one `Total` line with "TVA incluse" label (`screens-main.jsx:633-639`). Kiosk renders separate rows: subtotal, loyalty discount, promo discount, total (`KioskCartComponent.vue:239-263`). P1. |
| Sticky checkout bar | PRESENT-PARITY | `screens-main.jsx:631-644`. |
| Order-type selector (eat-in vs takeaway) | MISSING | Kiosk has `kiosk-cart-order-type-dinein` toggle (`KioskCartComponent.vue:87-95`). Mobile assumes takeaway. V1 feature flag `pos.dine_in_enabled=false` makes this optional but worth noting. |

### Checkout

| Feature | Status | Evidence |
|---|---|---|
| Payment method modal | PRESENT-PARITY | `ModalPayChoice` (`screens-modals.jsx:40-65`). Counter + Stripe options. |
| Loyalty points display on checkout | PRESENT-WEAK | Confirmation screen shows total but no "+N pts crédités" until OrderDetail. Kiosk shows discount applied inline in cart. |
| Order receipt / NF525 fiscal info | PRESENT-WEAK | OrderDetail mentions `Reçu fiscal NF525 #{orderId}-R` (`screens-modals.jsx:252`) but no surface for `fiscal_sequence_no` or chain hash. Probably acceptable for mobile (vs kiosk receipt). |
| Tax breakdown (HT / TVA / TTC) | MISSING | Only "TVA incluse" label. Should at least show TVA amount on receipt. |
| Promo / loyalty redemption flow | PRESENT-WEAK | Redemption only from Loyalty screen modal (`ModalRedeem` at `screens-modals.jsx:145-175`), not in checkout flow. |
| Stripe / online payment confirmation | PRESENT-WEAK | `ScreenStripe` (`screens-modals.jsx:67-109`) is a fake placeholder — hardcoded `4242 4242 4242 4242`, no real Stripe Elements. V0 stub. |

### Account / Profile

| Feature | Status | Evidence |
|---|---|---|
| Profile fields display | PRESENT-PARITY | `screens-main.jsx:856-865`. |
| Loyalty QR code | PRESENT-PARITY | `LoyaltyQR` widget at `screens-main.jsx:1010`. |
| Wallet Apple / Google (V0 stub) | PRESENT-PARITY | Buttons at `screens-main.jsx:1057-1081`, modals at `screens-modals.jsx:280-306`. V0 placeholder modals; production work tracked in `mobile/WALLET_PLAN.md`. |
| Order history | PRESENT-PARITY | `ScreenOrders` (`screens-main.jsx:716-845`), groups by date, derived from `LC.orders` data layer. |
| Reorder | PRESENT-PARITY | "↻ Refaire" button at `screens-main.jsx:832`, routes to menu. (Doesn't preload the cart with same items — could be enhanced.) |
| Edit profile | PRESENT-WEAK | Button at `screens-main.jsx:863` emits "bientôt disponible" toast. |
| Notification preferences | PRESENT-WEAK | Row shown ("Activées") but click toasts unavailable (`screens-main.jsx:905`). |
| Address book (multi-address) | MISSING | No saved addresses for delivery. (V1 is pickup-only — likely deferred to V1.x delivery feature.) |
| Payment methods saved | PRESENT-WEAK | Row shown ("Visa ····4242") but no management UI (`screens-main.jsx:904`). |
| Loyalty tier / rewards tab | PRESENT-PARITY | Three tabs (Mes points / Récompenses / Historique) at `screens-main.jsx:1086-1238`. |
| RGPD opt-out | PRESENT-PARITY | Button + confirm modal (`screens-main.jsx:1248-1254`, `screens-modals.jsx:311-334`). Cluster-3 round-2 fix verified. |
| Help / Support / Contact | PRESENT-WEAK | Phone row at `screens-main.jsx:908` but no in-app contact form. |
| Logout | PRESENT-PARITY | Button at `screens-main.jsx:921`. |
| Allergen / dietary preferences declaration | MISSING | Kiosk loyalty profile holds `declared_allergens` (`KioskCategoriesComponent.vue:391-395`) used to highlight matching allergens on products. Mobile has a "Allergènes & préférences" row that toasts unavailable (`screens-main.jsx:906`). Critical for the allergen P0 above. |
| Language selector | PRESENT-WEAK | Row shows "Français" — no real switcher (kiosk has runtime FR/EN/AR). |

### Error & loading states

| Feature | Status | Evidence |
|---|---|---|
| Network error states | MISSING | Mobile assumes data layer always succeeds. Kiosk has dedicated `KioskErrorNetworkComponent.vue`, `KioskErrorMenuUnavailableComponent.vue`, retry buttons. |
| Loading skeletons | MISSING | No skeleton/spinner placeholders. Kiosk has `kiosk-catalogue-loading` (`KioskCategoriesComponent.vue:64-73`) and per-card `is-loading` state. |
| Empty cat (no items) | PRESENT-WEAK | Mobile filters by category and shows nothing when empty — no empty-state copy. Kiosk shows `kiosk-catalogue-empty` with a CTA. |
| Catalog cache / offline banner | MISSING | Kiosk shows `kiosk-cache-banner` when menu comes from IndexedDB (`KioskCategoriesComponent.vue:51-62`). Mobile has no offline indicator. |
| Catalog-change toast (item removed) | MISSING | Kiosk has `CatalogChangeToastComponent` for when items disappear mid-session (`KioskAppComponent.vue:13-20`). Mobile has no equivalent. |
| Validation errors | PRESENT-PARITY | Wizard hint at `screens-item-steps.jsx:215-217`. |
| Toast notifications system | PRESENT-PARITY | `Toast` component (`screens-modals.jsx:263-273`). |

### Accessibility

| Feature | Status | Evidence |
|---|---|---|
| Focus management | PRESENT-PARITY | Heading auto-focus on step transitions (`screens-item-steps.jsx:802-806`), modal focus trap on ESC + first-focusable (`screens-modals.jsx:11-21`). |
| Keyboard navigation | PRESENT-PARITY | `tabIndex={0}` on `ChoiceCard` + `onKeyDown` (Enter/Space) at `screens-item-steps.jsx:247-263`. |
| Screen reader semantic | PRESENT-PARITY | `role="radio/checkbox/group/radiogroup/tablist/tab/tabpanel"` consistently across wizard + loyalty (`screens-item-steps.jsx:309, 353, 395, 433, 502; screens-main.jsx:1086, 1093-1097`). |
| Color contrast WCAG AA | PRESENT-PARITY (assumed) | Round-2 design audit cluster-4 closed contrast issues. Not re-verified here. |
| Touch target 44px minimum | PRESENT-PARITY | Recap CTA height 60 (`screens-item-steps.jsx:227`), wizard back/close 40×40 (`screens-item-steps.jsx:184, 199`). Below 44px in a few spots (32px close on home `screens-main.jsx:43`) — minor. |
| Reduced motion | PRESENT-PARITY | Confetti modal honours `prefers-reduced-motion` (`screens-modals.jsx:116-117`). |

### Mobile-native features (not kiosk parity but mobile expectations)

| Feature | Status | Evidence |
|---|---|---|
| Camera scan for loyalty card link | PRESENT-WEAK | `ModalCardLink` (`screens-modals.jsx:178-195`) is visual-only — no `getUserMedia` integration. |
| Push notification subscribe | MISSING | No `Notification.requestPermission()` flow, no service worker for push. |
| Geolocation (closest branch) | MISSING | Hardcoded `Hénin-Beaumont` — no geolocation API use. V1 single-branch makes this lower priority. |
| Offline mode / cached menu | MISSING | No `localStorage`/IndexedDB cache for menu. Kiosk has `fromCache` + offline banner. |
| App icon / splash configured | PRESENT-PARITY | `ScreenSplash` (`screens-onboarding.jsx:6-25`). PWA manifest not verified. |
| iOS safe-area handled | PRESENT-PARITY | `--ios-safe-top` CSS var used throughout (`screens-main.jsx:40, 311, 657, 996`). |
| Pull-to-refresh on menu / orders | MISSING | No native scroll-overflow refresh handler. |
| Haptic feedback on button taps | MISSING | No `navigator.vibrate()` or iOS haptic API calls. |
| Web Share API | MISSING | No share-order or share-receipt affordance. |

---

## Missing features list — Priority ranked

| Feature | Severity | Effort | Why it matters | Recommended phase |
|---|---|---|---|---|
| **Allergens display on product card / list / wizard** | **P0** | M | EU FIC 1169/2011 legal disclosure obligation; matches kiosk `KsAllergenBadge` everywhere; allergic customers cannot safely order without this | 6.B |
| **Special instructions textarea on wizard recap** | **P0** | S | Kitchen errors / dietary accommodations; kiosk has `selections.instruction` (190-char cap) flowing into ticket | 6.B |
| **Promo code field on cart** | **P0** | M | Lost revenue + marketing parity (Le Cayenne already issues codes via SMS); kiosk has full promo/validation flow | 6.B |
| **Direct add-to-cart bypass bug from list "+" button** | **P0** | S | Adds composition-required items (tacos with viandes=2) with empty selections → broken kitchen ticket. Replace `addToCart(it)` with `go('item', it.id)` or detect direct-add eligibility | 6.B (bug fix) |
| **Catalog availability greyout (86'd items)** | P1 | S | Customer orders out-of-stock item, kitchen rejects, refund needed; kiosk handles via `is_available=false` + tooltip | 6.B |
| **Live composition preview during wizard (not only recap)** | P1 | M | UX clarity — customer must back out to verify selections; kiosk has running chip list | 6.B |
| **Subtotal / loyalty discount / promo discount / total breakdown** | P1 | S | Required once promo + loyalty redemption land; kiosk has 4-line summary | 6.B (pairs with promo) |
| **Search bar on menu (real implementation)** | P1 | M | Currently toast stub; menu has 60 items + 13 cats so users will scroll a lot | 6.C |
| **Order-type toggle (dine-in / takeaway)** | P1 | S | V1 feature flag exists but mobile doesn't expose it; needed before V1.x dine-in | 6.C |
| **Dietary badges on list cards (HALAL, VEGGIE, VEGAN, GLUTEN-FREE)** | P1 | S | Currently only on item detail; kiosk shows on every card | 6.B |
| **Allergen preferences in profile (declared_allergens)** | P1 | M | Powers personalized allergen highlighting; pairs with #1 | 6.C |
| **Catalog-change / out-of-stock toast** | P1 | S | "Item supprimé du panier — Coca rupture" style notification | 6.C |
| **Loading skeletons + network error states** | P1 | M | Currently zero error handling on data layer fetch failures | 6.C |
| **Estimated prep time aggregated in cart** | P2 | S | Currently hardcoded "~12 min"; derive from `Math.max(items.time)` | 6.C |
| **Favorites (heart icon functional + persisted)** | P2 | M | Currently toast stub; needs `LC.storage` favorites set + UI in profile | 6.C |
| **Modify item from cart (reopen wizard with preserved selections)** | P2 | M | Kiosk has `editCartItem`; mobile customer must delete + restart wizard | 6.C |
| **Per-category banner / hero image** | P2 | S | Visual structure parity with kiosk breadcrumb | 6.D |
| **Promo carousel banner on home / menu** | P2 | M | Merchandising surface (`KioskPromoCarouselComponent` equivalent) | 6.D |
| **Sticky / auto-scroll active category chip** | P2 | S | UX polish on long menu scroll | 6.D |
| **Loyalty redemption from cart (not only loyalty screen)** | P2 | M | Pairs with promo/discount lines | 6.C |
| **Camera scan for loyalty card link (real getUserMedia)** | P2 | M | Currently visual-only modal; needed before plastic-card link is live | 6.D |
| **Push notification subscribe flow** | P2 | L | Mobile-native expectation; tied to backend topic infrastructure | 6.D / V1.x |
| **Offline mode / cached menu (IndexedDB)** | P2 | L | Kiosk has it; mobile flows break entirely on flaky 4G | 6.D / V1.x |
| **Pull-to-refresh on menu / orders** | P3 | S | Native mobile expectation | 6.D |
| **Haptic feedback on key actions** | P3 | S | Native mobile polish (`navigator.vibrate(15)` on add-to-cart) | 6.D |
| **Recently viewed carousel** | P3 | M | Discovery surface for repeat visitors | 6.D |
| **Sort options (price asc / desc / popularity)** | P3 | S | Useful with 60 items; not in kiosk so optional | 6.D |

---

## Recommendation: what to build next

Top 10 features for **Phase 6.B** (must-fix before V1 mobile prod release):

1. **Add allergens display** — `KsAllergenBadge`-equivalent React component, render on product list card (`screens-main.jsx:200`), item detail (`:332`), and wizard header (`screens-item-steps.jsx:177`). Data already in backend payload — needs UI surface only. **Critical legal.**
2. **Add special-instructions textarea to wizard recap** — mirror kiosk's `instruction` field at the recap step (`screens-item-steps.jsx:613`); persist in `buildLineItem` (`:698`); render on cart line + order detail.
3. **Fix direct-add bypass bug** — change `screens-main.jsx:212` `onClick={e => { e.stopPropagation(); addToCart(it); }}` to `onClick={e => { e.stopPropagation(); go('item', it.id); }}`. Direct-add only OK if `computeActiveSteps(item)` returns just `[RECAP]`.
4. **Add promo code input on cart** — text input + apply button + applied chip + error state; needs backend endpoint (already exists for kiosk).
5. **Add greyout / unavailable state on product cards** — read `it.is_available`, apply opacity + disabled + tooltip "Épuisé".
6. **Render live composition preview during wizard** — sticky bar above the CTA, regenerated from `buildLineItem(item, selections)` on every change.
7. **Render subtotal / discount / total breakdown on cart** — 4 lines: Sous-total / Réduction fidélité / Réduction promo / Total.
8. **Dietary badges on list cards** — extract the inline badges from `screens-main.jsx:334-335` into the list-card grid.
9. **Add allergen preferences in profile** — declared_allergens checkbox grid; persist in `LC.storage`; feed into the new allergen badges to highlight matches.
10. **Implement real menu search** — replace toast stub at `screens-main.jsx:176` with an actual filter overlay (debounced text input + matches).

Phase 6.C should target: order-type toggle, loading/error states, catalog-change toast, modify-from-cart, real Stripe Elements, loyalty redemption from cart.

Phase 6.D / V1.x should target: native-mobile gaps (push, offline, geolocation, haptic, PWA install), favorites persistence, promo carousel banner, recently-viewed.

---

## File references summary

Mobile sources audited:
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/screens-main.jsx`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/screens-item-steps.jsx`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/screens-onboarding.jsx`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/mobile/screens-modals.jsx`

Kiosk references:
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskAppComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskCategoriesComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskWizardComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskCartComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/KioskUpsellComponent.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/ds/KsAllergenBadge.vue`
- `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/components/frontend/kiosk/ds/KsBadge.vue`
