# POS (Caisse) — UI/UX page-by-page + every-button audit

- **Date**: 2026-06-08
- **Surface**: POS / Caisse — V1 LOCAL "Le Cayenne" (single-box, FR-locale, branch_id=1)
- **Harness**: disposable clone `http://127.0.0.1:8766` (DB `foodking_e2e`), POS operator `pos@lecayenne.fr` (id=3, branch 1), desktop 1440×900
- **Method**: READ-ONLY live walk (Playwright). No mutating confirm/submit fired. Every finding has file:line + screenshot.
- **Spec**: `tests/e2e/zz-uiux-pos-2026-06-08.spec.js` (+ `zz-pos-parked-2026-06-08.spec.js`)
- **Screenshots**: `tests/e2e/__screenshots__/uiux-pos-2026-06-08/`
- **Data state** (clone, branch 1): 45 items, open cash session (opened_by id=3, fond 50,00 €), 37 PREPARED kiosk/takeaway, 24 kiosk cash-pending, 10 parked orders. All surfaces exercised against real data.

---

## VERDICT

POS caisse is in **strong shape**. Operator bar, cart panel, encaissement (counter-collect) modal, cash-drawer dialog (open/active/close + variance-reason gate), tracker kanban, and parked-orders slide-over all **work, route correctly, give feedback, and are FR-correct** — with **zero console errors** on the main screen. **No dead buttons** found (the one suspected dead control was a test-targeting error — see PARKED note). Feedback + state-transition was **verified empirically** by firing real mutations on the disposable clone: counter-collect confirm (✅ toast + list refresh), parked recall (✅ cart populates 0→1, 3,00 €) — not just code-inferred. The defects are: (1) one **English i18n leak** ("5 days ago") on parked-orders timestamps — non-frozen, 1-line fix; (2) the FROZEN POS wizard popup renders price in **en-US format `€3.00`** instead of FR `3,00 €` (owner_gated, confirms memory POS-ERG-07); (3) a handful of cosmetic copy items. The fiscal Z-report daily close is **not reachable by the POS Operator** (admin-only by NF525 separation of duties — see ACCESS-GAP, not a defect).

---

## PAGE-BY-PAGE BREAKDOWN

