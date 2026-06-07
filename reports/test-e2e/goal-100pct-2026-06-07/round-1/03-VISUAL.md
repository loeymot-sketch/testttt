# AGENT 03 — VISUAL CAPTURE ANALYST · Round 1 · 2026-06-07

**Scope:** Capture EVERY effect/transaction/action on each surface, then ANALYZE
(Read the image) in DOUBLE perspective (CLIENT borne/OSS/ticket · OPERATOR
caisse/KDS/admin). Axes B (interface), C (visuel), G (perspective rôle).

**Method (advisor-aligned):** analyze-first (Read all existing captures), then
capture only gaps. **Currency / raw-label / decimal claims are DOM-text-verified
(Playwright `innerText` + regex), NOT eyeballed** — this caught one of my own
false positives (see DROPPED below). All captures + mutations on the disposable
clone `foodking_e2e` @ `http://127.0.0.1:8766`. Read-only on admin; kiosk
navigated (no order placed).

**Captures analyzed:** 22 pre-existing (central-sweep ×7, kiosk-full-order ×5,
kiosk-sweep ×3, kiosk-menu-flow ×2, encaissement-flow ×3, borne/kds lifecycle ×3)
+ 10 new gap captures in `tests/e2e/__screenshots__/visual-gaps-2026-06-07/`
(authored spec `tests/e2e/zz-visual-gaps-2026-06-07.spec.js`).

---

## VERDICT
No P0/P1 visual blockers (blocking=false). The product is visually coherent,
Cayenne-branded, with proper empty/error states on the surfaces I reached.
Findings: 1×P2 (F-VIS-02 en-US time, **PROD-confirmed config**) + 6×P3 (toast
number, avatar alt-leak, kiosk currency inconsistency, upsell blank images, KDS
+ stock truncation). The §4 visual deliverable **"Kiosk WIZARD composeur"** is
captured and CLEAN (step 1 fully validated); the kiosk **confirmation screen** is
captured and correct (F-VIS-05 resolved). `/admin/pos` + POS popup wizard +
deeper kiosk error states are explicit TODO (deferred to agents 04/05) — not
silently passed. One self-caught false positive DROPPED (dashboard decimal).

---

## DOUBLE-PERSPECTIVE READ (G1/G2/G3)

### CLIENT surfaces
| Surface | Capture | Verdict | Notes |
|---|---|---|---|
| Borne idle | kiosk-sweep/idle.png | ✅ usable | "Bienvenue! / À emporter / CHOISISSEZ UNE OPTION" — orange brand, light mode. Low-res capture (kiosk viewport), product OK. |
| Borne catégorie (Tacos) | visual-gaps/kiosk-cat-tacos.png | ✅ usable | Cards show name+desc+price ("TACOS / 1 viande+frites+sauce / €8,50") + "Personnaliser" badge for wizard items. |
| Borne WIZARD composeur | visual-gaps/kiosk-wizard-step1.png | ✅ usable | "VOUS COMPOSEZ / TACOS", 4-step stepper (VIANDE/SAUCE/MENU/RÉCAP), progress bar, "0/1", POULET MARINÉ/CURRY cards, footer ABANDONNER/PRÉCÉDENT/SUIVANT + Total €8,50. No raw label, FR clean, big touch targets. **§4 gap closed.** |
| Borne panier (vide) | kiosk-sweep/cart.png | ✅ usable | "VOTRE PANIER / 0 article / Votre panier est vide / Ajouter des articles" — good empty state. |
| Borne panier (1 art) | kiosk-full-order/2-cart.png | ✅ usable | Item line, sous-total/total, code promo, carte fidélité CTA. |
| Borne upsell | kiosk-full-order/2b-upsell.png | ⚠️ | "ET POUR TERMINER?" 3 cards — **product images BLANK (gray placeholder)** for all 3 upsell items (F-VIS-04). |
| Borne paiement (Plan B) | kiosk-full-order/3-payment.png | ✅ usable | "PAIEMENT À LA CAISSE / TOTAL À RÉGLER €1,00 / Confirmer ma commande". |
| Borne confirmation | kiosk-full-order/4-confirmation.png | ⚠️ | **Capture landed on IDLE screen, not a confirmation screen** — kiosk likely auto-reset; confirmation-with-order-number not captured (F-VIS-05). |
| Borne deep-link cat invalide | visual-gaps/kiosk-cat-invalid.png | ✅ | `?cat=99999` gracefully redirects to idle, no crash/raw label (good error handling). |
| OSS (suivi client) | central-sweep/oss.png | ✅ usable | "En préparation"(magenta)/"Prêt"(vert), N°A0001 large readable. Faint `—` placeholder in empty left col. |

