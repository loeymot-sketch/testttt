# Web Standalone Abuse Audit — Round 1 Findings (2026-05-30)

**Target:** STANDALONE WEB site (V1 Le Cayenne) — React+Babel-standalone SPA at `/Users/1millnonstop/Downloads/web/`, served via `php -S 127.0.0.1:8095`.
**Mode:** CAPTURE + AUDIT only. No web app source modified.
**Spec:** `tests/e2e/test-real-e2e-pagebypage-abuse-web-2026-05-30.spec.js`
**Screenshots:** `reports/test-e2e/frontends-abuse-2026-05-30/screenshots/web/` (156 PNGs)
**Viewports:** mobile 390×844 · tablet 768×1024 · desktop 1280×800

---

## VERDICT: GREEN — 0 P0 · 0 P1 · 0 P2 · 0 P3

No genuine defects found across all captured states × 3 viewports. The web standalone
renders cleanly: real product imagery, consistent prices, correct wizard composition + totals,
honest empty state, responsive layout that reflows correctly (desktop is a true 2-column
layout, NOT a stretched phone), and a clean intentional checkout/payment stop (un-wired by
design — correct, not a crash).

### Severity tally
| Severity | Count |
|----------|-------|
| P0 (crash / blank / wrong-total) | **0** |
| P1 (raw label / console error / 404 image / overflow / broken empty state) | **0** |
| P2 (truncation / contrast) | **0** |
| P3 (cosmetic) | **0** |

---

## Automated gate results (per spec, all 3 viewports)

| Gate | Result |
|------|--------|
| Raw-label innerText sweep (`Label.`, `kiosk.x.y`, `lecayenne.x.y`, `0undefined`, `undefined €`, `NaN`) | **CLEAN** — 0 leaks |
| Console / page errors (favicon + image-slots filtered) | **CLEAN** — 0 errors |
| Image 404 network sniffer (`resourceType==='image' && status>=400`) | **CLEAN** — 0 image errors |
| Product image HEAD check (all 41 menu items) | **41/41 → HTTP 200** · 0 broken · 0 emoji-only fallback |
| Horizontal overflow (`scrollWidth > innerWidth`) | **CLEAN** — 0 overflow at any viewport |
| Wizard recap reached + total renders sane — **all 5 templates × all 3 viewports** | **VALID** — see table below; every recap total renders as a sane € (no NaN/undefined) and is identical per template across viewports |

### Wizard recap total integrity — all 5 templates × 3 viewports (15 recaps)

Recap detection is **viewport-robust**: it keys on the footer-next button text `Ajouter au panier`
and reads the total from `.lc-wiz-foot-next-total` (present in EVERY layout), NOT the desktop-only
right-side `.lc-wiz-preview` panel (which is hidden at 390px). On mobile/tablet the recap renders as
a dedicated full-screen RÉCAP step (composition lines + per-step MODIFIER links + `Ajouter au panier`
footer); on desktop it is the live-preview pane. Both are correct and show the same total.

| Wizard | mobile | tablet | desktop |
|--------|--------|--------|---------|
| bols (3-step: sauce/supplements/drink/recap) | 8,90 € | 8,90 € | 8,90 € |
| frites (1-step: frites_style/recap) | 2,50 € | 2,50 € | 2,50 € |
| galette (full: viandes/sauce/crudites/supplements/menu/recap) | 9,00 € | 9,00 € | 9,00 € |
| sandwich (sauce_locked: viandes/crudites/supplements/menu/recap) | 10,00 € | 10,00 € | 10,00 € |
| tacos (viandes/supplements/menu/recap) | 9,40 € | 9,40 € | 9,40 € |

Totals are identical per template across all 3 viewports → recap pricing rendering is consistent and
not viewport-dependent. Evidence: `web-<vp>-wizard-<tpl>-recap.png` (15 PNGs).

