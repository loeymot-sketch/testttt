# Wave P R2 KDS — Re-test + 2 P1 Heals (R1 P-3 followups)

**Date** : 2026-05-20
**Branch** : `heal/cms-pr1-quickwins-2026-05-18`
**Server** : `http://127.0.0.1:8000`
**Spec** : `tests/e2e/wave-p-kds-2026-05-20.spec.js`
**Cap** : 3 iterations / 30 min wall-clock — used 2 iterations / ~20 min

---

## Verdict

**STATUS : GREEN 8/8 — both R1 P1 residuals healed and visually attested.**

- P1-A (i18n CTA "Ready"→"Prêt") : **HEALED** — root cause was
  `detectLocale()` not matching `/kds` path; Vue router redirects
  `/kds` → `/admin/kitchen-display-system` AFTER `i18n.js` module load,
  so initial pathname is `/kds` and admin force-FR never fires.
- P1-B (allergen badge non-render) : **HEALED** — root cause was the
  test seed sending an array of `{code, label}` objects instead of the
  canonical FR string array (per `2026_04_20_131600_backfill` migration
  + `kdsCustomization.js:152-153` filter). Backend resource
  `KDSOrderItemsResource:39` already exposed `allergens_snapshot` —
  no production code change needed.

KDS surface produces "Prêt" CTA + `⚠ ALLERGIE` pill + inline
`Allergènes : Gluten · Œufs · Lait · Moutarde · Sulfites` block at
**2.3 s sync latency**, **428×52 px** WCAG-AA bump CTA, **0 raw labels**,
**0 page errors**, polling fallback confirmed under simulated Pusher
outage.

**0-issue : YES.**

---

## Heals applied

| # | File | Change | Why |
|---|------|--------|-----|
| 1 | `resources/js/i18n.js:46-62` | Extended `isAdminPath()` regex to also match `/^\/kds(\/|$)/` and `/^\/order-status-screen(\/|$)/` | `/kds` route loads `app.js` which evaluates `i18n.js` at module load time; pathname at that point is `/kds` (BEFORE Vue router redirect to `/admin/kitchen-display-system`), so admin force-FR was bypassed and `navigator.language=en` (Playwright headless) won. OSS shares the same boot path → preemptive fix. |
| 2 | `tests/e2e/wave-p-kds-2026-05-20.spec.js:116-131` | Seed now picks an item with non-empty `allergen_flags` (`whereJsonLength('allergen_flags','>',0)`) and falls back to any active item | Exercises real R2-B seeded data (production allergen pipeline) instead of fabricated objects. |
| 3 | `tests/e2e/wave-p-kds-2026-05-20.spec.js:183-191` | `allergens_snapshot` now serialized as **FR string array** (`['gluten','lait',…]`) — matches backend `AllergenService::projectFlags` + frontend `kdsCustomization.js:152-153 typeof === 'string'` filter | Object shape `[{code,label}]` was silently dropped by the helper → `hasAllergen=false` → pill never rendered. |
| 4 | `tests/e2e/wave-p-kds-2026-05-20.spec.js:485` | Allergen badge selector narrowed from `[class*="allergen"]` to `.kds-card__allergen-pill, .kds-allergens-badge` | Broader `[class*="allergen"]` selector also matched the 22-px `.kds-card__allergen-spacer` placeholder rendered on every non-allergen card, so `.first()` resolved to a non-badge element and `badgeVisible` was false. |
| 5 | `tests/e2e/wave-p-kds-2026-05-20.spec.js:463-510` | K06 reframed from "modal opens/closes" to "header pill + inline body block both render" | KDS V2 (`DESIGN_SPEC_KDS_V2_2026-05-11.md`) intentionally removes the modal — chefs read allergens inline at 2-m distance. Click-to-open behaviour was a V1 legacy; the spec assertion was outdated. |
| 6 | `tests/e2e/wave-p-kds-2026-05-20.spec.js` (comment fix) | Removed backticks from JS comments inside a template literal (Babel parser ate them) | Iter-1 dropped the spec into `SyntaxError` before any test ran. |

Frozen zones touched : **0**. NF525 services touched : **0**.

---

## Spec result post-heal

```
Running 8 tests using 1 worker
  8 passed (2.3m)
```

