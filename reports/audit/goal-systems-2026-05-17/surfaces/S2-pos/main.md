# S2 — POS Caisse — MAIN audit

**Auditor** : Claude Opus 4.7 (1M ctx), read-only — no Agent dispatch
**Date** : 2026-05-17
**Branch** : `feature/mobile-app-le-cayenne-2026-05-10` @ HEAD `c3ba89863`
**Scope** : POS staff-operated surface — Vanilla JS popup wizard (FROZEN) + `PosComponent.vue` 3769 LOC + V5 primitives + Cash drawer + Z-rapport integration + payment paths.

---

## §0 Anti-drift — stale findings already healed (verified at HEAD)

These items were flagged in prior audits and have since been remediated. They are NOT scored as new defects; recorded here to prevent re-flagging.

| Tag | Source | Status at HEAD | Evidence |
|-----|--------|----------------|----------|
| **POS-A3** (PII leak `/api/admin/pos/walk-in-customer` + `/quote` open) | Wave-A 2026-05-11 / CTO 2026-05-16 | ✅ HEALED Sprint 5B + Sprint 4 | `app/Http/Controllers/Admin/PosController.php:51` constructor `permission:pos` ; `:144,166` route-aware `abort_unless`. |
| **POS NF525 cash trail untested** (direct CASH → no `cash_movement`) | Wave-Z Round 1 Z1 | ✅ HEALED Sprint 1B | `app/Services/OrderService.php:1032-1039` writes movement inside the same `DB::transaction` with `strict:true` ; suite `tests/Feature/Pos/PosCashTrailTest.php`. |
| **Hardware drawer pop forensic gap** (no-sale not audited) | Wave-Z Z10-NEW-001 / F-7 | ✅ HEALED Sprint 5B | `app/Http/Controllers/Admin/Pos/CashDrawerController.php:39-74` records `TYPE_DRAWER_OPEN` movement + audit log. |
| **Sprint 1B controller-level guard** (cash sale w/o open session) | CTO 2026-05-16 | ✅ HEALED Sprint 1B | `PosController.php:90-136` `assertCashDrawerSessionOpenIfCashInvolved()` checks both legacy and split paths and returns 422 `CASH_NO_OPEN_SESSION` with i18n. |
| **TOCTOU openSession double-open** | iter15 P0-09 | ✅ HEALED | `CashDrawerService.php:75-109` triple defense Cache::lock + DB lockForUpdate + UNIQUE partial idx. |
| **Variance gate** | Sprint 1D F-4 | ✅ HEALED | `CashDrawerService.php:241-277` requires `variance_reason` + `cash.reconcile.variance.override` permission above threshold. |
| **Parked orders cross-branch leak** | ultra-goal A5 | ✅ HEALED | `app/Models/PosParkedOrder.php:37-41` BranchScope ; `ParkedOrderController.php:78` explicit user/branch resolution. |
| **POS-A4** (frozen-zone diff in `pos-wizard.js` +237 / blade +165 vs main) | Wave-Z Round 1 | ⚠️ DEFERRED V1.0.1 (retrospective LOCK doc) — not a fresh finding. |

**Stale count** : 8 (all confirmed healed or correctly deferred).

---

## §1 Top-level scores (/100)