### OPERATOR surfaces
| Surface | Capture | Verdict | Notes |
|---|---|---|---|
| Dashboard | visual-gaps/dashboard-full.png | ✅ | Total ventes 32 365,40 € / CA jour 79,50 € / Ticket moyen 6,12 € — **all comma-FR (DOM-verified)**. Time-aware "Bonsoir!". |
| Encaissement queue | central-sweep/encaissement.png | ✅ | Borne orders, `30,00 €` FR. Customer line = raw kiosk token `soak-kiosk-...` (seeded test data). |
| Encaissement modal | encaissement-flow/2-modal-cash.png | ✅ | 4 payment methods present (Espèce/Terminal manuel/Mobile/Ticket resto — ex-stubs live), numpad, MONTANT TOTAL (large letter-spaced display). |
| Encaissement after | encaissement-flow/3-after.png | ⚠️ | Drawer-sim toast OK; **generic toast "Commande N° encaissée" missing order number** (F-VIS-01). Order removed from queue (good before/after). |
| KDS (admin overview) | central-sweep/kds.png | ✅ | "Aucune commande en cours" empty state + RÉCEMMENT SERVIES chips, 60s refresh banner. |
| KDS (chef board) | visual-gaps/kds-chef-board.png | ✅ usable | K0901-K0904 cards, items, timers, "Prêt" bump, "+3 en attente". Banner warns bump is per-browser (sync note, agent-01). "ATTENT.." truncated at card edge (F-VIS-06 minor). |
| Historique | central-sweep/historique.png | ⚠️ | N° fiscal/origine/montant OK; **time = en-US "04:30 AM / 03:42 PM" not FR 24h** (F-VIS-02). |
| Order-show (valid) | visual-gaps/order-show-4161.png | ⚠️ | Complete, FR currency; Imprimer Facture + Rembourser present; **time "12:37 PM" en-US** (F-VIS-02); no on-screen TVA line (agent-09). |
| Order-show (NULL serial) | visual-gaps/order-show-68.png | ⚠️ | **Broken empty shell**: "N° Commande: # —", no items, raw "avat" alt-text (F-VIS-03). |
| Stock & Produits | central-sweep/stock-rupture.png | ⚠️ | Toggle UI clean; **product names truncated harshly in cards ("Sandwic…", "Big Ca…", "Menu…")** (F-VIS-07). |
| Clients | central-sweep/customers-loyalty.png | ✅ | Table NOM/EMAIL/TEL/STATUT/ACTION; no loyalty-points column in list (likely on detail). |

---

## FINDINGS (each with file:line + reproduction + evidence)

### F-VIS-01 · P3 · Encaissement success toast omits order number
- **Where:** `resources/js/components/admin/encaissement/EncaissementComponent.vue:190`
- **Code:** `alertService.success(this.$t('label.encaisser_success', { order: '' }));` — passes empty `order`. Template `fr.json:557 "Commande N°{order} encaissée"` → renders **"Commande N° encaissée"** (dangling N°).
- **Repro:** `/admin/encaissement` → click "Encaisser". Top toast reads "Commande N° encaissée"; the drawer toast (`cash_drawer_opened_simulation`, fr.json:559) correctly includes the number.
- **Evidence:** `__screenshots__/encaissement-flow-2026-06-07/3-after.png` (two toasts visible, top one number-less).
- **Reco:** pass the order serial: `{ order: order.order_serial_no }` (PosCounterCollectModal.vue:538 already does this correctly).

### F-VIS-02 · P2 (config/FR-locale, PROD-confirmed) · Timestamps render en-US 12h AM/PM, not FR 24h
- **Where:** `app/Libraries/AppLibrary.php:32,40,56,432` → `env('TIME_FORMAT', 'h:i A')`. `order_datetime` rendered raw in `PosOrderShowComponent.vue:27` and historique.
- **PROD-confirmed (not a clone artifact):** `TIME_FORMAT="h:i A"` is set in **BOTH `.env` (operating) AND `.env.e2e`** — line 69 of each. So the operating box also renders en-US AM/PM. `DATE_FORMAT=d-m-Y` (FR) is fine; only the time is wrong.
- **Repro:** `/admin/historique` shows "04:30 AM / 03:42 PM"; `/admin/pos-orders/show/4161` shows "12:37 PM, 07-06-2026".
- **Evidence:** `central-sweep/historique.png`, `visual-gaps/order-show-4161.png`; `grep TIME_FORMAT .env .env.e2e`.
- **Reco:** set `TIME_FORMAT=H:i` (FR 24h) in the operating + e2e env. Bumped to P2 because it's the live config and a French restaurant receipt/historique showing "AM/PM" is a real FR-locale defect, not a clone quirk. Owner/config gate (env edit, not code).