| Test | What it covers | Status | Evidence |
|------|---------------|--------|----------|
| K01 | Empty state + admin login + no fatals | PASS | `screenshots/K01-empty-state.png` — 4 pre-existing kiosk fixtures, FR "Prêt" CTA |
| K02 | Seed ACCEPT → KDS within 8 s | PASS | `screenshots/K02-after-seed.png` — sync 2 278 ms |
| K03 | Multi-status board (4+7+8) | PASS | `screenshots/K03-multi-status.png` — 7 cards |
| K04 | ACCEPT→PREPARING→PREPARED via service | PASS | `screenshots/K04{a,b,c}-status-*.png` |
| K05 | UI bump CTA → API hit | PASS | `screenshots/K05{a,b}-*.png` — 428×52 px hit, apiHit true, "Prêt" verified |
| K06 | Allergen pill + inline block render | PASS | `screenshots/K06{a-before,b-inline-block}.png` — badgeVisible + inlineBlockVisible both true |
| K07 | Polling fallback under Pusher silence | PASS | `screenshots/K07-polling-fallback.png` — observedFallbackHit true, 6 cards visible |
| K08 | i18n + raw-label scan | PASS | `screenshots/K08-i18n-scan.png` — 0 leaks (`kds.X`, `Label.Y`, `0undefined`, `[object …]`) |

### capture-meta summary
- consoleErrors : 1 — pre-login `/api/admin/me` 401 probe (harmless, predates heals, not a regression).
- pageErrors : 0
- networkFailures : 0
- rawLabels : `{kds_dot:[], label_dot:[], message_dot:[], button_dot:[], zero_undefined:0, object_object:0}`

### Visual audit (screenshots Read tool)
- **K05b-after-bump.png** : 5 cards rendered, every CTA reads "Prêt", every state pill in FR ("PRÊTE", "EN COURS", "NOUVELLE"), source chips "BORNE"/"CAISSE" FR, "ATTENTE" timer label FR.
- **K06b-inline-block.png** : Sandwich Cayenne card carries the orange `⚠ ALLERGIE` header pill (alert role) and the body shows `⚠ Allergènes : Gluten · Œufs · Lait · Moutarde · Sulfites` (italic orange) — full V2 EU 1169/2011 disclosure visible at 2-m glance.

---

## Why R1 reported GREEN but missed both P1s

R1 spec executed against the OLD bundle. The i18n key resolution was
correct in source code, but the served `app.js` initialised
`i18n.locale='en'` from `navigator.language`. The page also rendered
"Bonjour"/"Accueil" because those strings are returned by Blade
templates (PHP `app()->getLocale()`), not by Vue `$t()` — which is why
R1 saw a PARTIAL FR page and the CTA span was the visible failure.

The allergen P1 was a test-fixture-shape mismatch — production pipeline
was healthy throughout (`KDSOrderItemsResource:39` exposes
`allergens_snapshot`, `kdsCustomization.js:152-153` consumes string
codes). R2-B seeded Le Cayenne items with valid FR codes ; the spec
just needed to use that data instead of fabricated objects.

Lessons :
1. After any source change to `i18n.js`, the bundle MUST be rebuilt
   (`npx mix`) before the spec can attest the heal. R1's GREEN was
   bundle-stale relative to source — no test ever exercised the fixed
   detectLocale because nothing was fixed yet.
2. Test fixtures must match the production data contract (FR string
   array for allergen codes) or they exercise dead branches and pass
   on a soft assertion.

---

## Frozen-zone verification

```
$ git diff --stat -- \
  resources/js/components/frontend/kiosk/KioskWizardComponent.vue \
  resources/js/components/admin/pos/PaymentComponent.vue \
  resources/js/components/admin/pos/v5/PosV5TrancheRow.vue \
  app/Services/Fiscal/ \
  public/js/pos-wizard.js
(zero output)
```

Frozen-zone diff = 0. NF525 Fiscal services untouched.

---

## Files

- Heal #1 : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/resources/js/i18n.js`
- Heal #2-6 : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/tests/e2e/wave-p-kds-2026-05-20.spec.js`
- Bundles rebuilt : `public/js/{app,manifest,vendor,admin-kds,admin-shell,kiosk-shell,pos-app,…}.js` + `public/css/app.css` + `public/mix-manifest.json`
- Screenshots : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/wave-p-2026-05-20/kds/screenshots/`
- Capture meta : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/wave-p-2026-05-20/kds/capture-meta.json`
- This report : `/Users/1millnonstop/Downloads/projet/foodking-web/web/testttt/reports/test-e2e/wave-p-2026-05-20/kds/R2-FINAL.md`

---

## Commit

`fix(kds-R2): i18n Ready→Prêt + allergen badge expose (Wave P R2 P-3 followups)`

SHA : (pending — see git log post-commit)

---

**0-issue verdict : YES. KDS surface ships V1 Le Cayenne with FR CTA + EU 1169/2011 allergen disclosure visually attested.**