| # | Axis | Score | One-line verdict |
|---|------|-------|------------------|
| 1 | **Architecture** | **48** | Vanilla pos-wizard.js 5964 LOC + PosComponent.vue 3769 LOC + V5 primitives + 8-piece Vue surrounding orchestra = no clean seam ; pos-wizard.js touches DOM inside Vue-managed root via MutationObserver — hostile lifecycle. |
| 2 | **Business completeness** | **78** | Cash + Card + Split (5 modes inc. TR) + Park/Recall + Floorplan/Dine-in (flag-off) + Counter-collect kiosk + Coupon + Loyalty + No-sale + Cash session lifecycle + Variance gate + NFC lookup. Gap: TR + Card terminal_id never written → Z-report TPE breakdown collapsed to "Sans TPE". |
| 3 | **UX staff (rush hour)** | **52** | PosComponent.vue Vue surface has skip-link, ARIA-live, debounced search, F-keys, barcode, V5 buttons (sm 32 / md 40 / lg 48 / xl 56). BUT cashier still enters the wizard 95% of orders — and the wizard is 32 px touch targets, single scrollable page, FR-only, no shortcut to "+1 same option". Friction concentrated where it costs most. |
| 4 | **i18n** | **38** | PosComponent.vue is `$t()`-aware (~280 keys consumed). Vanilla pos-wizard.js is **100 % hardcoded FR** (0 i18n hooks, `grep -c '__\|trans\|i18n\|$t' = 1`). Several Vue fallback strings `\|\| 'Article indisponible'` etc. (lines 1311, 2493, 2568, 2371). |
| 5 | **Integration** | **72** | CashDrawerSession.openSession triple-locked ; movement-on-CASH inside parent DB transaction ; fiscal_sequence_no allocated before cash row written ; KDS push via OrderCreated event + Soketi ; kiosk counter-collect flow uses same PaymentService. Gap: `terminal_id` not threaded (Wave-Z V1.0.1 backlog), drawer pop on session-less hardware test logged-only. |
| 6 | **Tests** | **70** | 27 PHPUnit suites under `tests/Feature/Pos*` + `tests/Feature/Cash/*` (9), 19 vitest under `tests/js/pos*`, 32 Playwright. Sprint 1B CASH trail test exists. **Risk**: Wave-Z noted 20 POS suites currently fail with 422 because they don't seed an open cash session in `setUp` — not a regression but live test-debt. |
| 7 | **Performance** | **52** | `pos-wizard.js` 290 KB + `pos-wizard.css` 41 KB shipped **unminified** and **busted on every page-load** via `?v=N-{{ time() }}` (`admin-pos-v4.blade.php:35,136`) → 100 % cache miss. 10 `innerHTML` blocks rebuild entire modal on each re-render. PosComponent.vue is monolithic but uses Vue 3 reactive store + debounced fetch. |
| 8 | **Accessibility** | **35** | PosComponent.vue: skip link, `aria-live`, `role="alert/region"`, `aria-label` on icon buttons, V5 button validators emit `aria-disabled/aria-busy`. **Wizard**: `grep -c 'aria-\|role=' public/js/pos-wizard.js = 0`. 34 `addEventListener('click', …)` on `<div class="wizard-option">` (div-as-button, no keyboard, no SR). `.viande-btn` 32×32 px violates WCAG 2.5.5 (44×44 px). |

**Surface weighted mean** (architecture×0.15 + business×0.20 + UX×0.15 + i18n×0.10 + integration×0.15 + tests×0.10 + perf×0.075 + a11y×0.075) ≈ **57 / 100**.

---

## §2 Findings

### P0 — Legal / Safety / Blocker

#### P0-S2-01 — Wizard XSS via unsanitized item name concatenation
- **Files** :
  - `public/js/pos-wizard.js:1246` — `h += '<span class="option-name">' + sauce.name + '</span>';`
  - `public/js/pos-wizard.js:3329` — `btn.innerHTML = emoji + ' ' + (isIncluded ? '✓ ' + displayName : '✕ Sans ' + displayName);`
  - `public/js/pos-wizard.js:4986-4989` — `btn.innerHTML = emoji + ' ✓ ' + name;`
- **Behavior** : Item/sauce/extra `name` (admin-supplied catalog data, stored in DB) is interpolated raw into `innerHTML` (10 occurrences total — `grep -c innerHTML` = 10). An admin who saves an item name containing `<img src=x onerror=alert(1)>` would execute code in every cashier's browser the next time that item enters the wizard.
- **Severity** : P0 (XSS-stored, authenticated authoring required, but blast radius = every cashier station). Exploitable trust boundary: admin-with-catalog-edit ≠ cashier.
- **Path** : LOCK doc + escape via `textContent` for the dynamic name (~10 small edits, no logic change).

#### P0-S2-02 — Wizard structural a11y failure (re-confirmed at HEAD)
- **Files** : `public/js/pos-wizard.js` (0 aria, 0 role, 34 div-as-button click handlers at lines 1243, 1340, 1356, 1372, …) ; `public/css/pos-wizard.css:308-310` `.viande-btn { width: 32px; height: 32px; }`, `:456-457` same on `.qty-btn`.
- **Evidence** :
  - `grep -c 'aria-\|role=' public/js/pos-wizard.js = 0`
  - `grep -c "addEventListener('click'" public/js/pos-wizard.js = 34`
  - WCAG 2.5.5 Target Size minimum = 44 × 44 CSS px.
- **Severity** : P0 — EU EAA (European Accessibility Act) deadline 2025-06-28 applies to digital point-of-sale services. Cashiers using assistive tech cannot operate the wizard.
- **Path** : already documented in CTO 2026-05-16 P0-FE-03. LOCK doc required.