### F-VIS-03 · P3 · Broken avatar alt-leak + empty shell on order-show of a soft-deleted/empty order
- **Where:** `PosOrderShowComponent.vue:318` `<img class="w-8 rounded-full" :src="orderUser.image" alt="avatar">` → when `orderUser.image` is empty/broken the browser shows the `alt` text "avatar", clipped by the 32px (`w-8`) box to **"avat"**. Generalizable: fires for ANY order whose user has no image. Plus header `N° Commande: #` renders with an empty serial.
- **Repro:** `/admin/pos-orders/show/68`. DB truth: `SELECT (SELECT COUNT(*) FROM order_items WHERE order_id=68) items, order_serial_no, status, deleted_at FROM orders WHERE id=68` → **items=0, serial=NULL, status=4, deleted_at='2026-05-28 18:02:26'** (order 68 is SOFT-DELETED + itemless). The order-show route loads it anyway (no trashed/empty guard) and renders an empty shell.
- **Evidence:** `visual-gaps/order-show-68.png` ("# —", empty Détails, 0,00 € totals, raw "avat").
- **Reco:** (a) durable fix — give the `<img>` a default `:src` / `v-if` so `alt` never leaks (affects all orders, not just edge); (b) guard order-show against trashed/itemless ids (404 or "commande indisponible"). Likelihood is low (reachable only by direct URL to a trashed id), hence P3. Flag to agent-02/04 whether trashed orders should be navigable.

### F-VIS-04 · P3 · Kiosk upsell cards show blank product images
- **Where:** `KioskUpsellComponent.vue` (image binding). The 3 upsell items (Menu/Boisson Seule/Frites Seules) render gray placeholder, no image.
- **Repro:** kiosk order flow → cart → checkout → "ET POUR TERMINER?" upsell screen.
- **Evidence:** `kiosk-full-order/2b-upsell.png` (3 cards, all image areas blank/gray).
- **Reco:** verify upsell items have image assets seeded / image fallback emoji like the wizard does (`KioskStepViandeComponent.vue:63` uses emoji fallback). On the clone these items may simply lack images; confirm on prod menu before healing.

### F-VIS-05 · RESOLVED (not a defect) · Kiosk confirmation screen exists and is correct
- Re-shot immediately on landing (spec `zz-visual-confirm-2026-06-07.spec.js`, 0.6 s dwell). URL `/kiosk/cash-instruction?number=A0010&total=1`. Screen: "💶 Rendez-vous en caisse / Présentez votre numéro à un membre de l'équipe / Numéro de commande **#A0010** (large orange) / Montant à régler **1,00 €** / Paiement en espèces uniquement à la caisse / Retour à l'accueil dans 25 s".
- **Evidence:** `visual-gaps/kiosk-confirmation-fresh.png` + `[CONF]` stdout. The earlier `4-confirmation.png` landing on idle was a capture-timing artifact (auto-reset countdown), NOT a missing screen. **Confirmation screen PASS.**

### F-VIS-08 · P3 · Kiosk currency format is non-FR AND inconsistent across its own screens
- **Where:** kiosk price mixin reads currency symbol+position from Vuex `globalState.lists` (`formatPrice.js:14-21` documents the separate mechanism). Admin honors `CURRENCY_POSITION=10` (=RIGHT, suffix — verified `app/Enums/CurrencyPosition.php:8`).
- **Defect:** the kiosk does NOT honor the same RIGHT/suffix convention. Cart/category/payment render **`€8,50` / `€1,00` (€ PREFIX)** while the cash-instruction confirmation renders **`1,00 €` (€ SUFFIX)** — inconsistent within the kiosk, and the prefix form is non-standard French (FR convention = suffix, which admin already uses).
- **Repro:** kiosk cart `€1,00` (`kiosk-full-order/2-cart.png`) vs confirmation `1,00 €` (`visual-gaps/kiosk-confirmation-fresh.png`).
- **Evidence:** the two captures above; `CURRENCY_POSITION=10` in `.env`/`.env.e2e` line 66 (= RIGHT, but kiosk shows LEFT).
- **Reco:** align the kiosk price mixin to the same FR suffix (`1,00 €`) the admin/`formatPrice.js` uses, OR ensure the Vuex currency record carries position=RIGHT. CLIENT-facing FR clarity (axis C3/C4 "FR partout"). Frozen-zone caution: `KioskWizardComponent/AppComponent` are frozen — fix in the non-frozen price mixin/helper. P3 (cosmetic, no money error).

