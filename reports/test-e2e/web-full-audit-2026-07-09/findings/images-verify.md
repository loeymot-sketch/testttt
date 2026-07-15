# VERIFY — dimension: images (web standalone) 2026-07-09

## 1. hero-1400kb-eager-lcp — CONFIRMED P2
- screens.jsx:123 : <img src="assets/menu/signature/cayenne-hero.png" alt="Sandwich Cayenne signature — Le Cayenne" className="lc-hero-art-svg" objectFit:'contain' onError=hide/> — eager (no loading=lazy), no srcset, no webp <picture>.
- curl http://127.0.0.1:8096/assets/menu/signature/cayenne-hero.png -> type=image/png size=1456875 http=200
- disk: assets/menu/signature/cayenne-hero.png = 1,456,875 bytes. It is the .lc-hero-art of the landing screen (above the fold => LCP).
- (context) sibling signature PNGs also huge: mega-hero 1.64MB, terminator-hero 1.82MB, supreme-hero 1.35MB.

## 2. dead-hero-field-missing-backend-imgs — CONFIRMED P3
- data/menu.js:292 mkItem sets `hero: heroFor(slug)`. heroFor (line 115) = ASSET_BASE + HERO_IMG[slug].
- ASSET_BASE (line 49-55) resolves to meta menu-image-base => backend http://127.0.0.1:8766/images/menu/ .
- HERO_IMG (line 105-111): cayenne/mega/terminator/galette-cayenne -> signature/cayenne-hero.png ; tacos-m/tacos-l -> signature/tacos-hero.png.
- Backend both MISSING: curl :8766/images/menu/signature/cayenne-hero.png -> text/html; charset=UTF-8 ; tacos-hero.png -> text/html (SPA 404 fallback).
- No consumer: grep -rn '\.hero' *.jsx *.js (excluding lc-hero css / HERO_IMG / heroFor) -> ZERO. Field never rendered => latent dead wiring only.

## 3. empty-alt-product-imgs — CONFIRMED P3
- grep alt="" -> upsell.jsx:91, flows.jsx:73, funnel.jsx:109, wizard-v2.jsx:488, wizard-v2.jsx:517 = 5 matches, all product/option thumbnails adjacent to visible text labels.
- Product cards/detail correctly use alt={item.name}: screens.jsx:40, screens-v3.jsx:166.
- WCAG-defensible as decorative (adjacent labels) => minimal nit, correctly flagged P3.

## 4. missing-lazy-offscreen-imgs — CONFIRMED P3
- grep 'loading="lazy"' *.jsx -> ONLY screens.jsx:40 and upsell.jsx:91.
- Absent (off-screen at first paint): screens-v3.jsx:166 (detail modal art), funnel.jsx:109 + flows.jsx:73 (cart rows), wizard-v2.jsx:488/517 (option thumbs). Cited "167" is off-by-one; img tag spans 166-168.