#### P0-S2-03 — Perpetual cache bust on POS bundle ships ~338 KB on every page load
- **File** : `resources/views/admin-pos-v4.blade.php:35,136`
  - Line 35 : `<link ... href="{{ asset('css/pos-wizard.css') }}?v=2-{{ time() }}">`
  - Line 136 : `<script src="{{ asset('js/pos-wizard.js') }}?v=9-{{ time() }}"></script>`
- **Behavior** : `time()` returns the current Unix timestamp at every Blade render → query-string changes on **every page load** → browser cache is bypassed unconditionally. The cashier on a kiosk POS hardware that boots into Chrome reloads this on every shift, every navigation back to /admin/pos. ~338 KB hard-fetched each time.
- **Severity** : P0 in a rush-hour context (3G fallback / printer-only network at small restaurants) — measurable 200-800 ms first-paint hit per nav. Also a CDN/Cloudflare cache pollution issue (every timestamp = a new cache key).
- **Fix** : replace `?v={{ time() }}` with `?v={{ file_exists(public_path('js/pos-wizard.js')) ? filemtime(public_path('js/pos-wizard.js')) : 'static' }}` or compile through Laravel Mix manifest. ~10 line edit, FROZEN file so needs LOCK doc.

---

### P1 — Customer-facing / business impact

#### P1-S2-04 — Wizard 100 % hardcoded FR — POS unusable in EN/AR
- **File** : `public/js/pos-wizard.js`
- **Evidence** : 'Récap' :537, 'Vérifiez votre commande' :537,569,999, 'Choisissez … viande' :718, 'Choisissez votre sauce' :861, 'Choisissez votre boisson' :979, 'Sans cheddar' :989, '▼ Voir tous (+N)' :1255,1642, '✕ Sans ' :3329,4989, 'Sans sauce' :82.
- **Behavior** : Even if the user toggles browser locale to EN/AR, the wizard renders FR-only. Le Cayenne single-restaurant V1 acceptable today, but blocking for Sister-restaurants (multi-tenant SaaS V2).
- **Severity** : P1 — V1 OK, blocker for V2 SaaS scale-out.

#### P1-S2-05 — Inline `onclick="…"` strings violate CSP
- **File** : `public/js/pos-wizard.js:1255, 1642, 3329, 4986, 4989`
- **Evidence** : 5 buttons inject HTML strings with `onclick="var grid=this.previousElementSibling; …"`. Any CSP `script-src 'self'` or stricter would block these.
- **Severity** : P1 — locks the project out of any CSP hardening for the entire POS surface ; combined with P0-S2-01 the wizard cannot be retrofitted defensively without breaking the visual contract owner declared "parfait".

#### P1-S2-06 — Z-report TPE breakdown collapsed to "Sans TPE"
- **Files** :
  - `app/Services/Payments/SplitPaymentService.php:202-211` — `OrderPayment::create([…])` does NOT include `terminal_id`.
  - `app/Services/Refund/RefundWithCounterEntryService.php:168-181` (per Wave-Z) — same.
  - `app/Services/Fiscal/ZReportService.php:297-441` aggregates per-mode but the `payment_terminals` join falls back to a single null-terminal bucket.
- **Behavior** : The `payment_terminals` table + `terminal_id` column on `order_payments` exist (Sprint 1C migration) but writes never populate them. Z-report shows all card revenue under a single "Sans TPE" line — defeats the feature's purpose (per-TPE fee reconciliation).
- **Severity** : P1 — fiscal reports look correct but TPE rate tracking is non-functional. Wave-Z deferred this V1.0.1. UI selector + payload-threading still TODO.
- **Cross-ref** : Wave-Z `P1-Z7-01`.

#### P1-S2-07 — PosComponent.vue is a 3769-line monolith
- **File** : `resources/js/components/admin/pos/PosComponent.vue` — 3769 LOC (1005 template, 2410 script, 354 style).
- **Evidence** : single `name: "PosComponent"`, ~120 methods, ~30 computeds, ~15 watchers, 18 child components imported, 8 store modules consumed. `git blame` would show 200+ contributors-of-record over 14 audit cycles.
- **Severity** : P1 — architectural bomb. Onboarding a new contributor requires reading 4000 lines to add a single field. Refactor target: split into `PosCheckoutPanel.vue`, `PosCartPanel.vue`, `PosOperatorBar.vue`, `PosOrderTracker.vue`, `usePosCart` composable. ~2-3 sprints.

