# Admin Dashboard — Page-by-Page + Every-Button UI/UX Audit

**Date:** 2026-06-08
**Surface:** Admin dashboard (V1 LOCAL "Le Cayenne")
**Harness:** disposable clone `http://127.0.0.1:8766` (DB `foodking_e2e`, 2219 orders / 63 items / 19 z_reports / 10 cash sessions — **live data, not empty-state**)
**Method:** Playwright @1440x900. (1) Page-load instrumentation: `pageerror` + `console.error` + HTTP≥400 listeners on every page. (2) **Click-delta probes** — for each control, snapshot URL/rows/modal-state, click, then observe navigation / XHR / DOM-mutation / dropdown-open / drawer-open / dialog / **download** to empirically classify WORKS vs DEAD. (3) Source-grep of each page's root Vue component for the complete `@click`/`@change` inventory + exact `file:line`. (4) Verify-before-report: every NO-OP candidate was re-probed with control-appropriate detection before being kept or dropped.
**Login:** `loginAsAdmin` (admin@lecayenne.fr / 123456) → `/admin/dashboard`.
**Spec files (scratch):** `tests/e2e/zz-uiux-*-2026-06-08.spec.js`. **Screenshots/JSON:** `tests/e2e/__screenshots__/uiux-dashboard-2026-06-08/`.

---

## TOP 10 MUST-FIX

| # | Sev | What | file:line | Daily-path? |
|---|-----|------|-----------|-------------|
| 0 | **P2** | **EVERY CRUD success toast is half-English** — `"Entreprise Updated Successfully."`, `"… Created Successfully."`, `"… Deleted Successfully."` (translated FR label + hardcoded EN suffix). **One file fixes 102 components.** | `resources/js/services/alertService.js:52,54,57` (`successFlip`) — called by 102 admin components | **yes (all CRUD)** |
| 1 | **P2** | en-US **AM/PM** time in every order date column (`08:52 AM`, `08:46 PM`) — FR must be 24h. Single backend root cause; also affects exports & receipts. | `app/Libraries/AppLibrary.php:32,40` (`TIME_FORMAT` default `'h:i A'`) + `.env.e2e`/`.env` `TIME_FORMAT="h:i A"` | yes (reports) |
| 2 | **P2** | Dashboard "Dernier Z" timestamp rendered en-US `6/8/2026, 12:58:19 AM` (M/D/YYYY + AM/PM). | `resources/js/components/admin/dashboard/LastZReportWidget.vue:58` (`d.toLocaleString()` → no `'fr-FR'`) | yes (dashboard) |
| 3 | **P2** | Pagination controls render **English "Previous" / "Next"** on every desktop list page (FR admin). | `resources/js/components/admin/components/pagination/PaginationBox.vue` + `PaginationSMBox.vue` (`TailwindPagination` default labels, no i18n slot) | yes (all lists) |
| 4 | **P3** | SLA Alerts widget shows raw `En attente depuis 15583 minutes` — not human-readable (should be `~11 j`). Hurts at-a-glance reading. | `resources/js/components/admin/dashboard/SlaAlertsComponent.vue:38` | yes (dashboard) |
| 5 | **P3** | `Exporter` button reveals its menu only via JS-toggled `.active`; no `:focus-within` CSS fallback and no `aria-expanded` on the trigger — keyboard/discoverability weak (it DOES open on mouse-click). | `ExportComponent.vue:2` + `resources/css/app.css:404-407` | yes (reports) |
| 6 | **P3** | Native `<input type="date">` on cash pages prints `06/08/2026` (browser-locale MM/DD) instead of FR DD/MM — minor, browser-dependent. | `CashOverviewComponent.vue` / `CashSessionReportListComponent.vue` date inputs | low |
| 7 | **P3** | Tracker source tabs carry no visible active style in some themes — the filter works but the admin can't always tell which source is selected. Add clear `aria-selected`/active styling. | `resources/js/components/admin/pos/PosOrdersTrackerComponent.vue:52` | yes (tracker) |

