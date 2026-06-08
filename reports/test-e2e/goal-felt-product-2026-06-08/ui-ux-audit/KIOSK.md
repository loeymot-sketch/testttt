# KIOSK (Borne) — UI/UX page-by-page + every-button audit

**Date:** 2026-06-08 · **Surface:** Kiosk / borne (PORTRAIT 1080×1920)
**Harness:** disposable clone `http://127.0.0.1:8766` (DB `foodking_e2e`) — operating box `:8765` untouched.
**Specs:** `tests/e2e/zz-uiux-kiosk-2026-06-08.spec.js`, `…-flow-…`, `…-probes-…`
**Screenshots:** `tests/e2e/__screenshots__/uiux-kiosk-2026-06-08/`
**Bundle freshness:** VERIFIED — served `kiosk-errors.js`/`kiosk-shell.js` (built 2026-06-08 07:06) contain FP-01 (`kiosk-error-network-staff-ack`/`staffCalled`) + FP-26 (`kiosk-cart-item-name` 2-line clamp) markers; source on disk is older → bundle is fresh, all line citations valid against this worktree.

## VERDICT
The borne is in **very good shape**. 12 distinct customer screens walked + every control exercised; category tab-switching verified across 5 families (all 11 tabs share layout); allergens confirmed shown **inline** (no modal — that's the V1 design). **Zero raw i18n labels** on any screen (every `_RAW` scan returned `[]`). FR copy correct everywhere, prices format FR (`€1,00`, `18,50 €`), layout intact at portrait. **No customer-reachable dead/stuck buttons in the V1 flow.** The 3 confirmed dead buttons all live on **deep-link/diagnostic-only error screens that have NO programmatic navigation path in V1** (proven below) → P3, not customer-facing. Prior heals FP-01 / FP-26 / FP-28 all VERIFIED still good. Two earlier suspects (dark-mode toggle "mandate violation"; `staff_ack` "raw key") were **investigated and cleared as non-issues** — see notes.

---

## TOP 10 MUST-FIX (prioritized)

| # | P | Item | File:line | Why |
|---|---|------|-----------|-----|
| 1 | P3 | **menu-unavailable "Réessayer" is DEAD** (emit-only, no nav/reload) | `KioskErrorMenuUnavailableComponent.vue:52` | Click → nothing. Screen is deep-link-only in V1 (no caller) → diagnostic P3. Mirror FP-01: `retry()` → `window.location.reload()`. |
| 2 | P3 | **payment-refused "Réessayer le paiement" is DEAD** (emit-only) | `KioskErrorPaymentRefusedComponent.vue:77` | Click → nothing. Reachable only when Plan B OFF (card path) — not V1. Fix: `$router.push({name:'kiosk.payment'})`. |
| 3 | P3 | **payment-refused "Payer en caisse" is DEAD** (emit-only) | `KioskErrorPaymentRefusedComponent.vue:83` | Click → nothing. Fix: `$router.push({name:'kiosk.cash-instruction'})` (matches the method's own doc-comment intent). |
| 4 | P3 | Cart: large empty whitespace gap with few items (item list top-anchored, summary bottom-pinned) | `KioskCartComponent.vue` items/summary layout (~`.kiosk-cart-items`) | 1-item cart shows ~1000px void mid-screen. Cosmetic; non-frozen. Center/min-height the items zone. |
| 5 | P3 | Loyalty: same large top whitespace above the card | `KioskLoyaltyComponent.vue` (card container) | Card bottom-anchored; top half empty. Cosmetic; vertically center the card. |
| 6 | P3 | Normal waiting deep-linked shows "NUMÉRO" label with an empty orange bar (no number) | `KioskWaitingComponent.vue` (number block) | Transient/edge: only on deep-link without a real queueNumber; real flow has the number. Guard the label so it hides until a number exists. |
| 7 | — | (owner_gated, FROZEN) Bowl/wizard steps have bottom whitespace gap above the action bar on short steps | `KioskWizardComponent.vue` (FROZEN §7) | Cosmetic only. Report-only; do NOT edit. |
| 8 | — | (owner_gated, FROZEN) dead-button parent does not bind error emits | `KioskAppComponent.vue:121-128` (FROZEN §7) | The router-view binds only `add-to-cart/go-to-cart/start-order/reset-kiosk`. Root cause of #1–3; fix belongs in the non-frozen children (above), not here. |
| 9 | INFO | EN/AR/DE/BN missing `kiosk.error.network.staff_ack` (FR present & resolving) | `resources/js/languages/{en,ar,bn,de}.json` | NOT a V1 issue — kiosk is FR-locked (ADR-007), customer never sees other langs. Add only when multi-lang ships. |
| 10 | INFO | Dormant dark-mode `<button>` in frozen shell, globally neutralized | `KioskAppComponent.vue:22-31` (FROZEN) + `resources/css/kiosk-fallback.css:17-20` | NON-issue: `display:none !important` hides it on every screen per owner 2026-05-21. Verified inert (click times out, theme stays light). No action. |

> **There are NO P1/P2 customer-facing defects.** All actionable items are P3 (cosmetic or diagnostic-only). The dead buttons matter only if a future change makes the error screens customer-reachable — fix is trivial and pre-staged above.

---

## PAGE-BY-PAGE BREAKDOWN

| Screen | Route | Controls audited | Working | DEAD / stuck | Screenshot | Notes |
|--------|-------|------------------|---------|--------------|------------|-------|
| **Idle** | `/kiosk/idle` | a11y gear, lang selector (hidden FR-lock), "À emporter" card, (theme toggle hidden) | ✅ all | none | `01-idle.png` | Logo, "Bienvenue !", single order-type (dine-in correctly hidden, V1 flag). RAW=[]. Lang selector hidden by design (FP-27 FR-lock). |
| **Categories** | `/kiosk/categories` | **11 sidebar tabs**, product "+" cards, inline allergen badges, breadcrumb, "Mon compte", abandon, bottom cart bar / pay | ✅ all | none | `02-categories.png`, `02-categories-tab-0..4.png` | **Tab-switching VERIFIED across 5 families** (Sandwich Cayenne 5p / Galette 2p / Sandwich Classique 2p / Burgers 2p / Tacos 2p) — all render product cards, FR titles/counts, RAW=[]. 11 tabs total (…Bols, Menu enfant, Frites, Suppléments, Desserts, Boissons — clicked-but-not-screenshotted tabs 6-11 share identical layout). "Mon compte"→loyalty, abandon→idle (source-verified). |
| **Wizard — Bowl (41)** | `/kiosk/wizard/41` | 4-step progress, choice cards, +, Précédent, Suivant (disabled until valid), Abandonner | ✅ all | none | `03-wizard-bowl-step1..3.png` | FROZEN. "Quelle sauce/supplément/menu/Récap", running "VOTRE COMPOSITION" chip, Total live. Suivant gated on menu step. Bottom whitespace on short steps (cosmetic, frozen). |
| **Wizard — Tacos/Burger** | `/kiosk/wizard/{26,38}` | entry step + cards | ✅ | none | `03b-wizard-{tacos,burger}.png` | FROZEN. Render OK, RAW=[] (captured in tacos-burger run). |
| **Wizard — Eau Plate (58, no-option)** | `/kiosk/wizard/58` | direct "Ajouter" (no steps) | ✅ | none | (cart proves add) | No-option drink adds directly to cart — verified: cart count "1 article". Graceful, no broken/empty wizard. |
| **Allergens** | (inline, no modal) | `KsAllergenBadge` on product cards / wizard header / cart lines | ✅ | none | `02-categories*.png`, `03b-wizard-*.png` | **No allergens MODAL exists in V1** — allergens are shown INLINE as badges: `KioskCategoriesComponent.vue:209` (`kiosk-product-allergens-{id}`), `KioskWizardComponent.vue:36` (`kiosk-wizard-header-allergens`), `KioskCartComponent.vue:170` (`kiosk-cart-item-allergens-{idx}`). `ALLERGEN_TRIGGER_FOUND=false` is correct (no trigger = no modal). Inline display is the design. The only allergen-mentioning modal is the loyalty **consent** modal (`ds/KsConsentModal.vue:180`, privacy text). |
| **Cart** | `/kiosk/cart` | back, Vider+confirm modal, edit ✏️, qty −/+/max-disable, 🗑️ trash, promo input+Appliquer, loyalty CTA, Valider, + Ajouter | ✅ all | none | `04-cart.png` | Seeded Eau Plate. FP-26 2-line clamp present (`KioskCartComponent.vue:780,959`). "Valider ma commande €1,00". RAW=[]. Whitespace gap with 1 item (P3 #4). |
| **Loyalty** | `/kiosk/loyalty` | back, numpad 1-9/⌫/0, Vérifier (gated), Continuer sans fidélité, S'inscrire (register flow), redeem yes/no | ✅ all | none | `05-loyalty-seeded.png` | "Programme fidélité". `proceedToPayment/goBack` → router (verified). RAW=[]. Top whitespace (P3 #5). |
| **Upsell** | `/kiosk/upsell` | 3 add cards (+), "Non merci, continuer sans (29s)" | ✅ all | none | `06-upsell-seeded.png` | FROZEN. "ET POUR TERMINER ?" Frites/Boisson/Menu, real images, skip countdown. RAW=[]. |
| **Payment (Plan B)** | `/kiosk/payment` | "Confirmer ma commande" (counter route) | ✅ | none | `07-payment-seeded.png` | "PAIEMENT À LA CAISSE / Veuillez payer à la caisse / TOTAL À RÉGLER €1,00". Plan B active → method selection hidden (correct V1). `confirmCounterRoute`→cash. RAW=[]. |
| **Waiting (normal)** | `/kiosk/waiting/1` | "Nouvelle commande", timeout overlay→home, cancel modal | ✅ | none (empty number = P3 edge) | `08-waiting-normal.png` | "Votre commande est en préparation", chef pulse, auto-redirect 9s. Deep-link shows empty "NUMÉRO" bar (P3 #6). |
| **Waiting (offline)** | `/kiosk/waiting/offline_1` | "Nouvelle commande" | ✅ | none | `08b-waiting-offline*.png` | FP-28 VERIFIED: "Commande enregistrée…", auto-return hint visible (`kiosk-offline-auto-return`), `startOfflineAutoRedirect`→`newOrder()`→idle @20s (code-verified; 7s probe still on screen, as expected). |
| **Confirmation** | `/kiosk/confirmation` | 🖨️ Imprimer le ticket (status states), Nouvelle commande → home | ✅ all | none | `09-confirmation-seeded.png` | Needs `orderRef` (guard); seeded → "Commande confirmée ! #A-042 / TOTAL PAYÉ €18,50", print button, auto-return 29s. `goHome`→reset+idle. RAW=[]. |
| **Cash-instruction** | `/kiosk/cash-instruction` | "J'ai compris" (ack→idle), countdown | ✅ | none | `10-cash-instruction.png` | "Rendez-vous en caisse / #A-042 / 18,50 € / Retour à l'accueil dans 44 s". `acknowledge` logs+emits+`$router.push` fallback. RAW=[]. |
| **Error — Network** | `/kiosk/error/network` | Réessayer (reload), Prévenir l'équipe (ack) | ✅ both | none | `11-err-network.png`, `probe-network-staff-ack.png` | FP-01 VERIFIED: retry→`location.reload()`; callStaff shows ack "Merci, veuillez patienter — un membre de l'équipe va vous assister." (real FR, NOT raw key). Deep-link-only in V1. |
| **Error — Menu unavailable** | `/kiosk/error/menu-unavailable` | **Réessayer (DEAD)**, Retour accueil (→idle) | retry ❌ / home ✅ | **retry DEAD** (P3) | `11-err-menu.png` | Deep-link-only (no caller — `goToKioskError` never called). retry emit-only. |
| **Error — Product removed** | `/kiosk/error/product-removed` | Retour au menu (→categories), Retour accueil (→idle) | ✅ both | none | `11-err-product.png` | Both have `$router` fallback. Deep-link-only. |
| **Error — Payment refused** | `/kiosk/error/payment-refused` | **Réessayer (DEAD)**, **Payer en caisse (DEAD)**, Annuler (→idle) | cancel ✅ / 2 ❌ | **retry+counter DEAD** (P3) | `11-err-payment.png` | Reachable only when Plan B OFF (card path, `KioskPaymentComponent.vue:596`) — not V1. |

---

## EMPIRICAL DEAD-BUTTON PROBE (`11b-error-button-wiring`, 1 passed)

Each error CTA clicked; recorded whether URL changed (`navigated`) or an ack appeared (`ackVisible`):

| Button | navigated | ack | **DEAD** |
|--------|-----------|-----|----------|
| network · Prévenir l'équipe | no | **yes** | NO (FP-01 ack OK) |
| **menu · Réessayer** | no | no | **YES** |
| menu · Retour accueil | yes→idle | — | NO |
| **payment · Réessayer le paiement** | no | no | **YES** |
| **payment · Payer en caisse** | no | no | **YES** |
| payment · Annuler | yes→idle | — | NO |
| product · Retour au menu | yes→categories | — | NO |

Root cause (source-anchored): these methods only `$emit`; the frozen parent `KioskAppComponent.vue:121-128` binds only 4 unrelated events, so the emits are inert. Sibling screens (network/product/cash/menu-home) self-navigate via `$router.push`/`reload` — these three were missed.

---

## REACHABILITY EVIDENCE (why the dead buttons are P3, not P1)

- **No code path navigates to the network / menu-unavailable / product-removed error routes.** The store helper `goToKioskError()` (`store/modules/kioskCart.js:87`) is **defined but never called** anywhere (`grep` of all `*.vue/*.js` outside the route def = 0 callers). The `KIOSK_ERROR_ROUTES` map is dormant.
- **payment-refused** has exactly ONE caller: `KioskPaymentComponent.vue:596`, inside `processCardPayment` — the card path, which is entirely hidden under **Plan B** (`paymentRouteAllToCounter=true`, `config/kiosk.php:54` default true; all `v-if="!paymentRouteAllToCounter"`). V1 routes payment to counter → this screen is unreachable organically.
- Therefore all four error screens are **deep-link / staff-diagnostic only in V1**; a customer cannot land on them. Dead buttons = P3 diagnostic.

---

## PRIORITIZED NON-FROZEN FIX LIST

- **[P3] `KioskErrorMenuUnavailableComponent.vue:52-57`** — `retry()` is emit-only (DEAD). Repro: `/kiosk/error/menu-unavailable` → click "Réessayer" → no nav (probe `navigated:false`). Screenshot `11-err-menu.png`. **Fix (scope-minimal):** mirror FP-01 — after emit, `setTimeout(()=>window.location.reload(),600)`. **Why reload, not a route push:** menu-503 means the SPA failed to *fetch the catalogue*; a full reload re-bootstraps the kiosk and re-attempts the menu fetch (a real retry). The two payment fixes below use `$router.push` instead because there the data is fine and the user just needs to be *routed forward* to a working screen — do NOT blindly copy one pattern across all three.
- **[P3] `KioskErrorPaymentRefusedComponent.vue:77-82`** — `retryPayment()` emit-only (DEAD). Repro: `/kiosk/error/payment-refused` → "Réessayer le paiement" → no nav. Screenshot `11-err-payment.png`. **Fix:** after emit, `this.$router?.push({name:'kiosk.payment'}).catch(()=>{})`.
- **[P3] `KioskErrorPaymentRefusedComponent.vue:83-86`** — `payCounter()` emit-only (DEAD). Repro: "Payer en caisse" → no nav. **Fix:** after emit, `this.$router?.push({name:'kiosk.cash-instruction'}).catch(()=>{})` (matches doc-comment intent "basculer vers CashInstruction").
- **[P3] `KioskCartComponent.vue` (`.kiosk-cart-items` / summary layout)** — large mid-screen void with few items. Screenshot `04-cart.png`. **Fix:** give items zone `min-height`/`flex:1` so the summary doesn't pin to the bottom on a 1-item cart.
- **[P3] `KioskLoyaltyComponent.vue` (card container)** — top whitespace above the numpad card. Screenshot `05-loyalty-seeded.png`. **Fix:** vertically center the card (`justify-content:center`).
- **[P3] `KioskWaitingComponent.vue` (number block)** — empty "NUMÉRO" bar on deep-link without a queue number. Screenshot `08-waiting-normal.png`. **Fix:** `v-if` the NUMÉRO label/bar on a present number.
- **owner_gated [FROZEN, report-only]** `KioskWizardComponent.vue` short-step bottom whitespace (cosmetic); `KioskAppComponent.vue:22-31` dormant theme button (already neutralized by `kiosk-fallback.css`); `KioskAppComponent.vue:121-128` non-binding of error emits (fix lives in the children above).

---

## CLEARED SUSPECTS (verified non-issues — documented to prevent re-flagging)

1. **Dark-mode toggle "V1 mandate violation"** → CLEARED. The `<button data-testid="kiosk-theme-toggle">` exists in the frozen shell and appears in DOM probes on every screen, BUT `resources/css/kiosk-fallback.css:17-20` applies `display:none !important` globally (owner request 2026-05-21: "je veux plus l'icône… laisse en mode Light"). Probe confirmed `display:none`, `rect 0×0`, click times out, theme stays `kiosk-theme--light`. Inert. No action.
2. **`kiosk.error.network.staff_ack` "raw label"** → CLEARED. An offline `require()`-based check mis-traversed a duplicate `"kiosk"` string key and reported MISSING. **Live render is ground truth**: the ack shows proper FR ("Merci, veuillez patienter…"); key present at `fr.json:1638`. FR resolves correctly. (Other langs lack it but kiosk is FR-locked.)
3. **Confirmation "shows idle"** → CLEARED. Deep-link bounces to idle via `requireConfirmationContext` (needs `orderRef`). Seeding `SET_ORDER_REF` then navigating renders the real confirmation (`09-confirmation-seeded.png`). Working as designed.

---

## EVIDENCE INDEX
- Specs: `tests/e2e/zz-uiux-kiosk-2026-06-08.spec.js` (screens + wiring probe), `…-flow-…` (cart-seeded gated screens), `…-probes-…` (theme/ack/confirmation).
- Runs: core+errors **12 passed**, wiring **1 passed**, flow **3 passed**, probes **3 passed**.
- Screenshots dir: `tests/e2e/__screenshots__/uiux-kiosk-2026-06-08/` (idle, categories, wizard-bowl×3, wizard tacos/burger, cart, loyalty, upsell, payment, waiting normal+offline, confirmation, cash, 4 error screens, theme/ack/confirmation probes).
