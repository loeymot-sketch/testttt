# DEEP VISUAL ULTRAUDIT — STANDALONE WEB (V1 Le Cayenne) — Round 1

**Date:** 2026-05-30
**Scope:** Visual-quality defects only — ALIGNMENT / ASPECT-RATIO / CROPPING / sizing / button-card-box-poster quality / layout / spacing / overflow. NOT photo-subject (already validated).
**Method:** Capture harness `tests/e2e/test-ultraudit-visual-web-2026-05-30.spec.js` → 142 PNGs (mobile 390 / tablet 768 / desktop 1280) → multimodal vision Read on framing-critical PNGs, then **runtime DOM + asset verification** on every emoji finding before grading.
**Screenshots:** `reports/test-e2e/ultraudit-visual-2026-05-30/screenshots/web/`
**App source (READ-ONLY, not modified):** `/Users/1millnonstop/Downloads/web/`

## Capture coverage
- 142 PNGs across 3 viewports. Home (fullPage + native-scale element shots: hero, hero-art SVG, special poster, featured poster, every featured card thumb + cards-grid row, testimonials, gallery, hours, app-cta); menu (fullPage + sidebar + grid + 8 card thumbs); item-detail modal; wizard steps 1–8 + recap; cart full + empty; checkout; payment; account login + register; loyalty (guest); orders; about; confirm; track.

## IMPORTANT — first-pass correction (verify-before-report)
My **first vision pass over-reported an "emoji-instead-of-photo" cluster as P1** (detail board / featured poster / cart / récap "show a chili emoji instead of the product photo"). **Runtime verification REFUTED that as a hardcoded-code defect:**
- The cart row (`flows.jsx:48-50`), funnel récap (`funnel.jsx:80-81`), item-detail board (`screens-v3.jsx:204-207`), and featured poster (`screens.jsx:229-233`) **all render `<img src={item.image}>` with the emoji only as an `onError`/not-yet-loaded fallback.**
- Runtime DOM inspection: cart thumb + detail board both had `<img src="assets/menu/sandwich-cayenne.png">`, `complete=true`, `naturalWidth=800`, emoji span `display:none`.
- `assets/menu/sandwich-cayenne.png` is a **real sandwich photo** (verified by Read).
- A re-capture that **waits for the modal images to finish loading** (`web-desktop-item-detail-VERIFY-loaded.png`) shows the **real Sandwich Cayenne photo**, not the chili.
- **Zero image 404s** recorded (`IMG_FAILS: []`).

**Conclusion (2nd correction — mechanism nails it): the emoji was a TEST-ENVIRONMENT ARTIFACT, NOT a product defect.** The fallback code is `<span display:none>` unless the `<img>` `onError` fires — so a slow-but-successful image shows a **blank box then the photo, never the emoji**. The emoji appearing means `onError` *actually fired* = a real load **failure**, not slowness. Root cause: the capture sweep ran against a **single-process `php -S` dev server** (no workers); a page firing ~13 thumbnails at once overran it and the server **dropped connections** (connection-level, so no HTTP status → my `IMG_FAILS` status≥400 check stayed empty). **Hard-confirmed:** relaunched the site on a **threaded server** (`PHP_CLI_SERVER_WORKERS=8 php -S 127.0.0.1:8096`) and re-shot the detail board with NO wait → `imgDisplay:block, naturalWidth:800, emoji span display:none`, real photo renders (`web-desktop-item-detail-THREADED.png`). Same code, same asset — only the server concurrency changed. **So WV-IMG-RACE is a methodology caveat (P3-info), not a product bug.** The brain's "object-fit top-crop" claim was likewise verified false (no `object-position`, PNG cutouts). Lesson: never grade image *content* from a sweep run against a single-threaded static server — use a threaded server (`PHP_CLI_SERVER_WORKERS≥4`) so concurrent thumbnails aren't dropped.

---

## FINDINGS (post-verification)