| # | Screen / Control | Works? | Target / Behavior | FR copy | Feedback | Screenshot | Notes |
|---|---|---|---|---|---|---|---|
| 1 | **Main caisse** `/admin/pos` | ✅ | Loads, item grid (45 tiles), cart panel, 2 shortcut panels | ✅ | n/a | `01-pos-main-loaded` | 0 console errors |
| 2 | Operator-bar: **À encaisser** (badge) | ✅ | Opens kiosk-cash panel | ✅ | — | `01` | badge=count of cash-pending |
| 3 | Operator-bar: **Suivi commandes** | ✅ | router → `/admin/pos-orders-tracker` | ✅ | — | `01`,`13` | tone "ready" when PREPARED>0 |
| 4 | Operator-bar: **Écran client** | ✅ | router → OSS in `target=_blank` | ✅ | — | `01` | new tab, noopener |
| 5 | Operator-bar: **Appliquer réduction fidélité** | ✅ | Disabled w/ no order; FR hint tooltip | ✅ | tooltip | `01`,`15` | `disabled=true`, title="Créez d'abord une commande…" — correct gate |
| 6 | Operator-bar: **Ouvrir tiroir** (no-sale) | ✅ | `triggerNoSaleOpenDrawer` (drawer sim, traced) | ✅ | — | `15` | enabled; title explains traced no-sale. NOT clicked (hardware sim) |
| 7 | Operator-bar: **Caisse** (session) | ✅ | Opens cash-drawer dialog | ✅ | — | `10` | tone "ready" when session active |
| 8 | Shortcut panel **Prêt à livrer** + **✓ Livré** | ✅ | `markDelivered` → idempotent status→DELIVERED, reloads list+stats, success toast | ✅ | ✅ toast | `02`,`05` | 1-click; FR toast "Commande N°{x} marquée Livré" |
| 9 | Shortcut panel **À encaisser borne** + **Encaisser** | ✅ | `openCounterCollect(o)` → counter-collect modal | ✅ | — | `02`,`08` | 4 live rows + "Voir plus" |
| 10 | Shortcut panels (empty) | ✅ | Empty copy + "Mis à jour il y a Xs" health beacon | ✅ | — | `13` (PRÊTS) | always-render (Q10) — distinguishes calm vs outage |
| 11 | **Item tile** click → wizard | ✅ | Opens Vue `#item-variation-modal` (qty, instruction, ticket preview, Ajouter au panier) | ✅* | — | `04` | *price en-US (see P1-W) — **FROZEN** |
| 12 | Wizard **Ajouter au panier** | ✅ | Adds line to cart | ✅ | ✅ green toast "Article ajouté au panier" | `05` | |
| 13 | Wizard **Annuler** / qty +/− | ✅ | Closes / adjusts qty | ✅ | — | `04` | FROZEN |
| 14 | **Cart panel** (ticket) | ✅ | Line w/ qty stepper, subtotal/total FR (`3,00 €`) | ✅ | aria-live totals | `05` | |
| 15 | Cart: **Discount input + Appliquer** | ✅ | Apply disabled w/o reason; enabled w/ reason (mirrors backend) | ✅ | disabled-state | `06`,`07` | `APPLY_DISABLED_NO_REASON=true`, `=false` w/ reason |
| 16 | Cart: **Raison** field | ✅ | Required-flag, char counter /255, invalid alert | ✅ | ✅ | `06`,`07` | |
| 17 | Cart: **Order-type** segmented (À emporter / Livraison) | ✅ | Toggles; inline delivery form | ✅ | active-state | `05` | Dine-in hidden (V1 flag off) |
| 18 | Cart: **Annuler dernière ligne** | ✅ | `cancelLastCartLine` undo | ✅ | — | `05` | |
| 19 | Cart: **Commande · {total}** (Pay) | ✅ | `orderSubmit` → opens **FROZEN** PaymentComponent | ✅ | — | `05` | total in label; payment step FROZEN |
| 20 | **Counter-collect modal** (encaissement borne) | ✅ | Total FR `1,50 €`, 4 modes, cash numpad, change calc `18,50 €`, card-ref block, close-self | ✅ | live change | `08`,`09` | **NON-frozen, excellent.** NOT confirmed (read-only) |
| 21 | **Cash-drawer dialog** — active view | ✅ | Fond initial, ouverte le, mouvements, montant attendu; Voir mouvements / Clôturer | ✅ | — | `10` | |
| 22 | Cash dialog — **Clôturer** (close form) | ✅ | Montant compté + denom quick-add, attendu, écart (green +947), Raison* required, submit disabled until reason | ✅ | disabled-gate | `11`,`12` | **variance-reason gate confirmed fixed** (verify-not-relitigate ✓) |
| 23 | **Tracker kanban** `/admin/pos-orders-tracker` | ✅ | 5 lanes: À ENCAISSER / EN PRÉPARATION / PRÊTS À SERVIR / EN LIVRAISON / LIVRÉS; filters; cards FR price; Encaisser/eye/print/cancel | ✅ | — | `13` | lanes FR. Admin sidebar wraps it (minor) |
| 24 | **Parked-orders** slide-over (📦 Commandes en attente) | ✅ | Opens slide-over, 10 rows, search, **Restaurer**(recall)+**Supprimer** per row, price `3,00 €` | ✅* | — | `16` | *"5 days ago" EN leak (P1-I) + "1 Articles" plural |
| 25 | **Mettre en attente** (park current) | ✅ | Guards empty cart (toast "Ajoutez au moins un article…"); else **native `window.prompt()`** for label | ✅ | ✅ toast | `14` | native prompt = UX gap (P3) |
| 26 | **Z-report (fiscal daily close)** | n/a on POS | NOT reachable by POS Operator. Cashier's only "close" = cash-drawer *session* close (#22). NF525 daily Z (`POST /fiscal/z-report/close`, frozen `ZReportService`) is **admin/branch-manager only** | — | — | — | **Access-gap by design — see ACCESS-GAP below.** No Z-close control exists on the POS surface |

\* = works but has a cosmetic/i18n defect noted in fix list.

### Empirical mutation feedback evidence (fired on disposable clone :8766)

Three load-bearing cashier mutations were executed live to confirm feedback + state transition (not just code-read):