### F-VIS-06 · P3 · Chef-KDS card header label truncated ("ATTENT..")
- **Where:** `KitchenDisplaySystemComponent` card header (the elapsed-time/"ATTENTE" label is clipped at the card's right edge).
- **Evidence:** `visual-gaps/kds-chef-board.png` (all 4 cards show "ATTENT.." cut off; timer "01:45" beside the big order number).
- **Reco:** cosmetic — give the header label room or wrap; low priority on a working kitchen board.

### F-VIS-07 · P3 · Stock-management product card names harshly truncated
- **Where:** `/admin/stock/rupture` product cards (`Sandwic…`, `Big Ca…`, `Menu…`).
- **Evidence:** `central-sweep/stock-rupture.png`.
- **Reco:** widen card / two-line clamp so operators can read the product they toggle. The category list (left) shows full names; only the right-hand cards clip.

---

## DROPPED (false positive — verified, NOT reported as defect)
- **"Dashboard daily KPIs use en-US period decimal (1.50 €)"** — DROPPED. My
  initial eyeball read of `central-sweep/dashboard.png` mistook the comma for a
  period at low zoom. DOM-truth extraction proves comma-FR: `KPI[Chiffre
  d'Affaires du Jour] => "79,50 €"`, `KPI[Ticket Moyen] => "6,12 €"`,
  `KPI[Total ventes] => "32 365,40 €"` (spec stdout `[VGAP]`). Backend
  `DashboardService.php:435` formats via `AppLibrary::currencyAmountFormat` →
  `NumberFormatter('fr_FR', CURRENCY)` (ext-intl present, `php -m | grep intl`
  = present). **Dashboard currency is correct FR.** (Validates the advisor's
  "don't eyeball decimals" rule.)

## COVERAGE NOT REACHED THIS ROUND (explicit TODO — NOT silently passed)
- **`/admin/pos` (POS register)** and **POS popup wizard** (`public/js/pos-wizard.js`,
  frozen) — listed on my mission surfaces but NOT captured this round. Deferred to
  **agent-04 (POS/Caisse)** who pilots POS in parallel; I will capture/analyze
  their effects in the next round. Marked TODO, not PASS.
- **Kiosk error states beyond invalid-cat**: paiement-refusé, produit-retiré
  mid-order, stock-0 on a wizard option — NOT exercised (need live stock toggle /
  payment-decline injection). Deferred to **agent-05 (Kiosk)** + agent-01 (sync).
  Only the invalid-category deep-link error path was verified (graceful, PASS).
- **Ticket print CONTENT (TVA/legal footer/operator line)** — the "Imprimer La
  Facture" button triggers the browser print dialog; the printable receipt CONTENT
  (legal mentions) is rendered by `ReceiptDataService` and owned by **agent-09**.
  I captured the on-screen order-show (operator view); receipt legal content = 09.

## OBSERVATIONS (not defects — context for other agents)
- **Chef-KDS bump is per-browser** (banner states it explicitly) — single-box V1
  acceptable; cross-screen KDS sync = agent-01 scope.
- **TVA not shown on on-screen order-show** (`hasTVA=false` in DOM) — the legal
  TVA breakdown lives on the printed receipt (ReceiptDataService) = agent-09.

---

## AXIS STATUS (B/C/G)
- **C1 before/after captured:** PARTIAL — encaissement (before queue / after toast+removed) and KDS lifecycle (before/after) captured; kiosk confirmation before/after incomplete (F-VIS-05).
- **C2 layout/branding:** PASS — no overflow/broken layout on any reached surface; Cayenne orange consistent; OSS uses status-semantic magenta/green (convention).
- **C3 raw labels / phantom numbers (DOM-verified):** PASS — `RAW_LABEL_RE`/`PHANTOM_RE` over innerText = none on kds-chef, dashboard, tacos grid, wizard step1, order-show 4161/68, invalid-cat. Exceptions: F-VIS-01 dangling "N°", F-VIS-03 "avat" alt-leak.
- **C4 palette:** PASS — brand orange/jaune present; no eyeball-only palette claim made.
- **C5 readability/contrast:** NOT CERTIFIED via screenshot (advisor + project history: a11y/contrast needs axe-core/computed-style; out of this round's tooling). Truncations noted (F-VIS-06/07) are layout, not contrast.
- **G1 client perspective:** PASS — borne flow, OSS, wizard all client-clear.
- **G2 operator perspective:** PASS — caisse encaissement, KDS chef board, dashboard, historique all operator-usable.
- **G3 per-screen role usability:** PASS (see double-perspective table).

## ARTIFACTS
- New specs: `tests/e2e/zz-visual-gaps-2026-06-07.spec.js`, `tests/e2e/zz-visual-confirm-2026-06-07.spec.js`
- Captures: `tests/e2e/__screenshots__/visual-gaps-2026-06-07/` (11) + analyzed pre-existing folders (central-sweep, kiosk-*, encaissement-flow, *-lifecycle).
- Frozen-zone touch: **NONE** (audit/capture only; `git status` shows only my untracked spec + screenshots + this report).