> **Bottom line:** **Zero literal dead/no-op controls** were found on any daily-path surface after rigorous verification. **Seven** "doesn't work" candidates the generic probe raised were each disproved as **harness artifacts** (see "Corrected non-findings") — including the `pos-orders-tracker` "Caisse" tab, which my loose `button:has-text("Caisse")` locator collided with the header `Caisse Le Cayenne` eyebrow; the precise `.pos-tracker-source-tab` selector shows Caisse filters `86 → 31` (and `31 pos + 55 kiosk + 0 online = 86` — perfectly consistent). The owner's "things that don't work" feeling is best explained by **localization/feedback polish** — half-English toasts on every save, en-US dates everywhere, English Previous/Next, raw `15583 minutes` — that makes the screen *feel* broken, not by literal dead buttons. All P-items below are non-frozen and scope-minimal.

### Verified non-issues (do NOT fix)
- "Two POS" confusion already resolved — the pos-v4 quick-access link is intentionally commented out (`DashboardComponent.vue:159`); no dead link remains.
- Transactions / pos-orders Export = **XLS + Print only** (no PDF) **by design** (`TransactionListComponent.vue:18-19`, `PosOrderListComponent.vue:14-15`).
- Hidden `settings.*` / customers / coupons / offers / delivery / online-orders modules = **intentionally hidden in V1** (`resources/js/config/v1-hidden-modules.js`) — not "missing".
- pos-orders-tracker "Caisse" tab = **WORKS** (false alarm, see above).

---

## HEADLINE STATE (page-load layer — STRONG)

Across **all 24 sidebar pages**:
- **0 uncaught page errors, 0 console errors, 0 HTTP ≥400** on initial render of every page.
- **No blank / 404-into-SPA pages.** Every sidebar destination renders real content.
- **No raw-label leakage** (`label.x`, `kiosk.x`, `menu.x`, `undefined`, `NaN`, `0undefined`) on any page body.
- Two "thin" sidebar entries are correct full surfaces: **OSS** (`order-status-screen`) = customer status wall with proper `Aucune commande` empty-state; **Messages** = customer-chat UI (3 conversations + composer).
- Audit Trail NF525 widget renders cleanly with FR relative time (`il y a 5 secondes`, `il y a 3 minutes`).

This contradicts a "many pages broken" read at the navigation level — every page loads. The complaint lives in the **interaction layer**, which is fully probed below.

---

## INTERACTION-LAYER PROBE — RESULTS (empirically verified)

| Control type | Pages probed | Verdict |
|---|---|---|
| **Export → XLS download** | sales-report, transactions, items-report, pos-orders | **WORKS** — real `.xlsx` downloads fire (`Rapport des ventes.xlsx`, `Transactions.xlsx`, …) |
| **Export → PDF download** | sales-report, items-report | **WORKS** (`Rapport articles.pdf`). transactions/pos-orders have **no PDF item by design** (XLS+Print only) |
| **Filtrer → actually filters** | sales-report, transactions, pos-orders | **WORKS** — impossible serial → table `10 → 1` row (empty-state), XHR fires |
| **Pagination Next / page 2** | employees, sales-report, transactions | **WORKS** — first row changes (page advances). _Earlier "UNCLICKABLE" was a hidden mobile `PaginationSMBox` (`sm:hidden`) matched by `.first()`._ |
| **Tabs (filter)** | ingredients (Tous/Viandes/Suppléments/Add-ons), tracker (Toutes/Caisse/Borne/En ligne) | **ALL WORK** — ingredient tabs change rows; tracker filters Toutes 86 / Caisse 31 / Borne 55 / En ligne 0 (consistent, verified with precise selector). |
| **Ajouter (create modal/drawer)** | employees, chefs, administrators, push-notifications, item-attributes, studio (article + catégorie) | **WORKS** — drawer/modal + form open every time |
| **Modifier (edit)** | employees | **WORKS** — edit drawer/form opens |
| **Supprimer (confirm)** | employees | **WORKS** — confirm dialog shown (cancelled, not confirmed) |
| **Save persists (E2E)** | item-attributes create | **WORKS** — `201 /api/admin/setting/item-attribute`, modal closes, **row persists after reload** |
| **Category filter chip** | studio (Burgers) | **WORKS** — grid body shrinks `2864 → 1229` chars |
| **Stock availability toggle** | stock/rupture (EN STOCK pill) | **WORKS** — `POST /admin/menu/availability/toggle → 200` |
| **Encaisser (collect)** | encaissement, tracker | **WORKS** — collect modal opens |
| **Actualiser** | encaissement | **WORKS** — XHR refresh |
| **Voir (detail)** | delivery-boy-cash | **WORKS** — navigates to `/admin/delivery-boy-cash-sessions/7` |
| **Envoyer l'email** | subscribers | **WORKS** — compose drawer opens |
| **EOD "PDF Clôture du jour"** | dashboard | **WORKS** — `200 OK application/pdf` |
| **Date-range presets** | dashboard widgets | Present inside the `@vuepic/vue-datepicker` calendar (open input to reveal) — standard, functional |

