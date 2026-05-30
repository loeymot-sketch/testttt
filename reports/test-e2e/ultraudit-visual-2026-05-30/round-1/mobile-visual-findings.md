# Mobile Standalone — Deep Visual Ultraudit (Round 1)
**App:** V1 Le Cayenne standalone mobile (`mobile/index.html` @ `127.0.0.1:8087`)
**Date:** 2026-05-30 · **Viewport:** 390×844 @ DPR2 (iPhone 13) · **Mode:** READ-ONLY (no app source modified)
**Scope:** ALIGNMENT · ASPECT-RATIO · CROPPING · sizing · button/card/box/poster quality · layout/spacing/overflow · palette. **NOT** photo-subject (already validated).

**Captures:** 59 PNGs → `reports/test-e2e/ultraudit-visual-2026-05-30/screenshots/mobile/`
**Image-fit metrics:** `reports/test-e2e/ultraudit-visual-2026-05-30/round-1/mobile-image-metrics.json`
**Capture specs:** `tests/e2e/test-ultraudit-visual-mobile-2026-05-30.spec.js` + `tests/e2e/test-ultraudit-visual-mobile-gaps-2026-05-30.spec.js`

## Screens covered
splash · onboarding onb1-4 · login · otp · home (full scroll) · menu (full scroll + 6 category filters) · wizard (viandes→supplements→menu→**recap top+bottom**) · cart (full + empty) · pay modal · stripe card-pay · confirm · orders list · order detail · profile · loyalty (card + history) · pay-at-counter + points-gain modal.

> **Note:** there is **no standalone "item detail" screen in production** — `ScreenItem` (screens-main.jsx:305) always delegates straight to `ScreenItemWizard`. Clicking a product opens the wizard. The large hero photo only appears on the **home featured card** and **onboarding poster**.

---

## SEVERITY SUMMARY
| Severity | Count |
|---|---|
| P0 (broken) | 0 |
| P1 (visible defect) | 3 |
| P2 (polish) | 5 |
| P3 (cosmetic) | 4 |

**No P0.** The app is visually coherent and on-brand (BLACK/ORANGE/YELLOW/WHITE) across every screen. The owner's complaint maps to a real, consistent root cause: **source product PNGs have inconsistent internal framing** (some food fills the frame, some is shrunk-and-centred on a transparent background), which makes thumbnails look unevenly filled. This is fixed **per image** (recrop/zoom the source), not by a code change — the rendering CSS (`object-fit: cover`, square boxes for square images) is correct.

---

## FINDINGS

