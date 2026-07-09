# V3 DEPTH AUDIT — MOBILE RN (mobile/) — GREEN_HELD

Target: mobile/ standalone React-CDN prototype (Le Cayenne). CODE-ONLY.
Posture: attempted to REFUTE "mobile is good". Could not break it.

## Claim 1 — data/menu.js = DB mirror, 0 invented product : HELD
- menu.js declares 31 products / 9 cats (comment MENU-CANON accurate: 4+2+6+2+2+2+3+8+2=31).
- Cross-checked vs LIVE DB foodking_e2e (Item::withoutGlobalScopes, non-deleted).
- DB "live" items = status=5; status=10 = archived/hidden. EVERY st5 product exists in menu.js
  with matching price; every menu.js product exists in DB. st10 items (Big Cayenne, Sandwich
  Classique, Big Classique, Big Chicken, Big Tacos, Bowl Poulet variants) correctly EXCLUDED.
- Frites Cheddar variants: DB stores as separate SKUs (Petite Cheddar fondu 3.50=2.50+1.00 etc);
  menu.js models them as frites-style composer options — same offering, same prices, NOT invented.
- Prices spot-verified: Cayenne 7.40, Suprême 7.00, Méga 8.00, Terminator 9.00, Tacos M 6.90 /
  L 7.90, Bols 7.90, Capri-Sun 1.50, Eau 1.00, Menu Enfant 4.90, Formule 2.50/2.00/2.00. All match.
- 0 hallucinated product (no "Box Familiale"/"Nashville"/"Solo").

## Claim 2 — Palette NOIR/ORANGE/JAUNE/BLANC, no #F4501E : HELD
- grep #F4501E across all .jsx/.js/.css/.html = 0 occurrences.
- Dominant palette: #fff/#ffffff (blanc), #0a0a0a/#000/#1a1a1a/#111 (noir),
  #ffd93d (jaune), #ff5a1f (orange). Green (#22c55e etc) = success states only.
- Terracotta #D97757 (Anthropic) only in tweaks-panel.jsx COMMENTED-OUT code; #C96442 only in
  design-canvas.jsx (design-tool focus ring/chrome) — NOT product UI.
- NEAR-MISS (noted, not a violation): styles.css `--red:#E5341A` / redesigns `--orange-deep:#E5341A`
  used as the deep endpoint of an orange gradient in onboarding progress bar. It is red-orange,
  not #F4501E, and functions as gradient depth. Attested as borderline, below P3.

## Claim 3 — NO-API-WIREUP V1 : HELD
- grep fetch/axios/XMLHttpRequest/api : the only fetch() calls load LOCAL state files in the
  design tooling (design-canvas.jsx DC_STATE_FILE, image-slot.js STATE_FILE); "ordersApi" is a
  prop name, not a client.
- Every "/api/v1/frontend/..." string is a Phase-6 planning COMMENT (data/orders.js, data/user.js,
  data/loyalty.js, wallet-spec.js, WizardRedeem.jsx, useLoyaltyQR.js). No active backend call.
- Runtime state = localStorage only (api/storage.js, NS 'lecayenne.'). No bearer/token/endpoint
  hardcoded in executable code.

## Claim 4 — Structure/nav/FR, no drift, no XSS : HELD
- FR consistent; no English UI drift (the "Cart" grep hit = aria-label "…au panier").
- No dangerouslySetInnerHTML in product screens; only image-slot.js innerHTML with static
  design-tool template (no user input) — tooling, not product.
- Owner recipe rules honored: Cayenne viandes:0 + sauce_locked fromagère; all Burgers viandes:0;
  Méga/Terminator viandes:2; Tacos M viandes:1 / L viandes:2.

## Attacks run (commands)
- grep -rniE '#F4501E' → 0
- grep -rhoiE hex colors freq → palette matches mandate
- grep -rniE 'fetch\(|axios|XMLHttpRequest|/api/' → all comments/local
- grep dangerouslySetInnerHTML|innerHTML|eval|document.write → tooling only
- php artisan tinker Item::withoutGlobalScopes()->... → 59 rows, diffed vs menu.js 31

## Verdict: GREEN_HELD — 0 P0/P1/P2/P3. Mobile RN standalone is sound.
