# ROUND-2 ADVERSARIAL VERIFY — UltraAudit Visuel (V1 Le Cayenne, 2 standalone frontends)

**Date:** 2026-05-30 · **Mode:** READ-ONLY (live capture + multimodal vision + DOM/console/network instrumentation; no app source modified)
**Fixes under test:** web commit `26d0809`, mobile commit `b6179a4a8`
**Live apps:** mobile `http://127.0.0.1:8087/index.html` · web `http://127.0.0.1:8095/`
**Evidence:** `reports/test-e2e/ultraudit-visual-2026-05-30/screenshots/round2/` (PNGs + `web-verify-metrics.json`, `web-funnel-metrics.json`, `mobile-verify-metrics.json`, `mobile-mv02-metrics.json`)
**Anti-load-race discipline:** every emoji→photo grade waited `img.complete && naturalWidth>0` before capturing AND read the actual `<img>` src/naturalWidth from the DOM. The round-1 "emoji" was a transient lazy-load fallback; these grades verify the loaded content, not the flash.

---

## VERDICT TABLE

| Fix | Surface | Result | PNG + observed |
|-----|---------|--------|----------------|
| **WV-01** | web item-detail board = real photo (sandwich + Coca + **bowl**) | ✅ **CONFIRMED** (all 3) | `web-desktop-detail-sandwich-modal.png` = full real sandwich photo (contain+7%, no crop, no emoji); `web-desktop-detail-coca-modal.png` = real Coca can; `web-desktop-detail-bowl-modal.png` = real bowl (`Bowl Frites Poulet mariné`). DOM: sandwich `src=sandwich-cayenne.png natW=800 emoji display:none`; coca `src=coca.png natW=800`; bowl `src=bol-frites.png 800×800 → 490×680 board, contain, fillFrac 0.72, letterboxPx 190` BUT bands sit on the orange gradient (transparent PNG cutout) → no visible void, reads as a centered product. The aspect-ratio outlier passes. |
| **WV-02** | web home featured "Big Cayenne" poster | ✅ **CONFIRMED** | `web-desktop-home-featured.png` = real full maxi-sandwich photo on orange gradient, no chili. DOM: slug `big-cayenne` resolves `image=assets/menu/sandwich-cayenne-maxi.png` (data present — distinguished from "data missing"). |
| **WV-03** | web hero "+25 Points" badge inside box (desktop 1280) | ✅ **CONFIRMED** | `web-desktop-home-hero-art.png` = "+25 Points à l'inscription" badge fully inside the yellow box, comfortable right margin, "à l'inscription" no longer clipped. No new overlap with hero SVG (badge sits in empty space below the burger). |
| **WV-05** | web menu grid footers bottom-align across columns | ⚠️ **STILL-BROKEN (partial, P2)** | `web-desktop-menu-full.png` / `web-tablet-menu-grid.png`. `margin-top:auto` pins each footer to its own card's bottom, BUT cards are NOT equal-height across a row, so footers stay ragged when row-mate descriptions wrap to different line counts (DOM probe: row-1 card heights 332/**350**/332/332px; Big Cayenne footer sits ~19px lower than its 3 row-mates → footBottom 760 vs 741; visible in PNG. tablet 2-col: 2-line Sandwich Cayenne footer well above 3-line Big Cayenne footer). Footers align only when descriptions happen to wrap to the same line count. NOT a NEW defect and NOT regressed — the pre-existing round-1 P2 persists; the fix did not achieve the stated "bottom-align across columns" for unequal-desc rows. **Mechanism left to the implementer** (DOM probe showed grid `align-items` already resolves to `normal`/stretch yet cards don't equalize — likely a wrapper between `.lc-menu-grid` and `.lc-card-item`, or stretch not propagating to the flex card; a confident CSS prescription would be unverified, so none is given). |
| **WV-06** | web cart drawer line-item thumb = real photo | ✅ **CONFIRMED** | `web-desktop-cart-drawer.png` = "Sandwich Cayenne" row shows real sandwich thumb, no chili. DOM: `.lc-cart-row-thumb img src=sandwich-cayenne.png natW=800`. |
| **WV-07** | web checkout/payment récap thumb = real photo | ✅ **CONFIRMED** | `web-desktop-funnel-summary.png` (RÉCAP) = "Sandwich Cayenne" line with real sandwich thumb, emoji span display:none. DOM: `.lcf-summary-row-thumb img src=sandwich-cayenne.png natW=800`. (Driven through "Passer commande" → funnel; first capture missed the funnel, re-driven correctly.) |
| **WV-09** | web product card photos framed (contain), full food, no top-crop | ✅ **CONFIRMED** | `web-desktop-home-cards-grid.png` = Sandwich Cayenne / Big Cayenne / Galette all contain+6%, full food shown, no top-crop, consistent framing on gradient. No ugly letterbox (cutouts are transparent PNG on gradient). |
| **MV-01** | mobile home featured "Sandwich Cayenne" = full photo (not half-cropped) | ✅ **CONFIRMED** (letterbox checked, acceptable) | `mobile-home-featured.png` = full sandwich end-to-end, no longer centre-slice. DOM: contain, fillFrac 0.51, letterboxPx 108 in the 150×220 box — the predicted contain-letterbox EXISTS, but the box background is light cream (#F2F0EB), so the bands blend in and read as a deliberate framed product shot, NOT black voids / floating image. Acceptable; not a new defect. |
| **MV-02** | mobile cart "Pour accompagner" upsell thumbs = full (not squished) | ✅ **CONFIRMED** | `mobile-cart-upsell.png` = Glace Ben&Jerry's / Tarte Daim / Tiramisu shown whole + undistorted. DOM: ben-jerrys/tarte/tiramisu (800×800) now `fit=contain` in 114×80 boxes; contain pillarbox blends on cream tile. |
| **MV-04** | mobile loyalty/profile "347 PTS" black-card circles read orange/yellow (not muddy brown) | ✅ **CONFIRMED** (improved; minor caveat) | `mobile-profile.png` / `mobile-loyalty.png`. DOM: large circle `rgb(255,90,31)`@0.42 (orange), small `rgb(255,217,61)`@0.30 (yellow) — vs round-1 @0.18. Small circle clearly amber/orange now; large circle reads warm burnt-orange (still dark-leaning because orange@0.42 over #0A0A0A ink is inherently dark, but no longer the olive/muddy-brown of @0.18). Not garish. Fix achieves its intent. |
| **MV-05** | mobile loyalty/profile reward glyph = 🎁 (not tofu) | ✅ **CONFIRMED** | `mobile-profile.png` "−5 € sur ta commande 🎁" + `mobile-loyalty.png` 3 reward tiers each show 🎁. DOM: `hasGift:true hasBanknote:false giftCount:3`. No tofu box. |
| **MV-06** | mobile wizard disabled "Suivant" = no orange glow | ✅ **CONFIRMED** | `mobile-wizard-step1-disabled.png` = flat grey disabled "Suivant 7,50 €" pill, no halo. DOM: `.rdw-cta:disabled boxShadow:none disabled:true`. |

---

## NEW-DEFECT HUNT (fixes' side-effects)

- **contain letterbox/pillarbox:** checked on every cover→contain switch. MV-01 hero has the largest letterbox (108px / fillFrac 0.51) but on a LIGHT card background it reads clean, not as a void. MV-02 desserts pillarbox on cream tile — clean. Web detail boards / featured (contain+7%) and cards (contain+6%) sit on gradient cutouts — no visible letterbox. **No new P0/P1 introduced.**
- **Console errors:** instrumented `pageerror` + `console.error` across every web (home/menu/detail/cart/funnel) and mobile (home/cart/profile/loyalty/wizard) flow → **0 errors** in every flow's metric block.
- **HTTP 4xx / 404:** instrumented `response.status()>=400` → **0** across all flows (web `http4xx:[]`, mobile `http4:[]`). No broken images.
- **Layout/overflow:** no new overflow or broken layout observed in any captured surface.

---

## SUMMARY

- **11 of 12 fixes CONFIRMED rendered correctly** (WV-01, WV-02, WV-03, WV-06, WV-07, WV-09, MV-01, MV-02, MV-04, MV-05, MV-06).
- **WV-05 = STILL-BROKEN (partial, P2)** — `margin-top:auto` bottom-pins each footer within its card but does not equalize card heights across a row, so footers stay ragged when row-mate descriptions wrap to different line counts (proven: row-1 cards 332/350/332/332px, footBottom 760 vs 741). This is the SAME pre-existing P2 from round-1 — not regressed, not a new defect — but the fix does not achieve the stated "footers bottom-align across columns" for unequal-desc rows. Mechanism left to the implementer (the obvious `align-items:stretch` prescription is NOT verified — the probe showed it already resolves to normal/stretch yet cards don't equalize).
- **NEW P0/P1: NONE.** No console errors, no 404s, no new layout breakage. The contain switches did not introduce ugly letterboxing on any surface (transparent-cutout PNGs on gradient + light card backgrounds absorb the bands; checked the worst cases: MV-01 hero fillFrac 0.51 / web bowl board fillFrac 0.72 / web Coca / MV-02 desserts).

**Out of scope, correctly untouched (owner-classified):** WV-04 hero SVG cartoon, MV-03 source-image recrop, MV-08 red logout, gallery/category emoji (P3), Orangina→tropico. Also note round-1 **MV-13 (P1, QUANTITÉ stepper occlusion on tall recaps)** was NOT part of this fix batch and remains open — outside round-2 scope but flagged for continuity.

**Overall:** GREEN with one honest caveat (WV-05 partial, P2). Recommend either re-grading WV-05 as still-open P2 or completing it with equal-height cards.