| id | page / element | PNG + viewport | defect | severity | file:line | fix |
|----|----------------|----------------|--------|----------|-----------|-----|
| WV-IMG-RACE | (NOT A PRODUCT DEFECT) emoji in captures = single-threaded dev-server connection drop | `web-*-item-detail-view.png`, `web-*-cart-full.png`, `web-*-checkout/payment-full.png`, `web-*-home-featured.png` vs `web-desktop-item-detail-THREADED.png` / `-VERIFY-loaded.png` | The chili emoji visible on the product surfaces in the sweep captures is a **test-environment artifact**, not the shipped behavior. Code shows the emoji **only on `<img> onError`**; the single-process `php -S` server dropped concurrent thumbnail connections during the sweep. On a threaded server the real photos render with no wait (proven). **No fix needed in the app for this.** | **P3-info (methodology)** | n/a (app code is correct: `screens-v3.jsx:204-207`, `flows.jsx:49-50`, `funnel.jsx:80-81`, `screens.jsx:229-233` all use `<img src={image}>` with emoji as 404 fallback) | Re-run captures behind a threaded server (`PHP_CLI_SERVER_WORKERS≥4`). OPTIONAL product polish (not a bug): swap the emoji `onError` fallback for a neutral grey placeholder so a genuine 404 looks less off-brand. |
| WV-03 | Hero — floating "+25 Points" badge clipped | `web-desktop-home-hero-art.png`, `web-desktop-home-hero-view.png` | The bottom-right floating badge **"+25 Points à l'inscription" is CLIPPED at the right edge** — "à l'inscription" runs off the box. `right:-16px` against `.lc-hero-art{overflow:hidden}`. Top-left badge also overlaps the SVG. Confirmed at desktop (badges are hidden on mobile via `styles-mobile.css:287`). | **P1** | `styles.css:333` `.lc-hero-art-floating--br{right:-16px}` + `styles.css:311` `.lc-hero-art{overflow:hidden}` | Move badge inside the box (`right:14px`), OR allow `overflow:visible` on the art wrapper, OR shorten the label. |
| WV-05 | Menu grid — sold-out card collapses; thumb-scale variance | `web-tablet-menu-grid.png`, `web-desktop-menu-grid.png` | `.lc-menu-grid` is CSS grid (`styles.css:397`) → default `align-items:stretch` already equalizes row heights and `.lc-card-item-desc{flex:1}` already bottom-pins the footer (so the row-height claim is NOT the issue). Real defect: the **sold-out card renders shorter at ~30% opacity** and sits isolated beside full-height row-mates (tablet 2-col shows a large white gap), and **food-cutout scale varies card-to-card** (WV-09) which the equal-height stretch makes more obvious. | **P2** | `styles.css:397-404` `.lc-menu-grid`; sold-out render in `WebMenu` `screens.jsx:412+` | Keep the sold-out card the same min-height as siblings (or a sold-out overlay filling the card); normalize thumb scale per WV-09. |
| WV-08 | Daily-special poster — heading vs circle; flame clip | `web-tablet-home-special.png`, `web-desktop-home-special.png`, `web-mobile-home-special.png` | Bold display heading "SANDWICH CAYENNE + MENU À 9,00 €" **collides with the decorative circle pseudo-element** (top-right) and wraps awkwardly ("À 9,00 €" orphaned). The **🔥 flame art (hardcoded emoji, `.lc-special-art`) is half-clipped at the right edge** on tablet/desktop. | **P2** | markup `screens.jsx:170-185`; CSS `.lc-special` / `.lc-special-art` `styles-v5.css:151+` | Constrain heading width / reduce font on the special card; pad `.lc-special-art` so the flame isn't clipped; lower the decorative circle. |
| WV-09 | Product food-cutout framing inconsistency | `web-desktop-home-cards-grid.png`, `web-desktop-home-card1..4.png`, `web-desktop-menu-card1..8.png` (all viewports) | Food PNG cutouts sit on the gradient at **inconsistent scale + vertical placement** — "Galette Normale" cutout is pushed up leaving a big empty gradient band below; "Big Cayenne" is smaller/higher than the wider "Sandwich Cayenne". Card boxes are equal-height but the imagery isn't normalized → visually uneven product row. (This is the accurate version of the brain's "cropping" concern — it's framing inconsistency, NOT an object-fit top-crop: there is **zero `object-position`** in CSS and the PNGs are transparent cutouts.) | **P2** | inline `screens.jsx:40-41` (`objectFit:'cover'`, no normalization); source PNGs `data/menu.js` (`imgFor`) | Standardize source PNG canvas/padding, OR wrap in a fixed-padding box with `object-fit:contain` + consistent vertical baseline so every food sits at the same scale. |
| WV-10 | Gallery "@lecayenne_" tiles = generic emojis | `web-desktop-home-gallery.png` | The Instagram-gallery section ("Photos du jour, plats du moment") shows **12 hardcoded generic emojis** (🍔🍟🌮🥣🌶🍗🍱🥑🥤🌯🧀🔥) on gradient tiles — NOT photos, and not even all Le Cayenne products (🥑 avocado, 🍱 bento, 🧀 cheese). Genuinely hardcoded (`screens.jsx:287`), not a load-race. Off-brand placeholder for a section that promises photos. | **P2** | markup `screens.jsx:286-290` (`.lc-gallery-tile`); CSS `styles-v2.css:116-130` | Replace tiles with real product photos (reuse menu asset PNGs) OR relabel the section to not promise "photos". |
| WV-04 | Home hero — "SIGNATURE BOX" art is a cartoon SVG | `web-desktop-home-hero-art.png`, `web-desktop-home-hero-view.png`, `web-tablet-home-hero-view.png` | Hero centerpiece is a **flat hand-drawn SVG cartoon burger** (geometric ellipse bun + green/orange/white/brown stripe slices). Reads as placeholder/illustration, off-brand vs the real food photos elsewhere. | **P2 (owner-decision)** | markup `screens.jsx:123-140` (`.lc-hero-art-svg`) | OWNER DECISION — replace with a real signature hero photo or keep deliberately as brand illustration. Do NOT auto-fix. |
| WV-11 | Wizard live-preview photo top crop | `web-desktop-wizard-step2.png` (tablet/mobile similar) | In the wizard right-side "Aperçu live" panel, the product preview photo is **cut off at the top** by the panel/scroll edge on later steps — a genuine container/overflow clip (a load failure would show the emoji, not a cropped photo, so this is NOT lazy-load). | **P3** | `wizard-v2.jsx` preview-panel image container | Fixed aspect box with `object-fit:contain` so the preview never clips on scroll. |
| WV-13 | Wizard step-2 — "Continuer" pill near back-arrow | `web-desktop-wizard-step2.png` | The bottom "Continuer 7,50 €" dark pill sits close to the left ← back-arrow region; on narrow widths they crowd. Not overlapping/broken at the tested viewports, but tight. (Owner explicitly named buttons — logging for completeness.) | **P3** | wizard footer layout `wizard-v2.jsx` (`.lc-wiz-foot-*`) | Add gap/min-margin between the back-arrow and the Continuer pill, or constrain the pill width. |
| WV-12 | Sparse pages — large empty whitespace bands | `web-desktop-loyalty-full.png`, `web-desktop-orders-full.png`, `web-mobile-menu-full.png` | Guest loyalty + guest orders render a small centered block in a tall viewport → large empty cream band. Mobile menu has a big empty band below the few items. Sparse but not broken. | **P3** | `loyalty-v2.jsx`, `orders.jsx` (guest states); `screens.jsx:412` `WebMenu` | Tighten vertical min-heights / reduce section padding for sparse/guest states. |