### Corrected non-findings (empirical beat inference — verify-before-report saved 7 false defects)
1. **Export "Exporter" dropdown** — source `ExportComponent.vue` is a static button with no `@click`; looked dead. Empirically the JS document-level dropdown handler toggles `.dropdown-list.active` → it opens. (P5 logs the missing keyboard/`aria` affordance.)
2. **EOD PDF** — first probe surfaced `Unauthenticated.`; that was a **timing artifact** (clicked before the Bearer token hydrated into Vuex). Re-probe = `200 application/pdf`.
3. **"Ajouter" across 5 modules** — flagged NO-OP because the side-drawer (`appService.sideDrawerShow()` → `.drawer.active`) wasn't in my modal selector. Re-probe with `.drawer.active` = all WORKS.
4. **Filtrer apply** — flagged NO-FILTER-EFFECT because a raw `evaluate()` `.value=` set didn't drive Vue's `v-model`. Native Playwright `fill()` = `10→1` rows, WORKS.
5. **pos-orders-tracker tabs** — flagged DEAD (client-side filter → no XHR, card-based not table). DOM-aware re-probe = WORKS (card counts change).
6. **item-attribute save** — flagged NO-POST; a fragile `.or()` button locator hadn't clicked the real `type=submit`. Exact `form button[type=submit]` = `201` + persists.
7. **pos-orders-tracker "Caisse" tab** — flagged DEAD across 3 runs; a loose `button:has-text("Caisse")` collided with the header `Caisse Le Cayenne` eyebrow / wrong element. Precise `.pos-tracker-source-tab` selector = Caisse filters `86 → 31`, active state flips. WORKS. (`_caisse2.json`)

> Settings company "Enregistrer" was also briefly inconclusive (probe watched POST; the save is a **PUT**). Re-probe = `PUT 200 /api/admin/setting/company` + toast. WORKS — though the toast is half-English (P2-EN).

---

## CONFIRMED DEFECT — en-US AM/PM time (FR-locale violation), dual root cause

**(a) Backend** `app/Libraries/AppLibrary.php`:
```
:32   $pattern = env('TIME_FORMAT', 'h:i A');                                  // time()
:40   $pattern = env('TIME_FORMAT', 'h:i A') . ', ' . env('DATE_FORMAT','d-m-Y'); // datetime()
```
`.env.e2e` and `.env` both set `TIME_FORMAT="h:i A"`. Result: every `order_datetime` column on **sales-report, transactions, pos-orders, historique** prints `08:52 AM` / `08:46 PM`. FR convention is 24h → fix `H:i`. Blast radius includes XLS/receipt exports (`OrderExport.php`, `SalesReportExport.php`) — all of which should be 24h, so the fix is correct everywhere. **`AppLibrary.php` is NOT a frozen file.** Cheapest fix = set `TIME_FORMAT=H:i` (env/owner data-op); code-default fix = change the two defaults to `'H:i'`.