**Scope of this check (precise):** the spec drives each wizard to recap at its **base/minimal config**
(it forces a selection only when Next is *disabled* by a required step; optional paid steps —
supplements, drinks — are left at default/none). So it verifies the recap total **field renders a sane,
cross-viewport-consistent value** at base config — NOT literally "displayed total == sum of selected
add-ons" with paid options. The **add-on pricing math** (multi-sauce +0,50 €, bol supplements, menu
addon, `computeWizardTotal` mirrors `priceFor`) is already validated by the sibling realignment spec
`test-e2e-website-realignment-2026-05-16.spec.js` tests **E / H / L**. Residual risk (recap UI rendering
a non-base total) is low and unobserved-bad — does not change the GREEN verdict.

---

## States captured (× 3 viewports = 156 PNGs, incl. recap for all 5 templates on every viewport)

`home` · `menu-all` · `menu-mid` · `menu-bottom` · `menu-images` (audit) ·
`detail-sandwich/galette/tacos/bols/frites/coca` ·
wizard **sandwich** (sauce_locked: viandes→crudites→supplements→menu→recap, 7 step shots) ·
wizard **galette** (full: viandes→sauce→crudites→supplements→menu→recap) ·
wizard **tacos** (no sauce/crudites: viandes→supplements→menu→recap) ·
wizard **bols 3-step** (sauce→bol_supplements→bol_drink→recap) ·
wizard **frites 1-step** (frites_style→recap) ·
`direct-coca` (simple direct-add) ·
ABUSE: `abuse-wizard-mid` · `abuse-wizard-back` (back to step 0 closes wizard) · `abuse-double-tap` ·
`cart-full` · `cart-empty` (all rows removed via trash → honest empty state) ·
`checkout` · `payment-stop` (intentional un-wired stop).

---

## Vision analysis (multimodal Read of representative PNGs)

Confirms the automated gates with human-equivalent inspection. Cited by PNG filename + exact observed:

- **`web-mobile-home.png`** — Clean. Brand header (LC · LE CAYENNE), `OUVERT · HÉNIN-BEAUMONT 62210`,
  hero `SANDWICH TACOS BOLS GALETTE`, dual CTA, stat strip `30 sec / 11h-00h / 1€=1pt`. No leak, no overflow.
- **`web-mobile-menu-all.png`** — Category chip carousel (horizontal scroll by design; "Galette" chip
  half-visible at right edge = intentional scroll affordance, NOT a clipping defect), diet filters,
  `41 résultats`, product card with real illustrated image + name + desc + price. Clean.
- **`web-desktop-menu-all.png`** — Proper 2-column layout: left sidebar category list (with per-cat counts)
  + 4-column product grid. Cards: Sandwich Cayenne 7,50 € / Big Cayenne 9,50 € / Galette Normale 6,50 € /
  Galette Cayenne 7,00 €, each with real image + SIGNATURE badge + add button. Desktop is a true desktop
  layout — does NOT look like a stretched phone.
- **`web-tablet-home.png`** — Nav collapses to burger at tablet, hero scales correctly, no overflow.
- **`web-desktop-wizard-bols-recap.png`** — Bols 3-step wizard, desktop live-preview pane:
  `BOWL FRITES POULET CURRY`, Sauce=Curry, Suppléments=Aucun, Boisson=Aucune boisson, **TOTAL 8,90 €**,
  +9 pts. Footer button mirrors total. Composition + total integrity confirmed.
- **`web-mobile-wizard-bols-recap.png`** — Mobile dedicated full-screen recap step `4/4 · BOWL FRITES
  POULET CURRY · RÉCAP · Vérifie ta commande`: composition header (Viande: Poulet curry · Base: Frites),
  per-step rows (Étape 1 Sauce: Curry / Étape 2 Suppléments: Aucun / Étape 3 Boisson: Aucune boisson)
  each with a `MODIFIER` edit link, footer `Ajouter au panier · 8,90 €`. Renders cleanly at 390px (no
  overflow, no overlap). Confirms the mobile recap works and total matches desktop (8,90 €).
- **`web-mobile-wizard-sandwich-step1.png` / `-step7.png`** — Mobile wizard progresses through distinct
  steps: step1 `1/5 CHOISIS 1 VIANDE` (footer 7,50 €) → step7 `7/8 SAUCE POUR LES FRITES` (footer
  `Voir récap 10,00 €`). The menu addon expands the flow 5→8 steps. Steps are visually distinct →
  the wizard advances correctly on mobile touch (not stalled).