---

## Severity rollup (post-verification)
- **P0:** 0
- **P1:** 1 — WV-03 hero badge text clipped (desktop).
- **P2:** 4 — WV-05 (sold-out card + thumb scale), WV-08 (special poster collision/clip), WV-09 (cutout framing), WV-10 (gallery emojis).
- **P2 owner-decision:** 1 — WV-04 (hero cartoon SVG).
- **P3:** 3 — WV-11 (wizard preview crop), WV-12 (sparse whitespace), WV-13 (wizard footer crowding).
- **P3-info (not a product defect):** 1 — WV-IMG-RACE (single-threaded dev-server capture artifact).

## Root-cause clusters
1. **Product-image framing (WV-09):** images are correct PNGs but source-cutout scale/placement isn't normalized → uneven product rows. The one genuine "products" finding (the emoji flashes were a test artifact, see WV-IMG-RACE).
2. **Poster/hero layout (WV-03 P1, WV-08, WV-04 owner-decision):** decorative-element clipping and one genuinely off-brand cartoon SVG.
3. **Placeholder content (WV-10):** the gallery ships hardcoded emoji tiles instead of photos.
4. **Capture methodology (WV-IMG-RACE):** grade image content only against a threaded server.

## Coverage caveats (honest)
- **Cart EMPTY state captured + inspected** (`web-desktop-cart-empty.png`, `web-mobile-cart-empty.png`): clean centered cart icon + "Ton panier est vide" + "Faim ? Va voir le menu." text. **Minor P3:** the empty state is text-only — no actionable "Voir le menu" button (plain text instead of a CTA). Otherwise clean, well-centered.
- Legal HTML pages (cgv/cookies/mentions/privacy/allergens) NOT re-audited here — covered by `test-real-e2e-fullpage-web-round3-2026-05-30`.

## NOT independently measured here (do not over-claim)
- Console errors: harness attaches a listener but this spec does not assert/report per-state (round3 covers it, was green). Image-load instrumentation (`IMG_FAILS`, status≥400) returned **empty** on both single- and multi-threaded servers — no image 404s (the emoji artifact was a connection-level drop, which has no HTTP status).
- Horizontal overflow: not instrumented in this spec (round3 covers it); none visually apparent, but that's an eyeball observation, not a measurement.

## Evidence files (verification trail)
- `web-desktop-item-detail-view.png` — sweep capture (single-threaded server) showing the emoji artifact.
- `web-desktop-item-detail-VERIFY-loaded.png` — same surface, capture after `waitForFunction(img.complete)` → real photo.
- `web-desktop-item-detail-THREADED.png` — same surface on a `PHP_CLI_SERVER_WORKERS=8` server, NO wait → real photo (proves the artifact is server concurrency, not the app).

## Clean surfaces (inspected, no defect)
account login + register modal, checkout form/CTAs + footer, payment method cards + CTA + footer, testimonials row, app-cta phone mock, menu sidebar, about page (timeline + 3-obsessions + team + FAQ + footer), wizard step-1 + recap, orders (guest), confirm ("C'EST PARTI!" + QR ticket), track ("~12 MIN" 4-step progress + cards). Buttons are consistent (orange pill / ghost / ink) across all surfaces; no raw i18n labels observed.