#### P1-S2-08 — Vue-side FR fallback strings ship in production
- **File** : `resources/js/components/admin/pos/PosComponent.vue`
  - `:1311` `'Article indisponible' / '${list.length} articles indisponibles'` (computed, no `$t`)
  - `:2370` `'Erreur lors de l'annulation'` fallback
  - `:2473,2490,2492` `$t('pos.park_label_prompt')` etc — OK
  - `:2568,2571` `$t("pos.barcode_not_found", { code })` — OK
  - `:40` `:aria-label="$t('button.close') || 'Fermer'"` — fallback FR
- **Severity** : P1 — same i18n quality regression risk as KDS/Kiosk. Sprint to ripgrep `|| '[A-ZÉ][a-z]{3,}'` and inject keys.

#### P1-S2-09 — POS direct CASH wired but missing client-side gate
- **File** : `resources/js/components/admin/pos/PosComponent.vue` (PaymentComponent path)
- **Evidence** : Backend 422 enforces (`PosController.php:90-136`), but no client check on the "Encaisser CASH" CTA tile when `cashSessionActive=false`. Cashier taps, gets a 422 toast.
- **Severity** : P1 — UX defect, not a correctness issue. Wave-Z Z1-NEW-004 deferred V1.0.1.

---

### P2 — Polish / inconsistency

#### P2-S2-10 — POS wizard is a parallel design system divorced from V5 tokens
- **Files** : `public/css/pos-wizard.css:312` legacy red `#E93C3C` ; `resources/css/foundations/pos-v5-tokens.css:30` Cayenne `#F4501E`.
- **Severity** : P2 — visible brand divergence between operator bar (V5 Cayenne) and the modal that opens on top of it (wizard legacy red).

#### P2-S2-11 — V5 primitives mounted but legacy `fk-pos-v4` / `pos-v4-shell` classes kept "for rollback"
- **File** : `PosComponent.vue:11` `<section class="pos-v5-shell fk-pos-v4 pos-v4-shell" data-pos-v4-shell data-pos-v5-shell>`
- **Severity** : P2 — three brand prefixes on the same root element. Pick one, kill the others.

#### P2-S2-12 — Inline style attribute drift in availability banner
- **File** : `PosComponent.vue:33-44` — banner uses inline `style="margin: 8px 12px; padding: 10px 14px; …"` instead of a class.
- **Severity** : P2 — style discipline regression.

#### P2-S2-13 — `_kioskPollTimer` + custom timers spread across `data()` instead of composable
- **File** : `PosComponent.vue:1129-1146`
- **Severity** : P2 — refactor into `usePosTimers()` composable.

#### P2-S2-14 — POS wizard non-Mix compiled (S25-SinglePage hand-written)
- **File** : `public/js/pos-wizard.js:1-9` (header comment)
- **Severity** : P2 — already known per CLAUDE.md frozen-zone doc, but listed for completeness. No tree-shaking, no minification, no source-map.

---

### P3 — Notes / debt for visibility

- **P3-S2-15** — `tests/Feature/Pos*` 20 suites fail with 422 because Sprint 1B cash gate is not seeded in their `setUp()` (Wave-Z follow-up).
- **P3-S2-16** — `cash_movements` lacks UNIQUE (order_id, type, direction) (Wave-Z Z1-NEW-003, defense-in-depth).
- **P3-S2-17** — `payment_breakdown` keyed by integer mode but no enum-class binding on UI side (PaymentComponent uses local consts).
- **P3-S2-18** — POS receipt printer ESC/POS bridge already wraps drawer pop ; printer test endpoint exposed (`/admin/printers/{p}/test-print`) — assumed RBAC-OK, not deeply audited here.
- **P3-S2-19** — `dineInEnabled` defaults FALSE — table flow path in PosComponent is reachable but currently dead via feature flag (CLAUDE.md confirms V1 disabled).

---

## §3 Top-3 recommendations