**(b) Frontend** `resources/js/components/admin/dashboard/LastZReportWidget.vue:58` — `return d.toLocaleString();` (no locale) → dashboard "Dernier Z" shows `6/8/2026, 12:58:19 AM`. Fix: `d.toLocaleString('fr-FR')`. Independent of (a).

_Pages already correct (use `toLocaleString('fr-FR', {hour:'2-digit'})`): cash-overview (`07:46`), cash-sessions-report, pos-tracker, audit-trail (`il y a 3 minutes`)._

---

## PAGE-BY-PAGE BREAKDOWN

| Page | URL | controls audited (btn/link/input/select/rows) | working | DEAD/broken | screenshot | notes |
|---|---|---|---|---|---|---|
| Dashboard | /admin/dashboard | 13b/41l/6i · EOD + 12 quick-access + date presets | EOD, all quick-access links, presets | none dead | 00-dashboard-full.png, dashboard-sla-region.png | LastZ AM/PM (P2); SLA raw minutes (P4) |
| Stock & rupture | /admin/stock/rupture | 38b · availability toggles | toggle POST 200 | none | stock-toggle-after.png | works |
| Catalogue Studio | /admin/items/studio | 138b/77l · add article/cat + category chips | Ajouter article+cat, Burgers chip filter | none | final-studio.png, studio-burgers-filtered.png | richest surface, all probed actions work |
| Attributs articles | /admin/settings/item-attributes/list | 36b · Ajouter/Modifier/Supprimer | create persists (201), edit, delete-confirm | none | save-form-filled.png, save-after-submit.png | FR pagination here ("Affichage 1 à 9"); no success toast (P6) |
| Ingrédients | /admin/ingredients | 54b · 4 tabs + Voir les détails | all 4 tabs filter | none | ingredients.png | tabs WORK (rows change) |
| Commandes caisse | /admin/pos-orders | 34b · Filtrer/Export/Imprimer/Supprimer/pagination | filter, XLS, pagination | none | pos-orders.png | AM/PM (P1) |
| Suivi caisse kanban | /admin/pos-orders-tracker | 29b · 4 source tabs + Encaisser | **all 4 tabs** (Toutes 86 / Caisse 31 / Borne 55 / En ligne 0) + Encaisser | none | pos-orders-tracker.png, caisse-discriminator.png | client-side filter, counts consistent (31+55+0=86) |
| Historique | /admin/historique | 24b · Filtrer/pagination | filter, pagination | none | historique.png | AM/PM (P1) |
| Encaissement | /admin/encaissement | 38b · Actualiser + Encaisser×N | Actualiser, Encaisser modal | none | encaissement.png | works |
| Vue caisse unifiée | /admin/cash-overview | 14b/4i/2sel · date/source/method filters + Rechercher | filters render, 24h time | none | cash-overview.png | **exemplary** FR formatting + reconciliation warnings |
| Rapport caisses quot. | /admin/cash-sessions-report | 14b/4i · 3 tables | renders | none | cash-sessions-report.png | 24h correct |
| Caisse livreur | /admin/delivery-boy-cash-sessions | 19b · Voir + Ouvrir la caisse | Voir → detail nav | none | delivery-boy-cash.png | works |
| KDS | /admin/kitchen-display-system | 18b · Prêt/Démarrer/Historique | renders | not deep-probed (own audit owner) | kds.png | clean load |
| OSS | /admin/order-status-screen | 12b (nav only) | full-screen status wall | none | oss.png | correct empty-state |
| Notification pushs | /admin/push-notifications | 21b · Ajouter/Filtrer/Export | Ajouter modal | none | push-notifications.png | works |
| Messages | /admin/messages | 14b · chat composer | renders 3 convos | none | messages.png | customer-chat UI |
| Abonnés | /admin/subscribers | 21b · Envoyer l'email/Filtrer/Export | Envoyer drawer | none | subscribers.png, verify-subscribers-email.png | works |
| Administrateurs | /admin/administrators | 23b · Ajouter/Modifier | Ajouter drawer | none | administrators.png | works |
| Employés | /admin/employees | 49b · Ajouter/Modifier/Supprimer/pagination | all CRUD opens, pagination | none | employees.png, final-modifier/supprimer-*.png | EN Previous/Next (P3) |
| Chefs | /admin/chefs | 24b · Ajouter/Modifier/Supprimer | Ajouter drawer | none | chefs.png | works |
| Transactions | /admin/transactions | 26b · Filtrer/Export/pagination | filter, XLS, pagination | none | transactions.png | AM/PM (P1) |
| Rapport des ventes | /admin/sales-report | 26b · Filtrer/Export(XLS+PDF)/pagination/presets | filter, XLS, PDF, pagination | none | sales-report.png, filter-sales-report.png | AM/PM (P1) |
| Rapport articles | /admin/items-report | 26b · Filtrer/Export | XLS + PDF | none | items-report.png | clean |
| Paramètres | /admin/settings → /company | 14b/37l/10i · sub-nav + Enregistrer | Enregistrer saves (PUT 200) | none | settings.png | **English** success toast (P2-EN) |

