# IMAGES dimension — Le Cayenne web standalone (/Users/1millnonstop/Downloads/web)
Date 2026-07-09. Backend :8766 up, site :8096 up. meta menu-image-base = http://127.0.0.1:8766/images/menu/

## Method note (important)
Backend does NOT return 404 for a missing image. A missing path under /images/menu/
returns **HTTP 200 with Content-Type text/html** (the Laravel SPA shell, 15551 bytes).
Proof: `curl -w '%{content_type}' .../images/menu/NOPE.png` -> `text/html; charset=UTF-8`.
So "missing" is detected by Content-Type != image/*, NOT by status code. In the browser
this HTML-as-image fails to decode -> `<img>.onerror` fires -> emoji/hide fallback shows.

## 1. Wiring (PASS)
data/menu.js:49-55  ASSET_BASE = meta[name=menu-image-base].content (trailing-slash normalized),
  default http://127.0.0.1:8766/images/menu/. Confirmed on live :8096.
data/menu.js:114  imgFor(slug) = ASSET_BASE + (ITEM_IMG[slug] || 'item-default.svg')
Product tile img = item.image (built from imgFor). 
Backend sweep of all 79 referenced filenames (images-backend-sweep.txt):
  - 77 return image/* (image/png / image/svg+xml)  -> OK
  - 2 return text/html (missing): signature/cayenne-hero.png, signature/tacos-hero.png
item-default.svg -> image/svg+xml OK.

## 2. onError fallback (PASS — robust)
EVERY <img> has onError:
  screens.jsx:40   product card  -> hide img, reveal emoji sibling (loading=lazy)
  screens.jsx:123  homepage hero -> hide on error
  screens.jsx:215  featured Mega -> hide + reveal sibling
  screens-v3.jsx:167 detail modal-> hide + reveal emoji
  funnel.jsx:109   cart summary  -> hide + reveal emoji
  flows.jsx:73     cart row      -> hide + reveal emoji
  upsell.jsx:91    upsell        -> hide + reveal emoji (loading=lazy)
  wizard-v2.jsx:488,517 option thumb -> hide + set parent icon text
Given the 200-text/html behavior above, this fallback is what prevents broken-image icons.

## 3. object-fit (PASS)
No object-fit in any CSS; all inline.
  contain: product cards, detail, featured, cart rows, upsell, hero (no squash) — correct.
  cover:   wizard option thumbs (wizard-v2.jsx:488,517) — acceptable crop on small square thumb.

## 4. alt text (minor)
Good: screens.jsx:40 alt={item.name}; screens-v3.jsx:167 alt={item.name};
      screens.jsx:123 alt="Sandwich Cayenne signature — Le Cayenne"; screens.jsx:211 alt="Méga".
Empty alt="" (decorative, but adjacent text label present): funnel.jsx:109, flows.jsx:73,
      upsell.jsx:91, wizard-v2.jsx:488, wizard-v2.jsx:517. WCAG-defensible (labels adjacent). P3.

## 5. Local vs backend (PASS — no missing local png)
Only local image ref in ALL code = screens.jsx:123 "assets/menu/signature/cayenne-hero.png".
  File exists (1,456,875 bytes), git-tracked, NOT in .vercelignore/.gitignore -> deploys.
  :8096 serves it HTTP 200 image/png size 1456875. CONFIRMED present.
Favicon + apple-touch-icon = inline data:image/svg+xml (index.html:7-8) -> no local file / no 404.
No CSS url() refs to local images. 256 files under assets/menu git-tracked.
=> No code path needs a local product png that is missing. No P1.

## 6. Perf / prod
loading=lazy present: screens.jsx:40 (cards), upsell.jsx:91. 
  MISSING lazy: screens-v3.jsx:167 (detail modal), funnel.jsx:109, flows.jsx:73,
  wizard-v2.jsx:488/517. Hero (screens.jsx:123) eager = correct (above-fold LCP).
Large image rendered: homepage hero cayenne-hero.png = 1.4 MB PNG, eager, above-fold (LCP),
  local, no webp/responsive srcset. Other heroes mega 1.6MB / terminator 1.8MB / supreme 1.3MB
  exist locally but are referenced ONLY by the dead .hero field (never rendered).
Mixed-content: image base is http://127.0.0.1:8766; on https Vercel prod this is blocked
  (CSP img-src = 'self' data: blob: https:  — no plain http except self). BUT explicitly
  documented as a required deploy step in VERCEL_DEPLOY.md:14-16,42-43 (swap meta to https
  api.lecayenne.fr). By-design/documented, not a code defect.

## Dead-data finding (latent)
data/menu.js:291  every item gets hero: heroFor(slug).
data/menu.js:115  heroFor -> for slugs in HERO_IMG (cayenne, mega, terminator, galette-cayenne,
  tacos-m, tacos-l) returns ASSET_BASE + 'signature/*.png' = backend path that returns text/html
  (missing on backend, confirmed). The .hero item field is NEVER consumed by any render code
  (broad grep: no it.hero / item.hero / .hero consumer). => dead data, no visible impact today.
  Risk: if someone later wires it.hero into a template, 6 hero slugs would show broken/hidden.