- **`web-mobile-cart-empty.png`** — Honest empty state after removing all rows via trash: cart icon,
  `Ton panier est vide`, `Faim ? Va voir le menu.` No raw label, no broken state.
- **`web-desktop-payment-stop.png`** — 3-step progress (Pickup ✓ → Paiement → Confirmé), `COMMENT TU PAIES?`,
  4 payment methods, RÉCAP panel (Sandwich Cayenne ×1, Total 7,50 €, +8 pts), `Paiement 100% sécurisé`.
  Intentional un-wired stop (no real payment) — CORRECT, not a crash.
- **`web-tablet-checkout.png`** — `QUAND RÉCUPÉRER TA COMMANDE?`, day/time pickers, promo, kitchen note
  (0/190 counter), pickup location `14 rue de la République · 62210`, Continuer button. Clean, responsive.

---

## Observations (non-defects — recorded for completeness, anti-hallucination discipline)

1. **Qty-0 abuse not reachable (by design).** `DirectAddView` qty stepper floors at `Math.max(1, q-1)`
   and `CartDrawer.updateQty` floors at `Math.max(1, it.qty+d)`. Repeated minus-taps stop at 1. This is
   correct guarding, not a missing-defect. Recorded as observation, not a finding.

2. **`coca` (and other simple items) route through ItemDetailModal's direct "Ajouter au panier"**, not the
   `DirectAddView` qty stepper. `DirectAddView` is only reached for wizard-templated-but-stepless simple
   items inside `WizardFlow`. The qty-stepper abuse therefore exercised the cart-row stepper instead.
   Behaviour is consistent and correct.

3. **`lecayenne.fr` footer email is NOT an i18n leak.** A first-pass regex `/\blecayenne\.[a-z_]+/i`
   false-positive-matched the legitimate footer contact email `contact@lecayenne.fr` (93 hits). Per
   anti-hallucination discipline (CLAUDE.md §3ter), this was identified as a regex artifact and the
   pattern was tightened to `(?<!@)\blecayenne\.[a-z_]+\.[a-z_]+` (require ≥2 dotted key segments, not
   preceded by `@`) before final reporting. After the fix: 0 raw-label findings. **No app defect.**

---

## Test execution notes

- **Port override (load-bearing):** the reused `tests/web-e2e/playwright.config.js` has `baseURL:8082`,
  and a STALE instance was confirmed squatting 8082 (`curl 8082 → 200`). The spec overrides
  `baseURL:'http://127.0.0.1:8095'` so the LIVE code under audit is tested, not the stale instance.
- **`actionTimeout:8000`** added so a disabled/intercepted click (e.g. a required-step Next, or a nav
  link behind an open modal backdrop) fails fast (caught) instead of hanging the 120s test budget.
- **Recap-detection fix (initial spec bug, not an app bug):** the first spec keyed recap detection on
  the desktop-only `.lc-wiz-preview-total-num` element, so recap + total-integrity was captured on
  desktop only (mobile/tablet silently no-op'd — no `*-recap` PNGs). Fixed with a viewport-robust
  `driveWizardToRecap()` helper that detects recap via the footer button text `Ajouter au panier` and
  reads the total from `.lc-wiz-foot-next-total` (present in all layouts). After the fix: all 5
  templates reach recap on all 3 viewports (15 recap PNGs) with consistent totals.
- **Run result:** desktop+tablet = 26/26 pass; mobile = 13/13 pass (clean full run). An intermittent
  Chromium context-close flake occasionally hits ~1 random mobile test during a long sequential mobile
  run (heavier deviceScaleFactor:2); each such test passes on solo re-run. This is an environmental
  Playwright flake, NOT an app defect — the failure is always `page.goto: Target page... has been
  closed` at the first line of `bootWeb`, before any app code runs.

---

## Cross-agent note

Image **freshness** vs kiosk (whether the web photo matches the latest kiosk asset) is tracked by a
separate agent and intentionally out of scope here — this audit confirms only that all 41 product
images **resolve (HTTP 200) and are not broken/placeholder**. Price values mirror mobile heal-light v2
and are internally consistent; not compared to DB (tracked separately).