> **Settings note:** `/admin/settings` redirects to `/admin/settings/company`. The visible settings sub-nav (Entreprise / Site / Filiales / Bornes / Configuration commandes / Devises) renders. Most `settings.*` modules (mail, theme, permission, tax, sms-gateway, payment-gateway, …) are **intentionally hidden in V1** per `resources/js/config/v1-hidden-modules.js` — **do NOT report those as missing** (deliberate scope). Company "Enregistrer" = **WORKS** (`PUT 200 /api/admin/setting/company` + toast) — my earlier "no-XHR" was a probe watching only POST; the save is a `PUT`. **But the success toast is in English** (`"Entreprise Updated Successfully."`) on a FR admin — see P2-EN.

---

## PRIORITIZED NON-FROZEN UI/UX FIX LIST

### P2 — confusing / FR-incorrect on a daily surface (not dead, but felt-broken)

**[P2-EN] Half-English CRUD success toasts (GLOBAL — highest leverage)** — `resources/js/services/alertService.js:48-58`
- Broken: `successFlip()` builds the toast as `translatedLabel + " Updated Successfully."` / `" Created Successfully."` / `" Deleted Successfully."` — the suffix is **hardcoded English**. Every create/update/delete in the admin shows e.g. `"Entreprise Updated Successfully."`, `"Employé Created Successfully."` on a FR UI.
- Repro: `/admin/settings/company` → change a field → Enregistrer → toast reads **"Entreprise Updated Successfully."**. Verified `PUT 200` + toast text captured (`_settings` run). Same path on employees/chefs/items/attributes/etc.
- Scope: **called by 102 admin components** (`grep -rl successFlip resources/js/components/admin`). One file fixes them all.
- Fix (scope-minimal): replace the 3 hardcoded suffixes with i18n lookups, e.g. `import i18n` and `message + ' ' + i18n.global.t('message.updated_successfully')`, adding FR keys (`Mis à jour avec succès.` / `Créé avec succès.` / `Supprimé avec succès.`) to `resources/js/languages/fr.json`. _Not frozen._

**[P2] AM/PM time in all order date columns** — `app/Libraries/AppLibrary.php:32,40`
- Broken: `time()`/`datetime()` default `TIME_FORMAT` to `'h:i A'` (12h en-US). FR = 24h.
- Repro: open `/admin/sales-report` or `/admin/historique` → DATE column shows `08:52 AM`, `08:46 PM`. Screenshot `sales-report.png`.
- Fix (scope-minimal): set `TIME_FORMAT=H:i` in `.env`/`.env.e2e` (owner data-op, zero code) **or** change the two `env(... , 'h:i A')` defaults to `'H:i'`. Verify exports/receipts switch to 24h too (intended). _Not frozen._

**[P2] Dashboard "Dernier Z" en-US datetime** — `resources/js/components/admin/dashboard/LastZReportWidget.vue:58`
- Broken: `d.toLocaleString()` with no locale → `6/8/2026, 12:58:19 AM`.
- Repro: `/admin/dashboard` → scroll to "Dernier rapport Z" card. Screenshot `dashboard-sla-region.png` (the `…12:58:19 AM` label).
- Fix: `return d.toLocaleString('fr-FR');` (one line).