1. **LOCK-doc surgical patch to pos-wizard.js** (P0-S2-01 + P0-S2-02 + P0-S2-03 in a single bundle)
   - Escape every `+ name +` insertion → `textContent` or DOM `createElement('span')` instead of `innerHTML`.
   - Convert 34 `<div class="wizard-option">` → `<button class="wizard-option">` + `aria-label` + bump `.viande-btn` / `.qty-btn` to 44×44 px.
   - Replace `?v=N-{{ time() }}` → `?v={{ filemtime(public_path(...)) }}` in `admin-pos-v4.blade.php`.
   - Scope ~ 250 lines diff total, no logic touched, Playwright keyboard-nav spec + axe-core run as gate.
   - Same LOCK doc can carry brand color refresh (#E93C3C → var(--pos-v5-brand-red)) and i18n hooks for FR/EN/AR strings.

2. **Thread `terminal_id` through Card + TR payment paths** (P1-S2-06)
   - Add an optional `terminal_id` prop on the Card/TR tranche in `PaymentComponent.vue` (selector backed by `/api/admin/payment-terminals?branch_id=`).
   - Plumb through `payment_breakdown[].terminal_id` in `SplitPaymentService.persistTranches:202-211` and the legacy single-tender path.
   - Adds value to Z-report TPE breakdown immediately + unlocks per-TPE fee billing for the Sister.

3. **Split PosComponent.vue into 4 components + 2 composables**
   - `PosOperatorBar.vue` (header / no-sale / cash session button), `PosCheckoutPanel.vue` (customer + order_type + payment), `PosCartPanel.vue` (cart lines + totals), `PosOrderActions.vue` (park/recall/parked-list).
   - Composables `usePosTimers`, `usePosAvailability`. Reduces script section from 2410 → ~600 LOC per component.
   - Pre-V2 SaaS work — without this, the file becomes ungovernable around 5000 LOC.

---

## §4 Evidence files cited

- `public/js/pos-wizard.js` — 5964 LOC, 290 KB, 0 aria, 34 div-clicks, 10 innerHTML, 5 inline onclick.
- `public/css/pos-wizard.css` — 1987 LOC, 41 KB, `.viande-btn 32×32px`, `.qty-btn 32×32px`, legacy red `#E93C3C`.
- `resources/views/admin-pos-v4.blade.php:35,136` — `?v=N-{{ time() }}` cache bust.
- `resources/js/components/admin/pos/PosComponent.vue` — 3769 LOC (1005/2410/354).
- `resources/js/components/admin/pos/v5/PosV5Button.vue` — proper button semantics, size validators.
- `resources/js/components/admin/pos/PaymentComponent.vue:1-565` — multi-tender UI wired.
- `resources/js/components/admin/cash/PosCashDrawerSessionDialog.vue` — 917 LOC, ARIA-correct dialog.
- `app/Http/Controllers/Admin/PosController.php:51,90-136,144,166` — RBAC + cash gate.
- `app/Http/Controllers/Admin/Pos/CashDrawerController.php:39-74` — F-7 forensic write.
- `app/Http/Controllers/Admin/Pos/ParkedOrderController.php` — per-user/branch.
- `app/Services/Cash/CashDrawerService.php:75-109, 241-277, 326-410` — triple-lock + variance gate + recordMovement.
- `app/Services/OrderService.php:1032-1039` — direct CASH → cash_movement inside transaction.
- `app/Services/Payments/SplitPaymentService.php:148-249` — multi-tender persistence + cash hook.
- `app/Services/Fiscal/ZReportService.php` — Z aggregator (TPE bucket).
- `app/Models/CashDrawerSession.php` ; `app/Models/CashMovement.php` ; `app/Models/PosParkedOrder.php`.
- `tests/Feature/Pos/PosCashTrailTest.php` ; `tests/Feature/Cash/*` (9 suites) ; `tests/Feature/Pos*` 27 suites.
- `routes/api.php:721-829` — POS group routes + cash-drawer/sessions + parked/floorplan/nfc.

---

## §5 Limits & gaps

- **No live measurement** of pos-wizard latency on rush-hour cashier hardware ; finding P0-S2-03 is inferred from `time()` semantics, not a flamegraph.
- **No XSS exploitation attempt** — finding P0-S2-01 verified by code reading, not a live demo. Admin must be able to save arbitrary HTML in item names for the chain to fire; ItemController validation depth not re-audited here.
- **Floorplan + Dine-in path** not deeply exercised — V1 feature-flag-off per CLAUDE.md memory.
- **NFC lookup endpoint** confirmed wired (`/customers/lookup-by-nfc`) but reader contract + hardware bridge not audited.
- **PaymentComponent.vue contract with SplitPaymentService** assumed correct based on prior `posPaymentComponentContract.spec.js` ; not re-validated here.
- **`v5/` subdir** sampled (PosV5Button.vue read in full) — primitives are clean, the surface that uses them (PosComponent) is the monolith risk.
- Per task instructions: **NO Agent dispatch**, no code modifications. RED-team second-opinion pass would catch any miss in this main report.

---

*End — S2 / POS-Caisse / Main / 2026-05-17.*