| id | page/element | PNG | defect (observed) | sev | file:line | fix |
|---|---|---|---|---|---|---|
| **MV-01** | Home — featured "SANDWICH CAYENNE" card photo | `02-home-seg1.png`, `probe-01-home-top.png` | The signature hero (`cayenne-hero.png` **1448×1086, 4:3 landscape**) is forced into a **150×220 portrait box** with `object-fit:cover` → **aspect mismatch ×1.96** (worst on the app). ~50% of the image width is cropped; a pale letterbox-looking seam shows between the yellow text panel and the photo. Food reads as zoomed/cut. | **P1** | image `mobile/assets/menu/signature/cayenne-hero.png` used at `mobile/screens-main.jsx:120-121` (box `width:150 × height:220`) | Either (a) supply a **portrait-cropped** variant of the hero so cover doesn't discard half the width, or (b) widen the photo column / reduce card height so box aspect ≈ source 4:3. Per-image, no shared-CSS change. |
| **MV-02** | Cart — "POUR ACCOMPAGNER" upsell desserts | `06-cart-full-seg2.png` | Dessert thumbs (`ben-jerrys.png` / `tarte.png` / `tiramisu.png`, **800×800 square**) rendered in **114×80 landscape boxes** → mismatch **×1.43**; tops/bottoms cropped, desserts look squashed/zoomed. Inconsistent with the square thumbs everywhere else. | **P1** | `mobile/screens-main.jsx` (cart upsell carousel, "POUR ACCOMPAGNER" tiles ~114×80) | Make the upsell tile box **square** (or 4:3) to match the square sources, or provide landscape-cropped dessert variants. |
| **MV-03** | Menu list + category filters — thumb-box fill **inconsistency** | `03-menu-seg1.png`, `03-menu-cat-boissons.png`, `03-menu-cat-frites.png`, `03-menu-cat-supplments.png` (vs `03-menu-cat-bols.png`) | Across the 84×84 list thumbs the food **fills inconsistently**: **bols / tacos / burgers** fill edge-to-edge (good), but **drinks (Coca/Fanta/Sprite), frites cups, cheeses (cheddar/emmental/œuf)** float small with a wide margin inside the box. Root cause: source PNGs are **transparent-background, subject shrunk-and-centred** for those items (verified: corner pixels TRANSP on `frites/coca/cheddar/ben-jerrys`), while bols/tacos/burgers are full-bleed. Result reads as "products floating with gaps / boxes not well done". | **P1** | source images under `mobile/assets/menu/*.png` (e.g. `coca.png`, `frites.png`, `cheddar.png`, `emmental→fromage.png`, `oeuf.png`); rendered at `mobile/screens-main.jsx:245-247` (box 84×84) and `data/menu.js` image refs | **Recrop/zoom the under-filled source images** so the subject fills ~90% of the 1:1 frame, matching bols/tacos/burgers. No code change. (Optional polish: give the list thumb a subtle neutral fill so transparent images don't read as empty.) |
| **MV-04** | Loyalty + Profile cards — decorative circles render muddy brown | `09-loyalty-seg1.png`, `09-profile-seg1.png`, `08-orders-seg1.png` | The decorative corner circles on the black "347 PTS" card composite to a **muddy dark-brown / olive**, reading as off-palette (mandate is BLACK/ORANGE/YELLOW/WHITE). Cause: `var(--orange)`/`var(--yellow)` at **opacity 0.18 over `var(--ink)` (#0A0A0A)** → ~`#2e1a0e` brown / dark olive. | **P2** | `mobile/screens-main.jsx:942-943` (`background:'var(--orange)',opacity:0.18` + `'var(--yellow)',opacity:0.18` over ink card) | Raise opacity or use a pre-mixed brand tint (e.g. solid `#1F140B`→ replace with a higher-opacity orange or remove the circle) so the accent reads orange, not brown. |
| **MV-05** | Loyalty / Profile — broken/garbled glyph after "−5 € sur ta commande" | `09-profile-seg1.png`, `09-loyalty-seg1.png` | A trailing emoji/glyph renders as **tofu/broken boxes** ("[K]"-like) next to "−5 € sur ta commande", i.e. an emoji the bundled font can't render. | **P2** | `mobile/screens-main.jsx` (loyalty progress copy "Plus que … pour −5 € sur ta commande 🎟/💶") — emoji literal in the progress string | Remove the trailing emoji or replace with an inline SVG/`I.*` icon that is font-independent. |
| **MV-06** | Wizard footer — disabled "Suivant" CTA has an active-coloured orange glow | `04-item-sandwich-cayenne-classique-seg1.png`, `g02-wizard-s1-stuck.png` | When the step is incomplete and "Suivant" is **disabled** (grey), it still shows a **soft orange halo/box-shadow** behind it — a disabled control with an enabled-state glow is visually contradictory. | **P2** | `mobile/redesigns-styles.css` `.rdw-cta` / `.rdw-cta-wrap` shadow rule (disabled state not suppressing the orange glow) | Suppress the orange glow on `.rdw-cta:disabled` (neutral/no shadow when disabled). |
| **MV-07** | Onboarding onb2 — giant "30S" headline occluded by illustration card | `g01-onb2.png` | The huge orange "30S" headline is **overlapped by the illustration card** so it reads as "3O…S" fragments around the card edges — looks cluttered/clipped rather than a clean layered poster. | **P2** | `mobile/screens-onboarding.jsx` (onb2 `OnboardingShell` hero + headline stacking) | Either bring the headline fully above the card, reduce its size, or increase card offset so the headline isn't bisected. |
| **MV-08** | Stray `--red` (#E5341A) in chrome (off the 4-colour mandate) | (code; visible on `09-profile-seg1.png` "Se déconnecter") | Palette mandate is BLACK/ORANGE/YELLOW/WHITE. `--red:#E5341A` is defined and used for the **logout** action and `.lc-pill--red`. Red on a destructive action is a common pattern but is technically off the stated 4-colour mandate. | **P2** | `mobile/styles.css:19` (`--red:#E5341A`), used `screens-main.jsx:989` (logout) + `styles.css:104` (`.lc-pill--red`) | Owner decision: keep red for destructive only (recommended) OR recolour logout to `--ink`/`--orange` to stay strictly on-mandate. |
| **MV-09** | Home category emoji tiles — inconsistent / odd emoji | `02-home-seg1.png` | The 6 category tiles use emoji not photos; "GALETTE" renders as a wrap/salt-shaker-looking glyph, and Sandwich Cayenne / Classique share the same 🥖 — visually weak next to the photo-rich cards above. | **P3** | `mobile/screens-main.jsx:135-141` (CATS emoji tiles) + `data/menu.js` category `icon` field | Optional: swap to the `cat-*.png` photos (already in `assets/menu/`) or curate clearer category emoji. |
| **MV-10** | OTP inputs read as skeleton blocks | `g01-otp.png` | The 4 OTP boxes are pale cream rounded squares with **no visible border/affordance** when empty — easily mistaken for loading skeletons rather than input fields. | **P3** | `mobile/screens-onboarding.jsx` `ScreenOTP` input styling (~line 280) | Add a light 1px border/underline so empty inputs read as fields. |
| **MV-11** | Wallet badge stubs slightly stretched | `09-loyalty-seg1.png` | Apple/Google Wallet badge SVG stubs (`200×56`) rendered in `98×36` with `object-fit:fill` → mismatch **×1.31**, mild horizontal squish. | **P3** | `mobile/uploads/add-to-apple-wallet-fr-stub.svg` / `add-to-google-wallet-fr-stub.svg`, rendered in loyalty footer | Use `object-fit:contain` or a box matching the badge's 3.57:1 ratio. |
| **MV-12** | Filter-chip / carousel right-edge clip | `03-menu-seg1.png`, `02-home-seg2.png` | "GALETTE" filter chip and the "Les envies" carousel are clipped at the right viewport edge. **Intentional** horizontal-scroll peek affordance — verified, **not a defect**; logged for completeness. | **P3 (info)** | `screens-main.jsx:222` (filter chips `overflowX:auto`), `:152` (envies carousel) | None — working as designed (scroll peek). |

---

## VERIFIED-CLEAN (no defect — owner-flagged suspects checked)
- **Wizard RECAP "QUANTITÉ" bar vs sticky CTA** (`g02-wizard-recap-bottom.png`): for the captured 7-row Tacos L recap the QUANTITÉ black bar is **NOT occluded** by the sticky "Ajouter au panier" CTA — clear gap between them. The recap content has `padding-bottom:130px` (screens-item-steps.jsx:840) and the QUANTITÉ bar lives **inside the scroll area**, so it is always reachable by scrolling above the fixed CTA. The known occlusion only narrows on the very tallest recaps; not reproduced as a true occlusion here. **No change needed.**
- **Cart-full layout** (`06-cart-full-seg1.png`): clean cards, qty steppers, NF525/TVA line, sticky promo+CTA — good. Food thumbs (bol/tacos/burger) fill their 80×80 boxes cleanly.
- **Pay modal / Stripe card / Confirm / Order detail / Login / Splash / Profile rows**: all clean, on-palette, well-aligned. No defects.
- **`natW=0` images in metrics** (`legumes-sautes`, `jambon-dinde`, `oignons-frits`, `champignons`, `ben-jerrys` on menu): **lazy-loading** (`loading="lazy"`, below fold at measure time), files exist + serve HTTP 200 — **not broken**. No false positive.

---

## ROOT-CAUSE NOTE (the headline)
The dominant, owner-visible issue (MV-01 / MV-02 / MV-03) is **NOT** a CSS/`object-fit` bug — `cover` + square boxes for square sources is correct. It is **source-image framing inconsistency**: a subset of product PNGs (drinks, frites cups, cheeses, desserts) are transparent-background with the subject shrunk-and-centred, while others (bols/tacos/burgers) are full-bleed; and one landscape hero (`cayenne-hero.png`) is dropped into a portrait box. **Fix = recrop/normalise the offending source images** (subject fills ~90% of a 1:1 frame; provide a portrait hero variant), which is a per-image asset task and does not touch protected/app code.