| Action | Result (empirical) | Feedback | State transition | Screenshot |
|---|---|---|---|---|
| **Counter-collect CONFIRM** (cash, exact amount) | ✅ works | green toast "Tiroir ouvert (simulation)" + modal closes | kiosk-pending pool 24→23 in DB; panel list refreshed (paid order gone, next pending filled the slot — panel caps at 4 rows) | `17-collect-after-confirm` |
| **Restaurer** (parked recall) | ✅ works | cart populates | cart Articles 0→1, total 0,00→**3,00 €**; slide-over closes | `18-after-recall` |
| **Livré** (1-click) | code-verified ✅ | `markDelivered` → idempotent `posOrder/changeStatus`→DELIVERED + reload + success toast | not fired live: "Prêt à livrer" panel legitimately empty at run time (feed is **time-scoped to today's active OSS orders** — historical PREPARED rows aged out; correct behavior). Shares the exact `posOrder/changeStatus`+reload path proven by the recall + confirm refreshes | n/a (panel empty) |

**No stale-kanban observed**: counter-collect list refreshed correctly after confirm; recall removed the row + populated cart; tracker lanes carry live time-scoped data. The "Prêt à livrer = 0" while DB has 39 PREPARED is NOT a bug — `loadReadyOrders` sources the time-scoped OSS active feed (`orderStatusScreenOrder/lists`), not the full historical table.

---

## PRIORITIZED NON-FROZEN FIX LIST

### [P1-I] Parked-orders timestamp renders in ENGLISH ("5 days ago")
- **file**: `resources/js/components/admin/pos/ParkedOrdersComponent.vue:237`
- **broken**: `const locale = this.setting.site_default_language || navigator.language || 'fr';` — on the admin POS surface `this.setting` (`frontendSetting/lists` getter) is empty `{}`, so `site_default_language` is undefined → falls through to `navigator.language` (en-US in any non-FR browser) → `Intl.RelativeTimeFormat` emits **English** "5 days ago" / "6 days ago".
- **repro**: Open POS → 📦 "Commandes en attente" → each parked card shows "X Articles · **5 days ago**" in English. Screenshot `16-parked-list.png`. (`formatMoney` at line 219 hardcodes `'fr-FR'` and renders prices correctly — proof the locale-fallback is the sole offender.)
- **scope-minimal fix**: line 237 → **hardcode** `const locale = 'fr-FR';` (drop the `this.setting...|| navigator.language` chain entirely — mirror exactly what `formatMoney` at line 219 already does, which renders correctly). The `|| 'fr-FR'` variant is NOT recommended: it silently leaves the bug if `this.setting` ever resolves a non-FR `site_default_language`. V1 is FR-locked (ADR-007 immutable), so hardcoding fr-FR is correct and matches the proven-good sibling formatter.
- **owner_gated**: false

### [P1-W] FROZEN wizard popup renders price in en-US format `€3.00`
- **file**: `public/js/pos-wizard.js` (FROZEN — POS Vanilla JS wizard) — header price + Total + "Aperçu ticket"
- **broken**: Wizard shows `€3.00` (symbol-first, dot decimal = en-US `Intl` format) instead of FR `3,00 €`. Confirms memory **POS-ERG-07**. Inconsistent with the rest of the page (cart, modals, tracker all render `3,00 €`).
- **repro**: Open POS → click any item tile → wizard popup. Header "Menu (Frites + Boisson) **€3.00**", Total "**€3.00**". Screenshot `04-wizard-open.png`.
- **scope-minimal fix**: change the wizard's currency formatter to `Intl.NumberFormat('fr-FR', {style:'currency', currency:'EUR'})` (or `'3,00 €'` template). **Requires LOCK / owner gate** (frozen file).
- **owner_gated**: **true**

### [P3-C1] "1 Articles" — French plural agreement
- **file**: `resources/js/components/admin/pos/ParkedOrdersComponent.vue:~67` (`{{ order.items_count }} {{ $t('label.items') }}`) — `label.items` = "Articles" (always plural)
- **broken**: Renders "1 Articles" for single-item parked orders (should be "1 Article").
- **repro**: parked slide-over, any 1-item card. Screenshot `16-parked-list.png`.
- **scope-minimal fix**: pluralize via i18n choice/`order.items_count > 1 ? 'Articles' : 'Article'`. Cosmetic.
- **owner_gated**: false

### [P3-C2] Encaissement mode label "Espèce" (singular)
- **file**: `resources/js/languages/fr.json` → `label.encaisser_mode_cash = "Espèce"`
- **broken**: Conventional FR cash label is "Espèces" (plural). Minor copy.
- **repro**: counter-collect modal, first payment mode. Screenshot `08`.
- **scope-minimal fix**: "Espèce" → "Espèces".
- **owner_gated**: false

### [P3-U] Park-current uses native `window.prompt()` for label
- **file**: `resources/js/components/admin/pos/PosComponent.vue:~3496` (`promptParkOrder` → `window.prompt(promptLabel, '')`)
- **broken**: A native browser prompt is jarring on a touch POS, can't be styled/branded, blocks the JS thread, and is awkward on a kiosk-style screen. Functional but below the polish of the rest of POS.
- **repro**: add an item → "Mettre en attente" → native OS prompt appears.
- **scope-minimal fix**: replace with a small in-component labelled modal/inline input (pattern already exists e.g. cancel-reason dialog).
- **owner_gated**: false (non-frozen; UX enhancement)

### [P3-L] Tracker kanban wrapped by admin sidebar (full-screen intent)
- **file**: tracker route renders inside the admin shell (`/admin/pos-orders-tracker` via `posOrderRoutes.js:52`)
- **broken**: The tracker has a "Retour caisse" button implying a standalone full-screen caisse surface, yet the left admin nav (Tableau De Bord, POS, …) still wraps it — minor visual inconsistency, slightly reduces the at-a-glance kanban width.
- **repro**: Screenshot `13-tracker-kanban.png` (sidebar visible left).
- **scope-minimal fix**: optional — render tracker on a chromeless layout like the OSS/customer screen. Low priority; not a functional defect.
- **owner_gated**: false

### [P2-RACE] (verify-not-relitigate) Order-board fetch last-write-wins
- Prior falsification sweep flagged `fetchOrders` last-write-wins race (P2) in the POS order board. **Confirmed still present in principle** (multiple async loaders — `loadReadyOrders`, `loadActiveOrdersStats`, tracker fetch — without request-sequence guards). Not re-filed as new; tracked from prior sweep. Low real-world impact on single-box single-operator V1.

---

## ACCESS-GAP (informational, by design — not a defect)

- **Z-report (NF525 fiscal daily close) has NO entry point on the POS caisse surface, and the POS Operator role cannot reach it.** Verified live: `pos@lecayenne.fr` (role "POS Operator") holds exactly **7 permissions** — `dashboard, pos, pos-orders, pos-discount-up-to-10, pos.redeem-loyalty, kitchen-display-system, order-status-screen`. It has **no** `pos-manage-fiscal`, **no** `cash-sessions-report` (so `/admin/cash-overview` is also out of reach), and **no** fiscal/Z permission. The fiscal Z (`POST /fiscal/z-report/close`, frozen `ZReportService`) is admin/branch-manager only.
- **Implication for this audit**: the only "close" a cashier performs is the cash-drawer **session** close ("Clôturer la caisse" — #22, audited & working with variance-reason gate). The daily fiscal Z is an admin task on a different surface (dashboard `LastZReportWidget` / fiscal routes) and was therefore out of scope for the POS-operator walk. This is the expected NF525 separation of duties, not a missing button. No action required beyond noting that the cashier doctrine documentation should make the session-close vs fiscal-Z distinction explicit so an operator does not expect a "close the day" button on the caisse.

## VERIFY-NOT-RELITIGATE (confirmed current state)

- **FP-05 caisse WS-loss banner** — healed: `ConnectionStatusBanner` + `pos-offline-banner` (role=alert, queue depth, Synchroniser btn) present on main screen. ✅
- **Cash-drawer variance-reason** — fixed: close form requires a reason when `liveVariance ≠ 0`; submit disabled until provided (`12-cash-close-variance.png`). ✅
- **fetchOrders last-write-wins (P2)** — still structurally present (see P2-RACE above), not re-litigated as new. ✅

---

## FROZEN (report-only, owner_gated=true — DO NOT edit)

- **POS wizard popup** (`public/js/pos-wizard.js` / `pos-wizard.css` / `admin-pos-v4.blade.php`) — only defect is **P1-W en-US price** (`€3.00`). Otherwise renders cleanly (qty stepper, instruction field, ticket preview, FR "Ajouter au panier"/"Annuler").
- **PaymentComponent.vue** — reached by cart Pay button (`orderSubmit`). Not audited for edits.
- **PosV5TrancheRow.vue**, Fiscal/*, PricingService, OrderStateMachine — untouched.

---

## TOP 10 MUST-FIX

1. **[P1-I]** Parked-orders English "5 days ago" → force `'fr-FR'` locale — `ParkedOrdersComponent.vue:237` (non-frozen, 1-line). **#1 felt-product defect.**
2. **[P1-W]** Wizard price `€3.00` → FR `3,00 €` — `public/js/pos-wizard.js` (**owner_gated**, frozen, confirms POS-ERG-07).
3. **[P3-C1]** "1 Articles" → "1 Article" plural — `ParkedOrdersComponent.vue:~67`.
4. **[P3-C2]** "Espèce" → "Espèces" — `fr.json label.encaisser_mode_cash`.
5. **[P3-U]** Replace native `window.prompt()` park-label with styled in-app input — `PosComponent.vue:~3496`.
6. **[P3-L]** Tracker chromeless full-screen layout (optional) — `posOrderRoutes.js:52`.
7. **[P2-RACE]** Add request-sequence guard to order-board loaders (from prior sweep) — low V1 impact.
8. *(none — no further real defects found across 25 audited controls)*
9. *(verify) variance-reason gate — confirmed working, no action.*
10. *(verify) WS-loss banner — confirmed present, no action.*

Items 1 + 3–6 are non-frozen and shippable now. Item 2 needs an owner LOCK gate.