**[P2] English "Previous / Next" pagination** — `PaginationBox.vue` + `PaginationSMBox.vue`
- Broken: `laravel-vue-pagination`'s `TailwindPagination` renders default English labels; no i18n slot used.
- Repro: any list page (`/admin/employees`, `/admin/sales-report`, …) → pagination bar shows `Previous` / `Next`. JSON `_final-english-settings.json`.
- Fix (scope-minimal): pass translated slot/labels to `TailwindPagination`, or wrap with FR aria-labels (`Précédent`/`Suivant`). Both pagination components, ~2-4 lines each.

### P3 — polish

**[P3] SLA Alerts raw minutes** — `SlaAlertsComponent.vue:38`
- `En attente depuis 15583 minutes` is unreadable. Format to `j h m` (e.g. `~11 j`). Repro: `dashboard-sla-region.png`. (Data is stale clone fixtures, but the formatter is the issue regardless of data.)

**[P3] Export dropdown keyboard/aria** — `ExportComponent.vue:2` + `resources/css/app.css:404-407`
- Reveal depends on a JS-applied `.active`; no `aria-expanded`/`aria-haspopup` on the trigger, no CSS `:focus-within` fallback. Works on mouse-click; weak for keyboard/SR users. Add `aria-expanded` binding + ensure focus management. (Non-blocking; export itself works.)

**[P3→resolved] "Silent" create** — `ItemAttributeCreateComponent.vue:165`
- Earlier appeared toast-less; in fact a success toast **does** fire via `successFlip` (modal also closes). The real issue is that the toast is **half-English** — folded into P2-EN above. No separate fix needed.

**[P3] Native date input MM/DD** — cash pages
- `<input type="date">` shows `06/08/2026` (browser-locale). Minor & browser-dependent; for guaranteed FR DD/MM, a custom FR date component would be needed. Low priority.

### Inconclusive — re-check, do not assert
**[?] Settings company "Enregistrer"** — `/admin/settings/company` save fired no POST in my capture (likely a multipart request my filter missed). Targeted re-probe needed before classifying. Not counted as a defect.

---

## FELT-NUMBER CONSISTENCY (checked, no defect)
- Dashboard overview "Total commandes" = **2204**; sales-report "Total Commandes" = **2206**; DB raw `orders` = **2219**. The ~13-15 spread is explained by **status filtering**: the DB holds orders across 10 statuses incl. canceled (status 13 ×4), refunded/failed (22 ×4, 19 ×1, 2 ×1, 5 ×1) that completed-order counts legitimately exclude. The 2204-vs-2206 difference between two widgets is a status-set / date-window nuance, not corruption. Acceptable.
- Tracker source split **31 (Caisse) + 55 (Borne) + 0 (En ligne) = 86 (Toutes)** — internally consistent.
- Cash-overview totals add up: Grand Total `86,50 €` = Caisse `0` + Borne `86,50 €` + Livreur `0`, with a correctly-surfaced reconciliation warning ("28 encaissements espèces sans session caisse → 64,50 € non rattaché").
- Currency renders FR-correct everywhere checked (`32 508,40 €`, `9,00 €`) — no `NaN €` / `0undefined`.

## METHODOLOGY NOTE — why "felt broken" ≠ "is broken"
Seven controls initially classified DEAD/NO-OP by the generic probe were **all** harness artifacts, each caught by control-appropriate re-verification (drawer `.active`, native `fill()`, exact `type=submit`, DOM-aware card counts, token-hydration timing, precise CSS-class selectors, PUT-vs-POST). The audit's strongest, owner-relevant conclusion: **the admin's daily controls work** — zero dead controls — but pervasive en-US date/time, half-English save toasts, English pagination, and raw-number displays make the product *feel* unfinished. Fixing the P2 set (one frontend `alertService` file = 102 surfaces, one backend env/default for time, one `LastZReportWidget` line, two pagination components) moves felt quality the most for the least risk — **none of it touches a frozen zone**.
